<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Configuration validation.
 *
 * Errors carry a JSON Pointer to the exact node that is wrong, because the
 * primary author of this document is an agent that needs to correct its own
 * output without a human translating "something is wrong with your options
 * list" into a location.
 *
 * The secret-leak check is the most important rule here. The document is meant
 * to live in a git repository, so a value that belongs in the encrypted
 * credential store must never be accepted into it -- rejecting the save is the
 * only reliable moment to prevent that.
 */
final class Fsync_Config_Validate
{
    const SEVERITY_ERROR = 'error';
    const SEVERITY_WARNING = 'warning';

    /**
     * Keys whose value must be a credential id, never a credential.
     */
    const CREDENTIAL_KEYS = array('credential');

    /**
     * Key names that must never appear in the document at all, because their
     * presence means someone pasted a value where an id belongs.
     */
    const FORBIDDEN_KEYS = array(
        'private_key',
        'client_secret',
        'client_email',
        'refresh_token',
        'access_token',
        'password',
        'passwd',
        'secret',
        'api_key',
        'apikey',
        'webhook_url',
        'token',
    );

    /**
     * Validate a document.
     *
     * @param array $document
     * @param array $context Optional: 'introspect' => array from Fsync_Introspect,
     *                       'credentials' => array<int, string> of known ids
     * @return array{ok: bool, errors: array, warnings: array}
     */
    public static function check(array $document, array $context = array())
    {
        $issues = array();

        self::check_secrets($document, '', $issues);
        self::check_structure($document, $issues);
        self::check_scope($document, $context, $issues);
        self::check_environments($document, $issues);
        self::check_storage_and_schedules($document, $issues);
        self::check_credentials($document, $context, $issues);

        $errors = array_values(
            array_filter(
                $issues,
                static function ($issue) {
                    return $issue['severity'] === self::SEVERITY_ERROR;
                }
            )
        );

        $warnings = array_values(
            array_filter(
                $issues,
                static function ($issue) {
                    return $issue['severity'] === self::SEVERITY_WARNING;
                }
            )
        );

        return array(
            'ok' => $errors === array(),
            'errors' => $errors,
            'warnings' => $warnings,
        );
    }

    /**
     * Refuse anything that looks like a credential value.
     *
     * @param mixed $node
     * @param string $pointer
     * @param array $issues
     * @return void
     */
    private static function check_secrets($node, $pointer, array &$issues)
    {
        if (! is_array($node)) {
            if (is_string($node)) {
                self::check_secret_value($node, $pointer, $issues);
            }

            return;
        }

        foreach ($node as $key => $value) {
            $child = $pointer . '/' . self::escape_pointer((string) $key);

            if (in_array(strtolower((string) $key), self::FORBIDDEN_KEYS, true)) {
                $issues[] = self::issue(
                    self::SEVERITY_ERROR,
                    'secret_in_config',
                    $child,
                    sprintf(
                        '「%s」は設定ファイルに書けません。値は管理画面で登録し、ここには credential のIDだけを書いてください。',
                        (string) $key
                    )
                );

                continue;
            }

            if (in_array((string) $key, self::CREDENTIAL_KEYS, true)) {
                if (! is_string($value) || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/i', (string) $value)) {
                    $issues[] = self::issue(
                        self::SEVERITY_ERROR,
                        'credential_not_id',
                        $child,
                        'credential には値ではなく、登録済みの認証情報IDを指定してください。'
                    );
                }

                continue;
            }

            self::check_secrets($value, $child, $issues);
        }
    }

    /**
     * @param string $value
     * @param string $pointer
     * @param array $issues
     * @return void
     */
    private static function check_secret_value($value, $pointer, array &$issues)
    {
        if ($value === '') {
            return;
        }

        if (strpos($value, '-----BEGIN') !== false) {
            $issues[] = self::issue(
                self::SEVERITY_ERROR,
                'secret_in_config',
                $pointer,
                '秘密鍵が含まれています。設定ファイルに秘密情報を書かないでください。'
            );

            return;
        }

        if (strpos($value, '"type": "service_account"') !== false || strpos($value, '"private_key_id"') !== false) {
            $issues[] = self::issue(
                self::SEVERITY_ERROR,
                'secret_in_config',
                $pointer,
                'サービスアカウントJSONが含まれています。管理画面から登録してください。'
            );

            return;
        }

        if (preg_match('#^https://hooks\.slack\.com/services/\S+#i', $value) === 1) {
            $issues[] = self::issue(
                self::SEVERITY_ERROR,
                'secret_in_config',
                $pointer,
                'Slack Webhook URL が含まれています。管理画面から登録し、IDで参照してください。'
            );

            return;
        }

        // Long opaque blobs. Hex is exempt because hashes and fingerprints are
        // legitimately present and are not secrets.
        if (
            preg_match('/^[A-Za-z0-9+\/=_-]{40,}$/', $value) === 1
            && preg_match('/^[0-9a-f]+$/i', $value) !== 1
        ) {
            $issues[] = self::issue(
                self::SEVERITY_ERROR,
                'secret_in_config',
                $pointer,
                '認証情報らしき文字列が含まれています。値ではなく credential のIDを指定してください。'
            );
        }
    }

    /**
     * @param array $document
     * @param array $issues
     * @return void
     */
    private static function check_structure(array $document, array &$issues)
    {
        if (! isset($document['config_version'])) {
            $issues[] = self::issue(
                self::SEVERITY_ERROR,
                'missing_config_version',
                '/config_version',
                'config_version は必須です。'
            );
        } elseif ((int) $document['config_version'] !== 1) {
            $issues[] = self::issue(
                self::SEVERITY_ERROR,
                'unsupported_config_version',
                '/config_version',
                sprintf('未対応の config_version です: %s', (string) $document['config_version'])
            );
        }

        foreach (
            array(
                '/sync' => 'sync',
                '/environments' => 'environments',
            ) as $pointer => $key
        ) {
            if (isset($document[$key]) && ! is_array($document[$key])) {
                $issues[] = self::issue(
                    self::SEVERITY_ERROR,
                    'expected_object',
                    $pointer,
                    sprintf('%s はオブジェクトである必要があります。', $key)
                );
            }
        }

        $conflict = $document['sync']['policy']['conflict'] ?? 'manual';
        if (! in_array($conflict, array('manual', 'push_wins', 'pull_wins', 'newest_wins'), true)) {
            $issues[] = self::issue(
                self::SEVERITY_ERROR,
                'invalid_conflict_policy',
                '/sync/policy/conflict',
                'conflict は manual / push_wins / pull_wins / newest_wins のいずれかです。'
            );
        }

        if (! empty($document['sync']['policy']['allow_delete'])) {
            $issues[] = self::issue(
                self::SEVERITY_WARNING,
                'delete_enabled',
                '/sync/policy/allow_delete',
                '削除が有効になっています。受信側のコンテンツが削除される可能性があります。'
            );
        }
    }

    /**
     * @param array $document
     * @param array $context
     * @param array $issues
     * @return void
     */
    private static function check_scope(array $document, array $context, array &$issues)
    {
        $allow = (array) ($document['sync']['scope']['options']['allow'] ?? array());

        foreach ($allow as $index => $name) {
            $pointer = '/sync/scope/options/allow/' . (int) $index;

            if (Fsync_Config::is_protected_option((string) $name)) {
                $issues[] = self::issue(
                    self::SEVERITY_ERROR,
                    'protected_option',
                    $pointer,
                    sprintf('「%s」は同期できないオプションです。', (string) $name)
                );

                continue;
            }

            if (Fsync_Config::is_warned_option((string) $name)) {
                $issues[] = self::issue(
                    self::SEVERITY_WARNING,
                    'sensitive_option',
                    $pointer,
                    sprintf('「%s」は環境ごとに異なることが多いオプションです。意図した設定か確認してください。', (string) $name)
                );
            }
        }

        // Options are allowlist-only by design; a deny list here means the
        // author expected denylist semantics and would get far more than they
        // intended.
        if (isset($document['sync']['scope']['options']['deny'])) {
            $issues[] = self::issue(
                self::SEVERITY_ERROR,
                'options_deny_unsupported',
                '/sync/scope/options/deny',
                'オプションは許可リスト方式のみです。deny は使用できません。allow に列挙してください。'
            );
        }

        $known_types = (array) ($context['introspect']['post_types'] ?? array());
        $known_taxonomies = (array) ($context['introspect']['taxonomies'] ?? array());

        foreach ((array) ($document['sync']['scope']['post_types'] ?? array()) as $type => $settings) {
            $pointer = '/sync/scope/post_types/' . self::escape_pointer((string) $type);

            if ($known_types !== array() && ! isset($known_types[$type])) {
                $issues[] = self::issue(
                    self::SEVERITY_WARNING,
                    'unknown_post_type',
                    $pointer,
                    sprintf('投稿タイプ「%s」はこのサイトに登録されていません。', (string) $type)
                );
            }

            if (! is_array($settings)) {
                $issues[] = self::issue(
                    self::SEVERITY_ERROR,
                    'expected_object',
                    $pointer,
                    '投稿タイプの設定はオブジェクトである必要があります。'
                );

                continue;
            }

            $mode = $settings['meta']['mode'] ?? 'all';
            if (! in_array($mode, array('all', 'allow', 'none'), true)) {
                $issues[] = self::issue(
                    self::SEVERITY_ERROR,
                    'invalid_meta_mode',
                    $pointer . '/meta/mode',
                    'meta.mode は all / allow / none のいずれかです。'
                );
            }
        }

        foreach ((array) ($document['sync']['scope']['taxonomies'] ?? array()) as $taxonomy => $settings) {
            if ($known_taxonomies !== array() && ! isset($known_taxonomies[$taxonomy])) {
                $issues[] = self::issue(
                    self::SEVERITY_WARNING,
                    'unknown_taxonomy',
                    '/sync/scope/taxonomies/' . self::escape_pointer((string) $taxonomy),
                    sprintf('タクソノミー「%s」はこのサイトに登録されていません。', (string) $taxonomy)
                );
            }
        }

        foreach ((array) ($document['sync']['scope']['tables'] ?? array()) as $index => $table) {
            $name = is_array($table) ? (string) ($table['name'] ?? '') : (string) $table;

            if (in_array($name, Fsync_Config::PROTECTED_TABLES, true)) {
                $issues[] = self::issue(
                    self::SEVERITY_ERROR,
                    'protected_table',
                    '/sync/scope/tables/' . (int) $index,
                    sprintf('テーブル「%s」は同期できません。', $name)
                );
            }
        }

        $core = $document['sync']['scope']['files']['core'] ?? 'checksum-only';
        if (! in_array($core, array(false, 'checksum-only', 'sync'), true)) {
            $issues[] = self::issue(
                self::SEVERITY_ERROR,
                'invalid_core_mode',
                '/sync/scope/files/core',
                'files.core は false / "checksum-only" / "sync" のいずれかです。'
            );
        }

        if ($core === 'sync') {
            $issues[] = self::issue(
                self::SEVERITY_WARNING,
                'core_sync_enabled',
                '/sync/scope/files/core',
                'WPコアファイルの転送が有効です。バージョンが一致する環境間でのみ実行されます。'
            );
        }
    }

    /**
     * @param array $document
     * @param array $issues
     * @return void
     */
    private static function check_environments(array $document, array &$issues)
    {
        $environments = (array) ($document['environments'] ?? array());
        $names = array_keys($environments);

        foreach ($environments as $name => $environment) {
            $pointer = '/environments/' . self::escape_pointer((string) $name);

            $valid_name = Fsync_Peer::normalize_env_name($name);
            if (is_wp_error($valid_name)) {
                $issues[] = self::issue(
                    self::SEVERITY_ERROR,
                    'invalid_env_name',
                    $pointer,
                    $valid_name->get_error_message()
                );
            }

            if (! is_array($environment)) {
                $issues[] = self::issue(
                    self::SEVERITY_ERROR,
                    'expected_object',
                    $pointer,
                    '環境の設定はオブジェクトである必要があります。'
                );

                continue;
            }

            $role = (string) ($environment['role'] ?? '');

            // The source environment is where releases are authored; it has no
            // URL because nothing connects to it.
            if ($role !== 'source') {
                $url = (string) ($environment['url'] ?? '');

                if ($url === '') {
                    $issues[] = self::issue(
                        self::SEVERITY_ERROR,
                        'missing_env_url',
                        $pointer . '/url',
                        'url は必須です（role が source の環境を除く）。'
                    );
                } elseif (strpos($url, 'https://') !== 0 && ! Fsync_Pairing::is_local_url($url)) {
                    $issues[] = self::issue(
                        self::SEVERITY_ERROR,
                        'insecure_env_url',
                        $pointer . '/url',
                        '接続先URLはHTTPSである必要があります。'
                    );
                }

                if (empty($environment['credential'])) {
                    $issues[] = self::issue(
                        self::SEVERITY_ERROR,
                        'missing_env_credential',
                        $pointer . '/credential',
                        'credential は必須です。ペアリング後に自動で設定されます。'
                    );
                }
            }

            foreach (array('promotes_to', 'requires_verified_on') as $key) {
                foreach ((array) ($environment[$key] ?? array()) as $index => $target) {
                    if (! in_array((string) $target, $names, true)) {
                        $issues[] = self::issue(
                            self::SEVERITY_ERROR,
                            'unknown_environment_reference',
                            $pointer . '/' . $key . '/' . (int) $index,
                            sprintf('環境「%s」が environments に定義されていません。', (string) $target)
                        );
                    } elseif ((string) $target === (string) $name) {
                        $issues[] = self::issue(
                            self::SEVERITY_ERROR,
                            'self_referential_environment',
                            $pointer . '/' . $key . '/' . (int) $index,
                            '環境が自分自身を参照しています。'
                        );
                    }
                }
            }

            foreach ((array) ($environment['transport'] ?? array()) as $index => $transport) {
                if (! in_array((string) $transport, array('https', 'ssh'), true)) {
                    $issues[] = self::issue(
                        self::SEVERITY_ERROR,
                        'invalid_transport',
                        $pointer . '/transport/' . (int) $index,
                        'transport は https または ssh です。'
                    );
                }
            }
        }

        // A promotion gate that nothing can satisfy silently blocks every
        // release, so an environment requiring verification from an
        // environment that never promotes to it is reported here.
        foreach ($environments as $name => $environment) {
            foreach ((array) ($environment['requires_verified_on'] ?? array()) as $index => $gate) {
                $promotes = (array) ($environments[$gate]['promotes_to'] ?? array());

                if (! in_array((string) $name, $promotes, true)) {
                    $issues[] = self::issue(
                        self::SEVERITY_WARNING,
                        'promotion_path_incomplete',
                        '/environments/' . self::escape_pointer((string) $name)
                            . '/requires_verified_on/' . (int) $index,
                        sprintf(
                            '「%s」は「%s」での検証を要求していますが、「%s」の promotes_to に「%s」が含まれていません。',
                            (string) $name,
                            (string) $gate,
                            (string) $gate,
                            (string) $name
                        )
                    );
                }
            }
        }
    }

    /**
     * @param array $document
     * @param array $issues
     * @return void
     */
    private static function check_storage_and_schedules(array $document, array &$issues)
    {
        $storage = (array) ($document['storage'] ?? array());
        $environments = array_keys((array) ($document['environments'] ?? array()));
        $notifiers = array_keys((array) ($document['notify'] ?? array()));

        foreach ($storage as $name => $target) {
            $pointer = '/storage/' . self::escape_pointer((string) $name);
            $type = (string) ($target['type'] ?? '');

            if (! in_array($type, array('local', 'gcs', 'gdrive'), true)) {
                $issues[] = self::issue(
                    self::SEVERITY_ERROR,
                    'invalid_storage_type',
                    $pointer . '/type',
                    'storage.type は local / gcs / gdrive のいずれかです。'
                );

                continue;
            }

            if ($type === 'gcs' && empty($target['bucket'])) {
                $issues[] = self::issue(
                    self::SEVERITY_ERROR,
                    'missing_bucket',
                    $pointer . '/bucket',
                    'GCS には bucket が必要です。'
                );
            }

            // A service account has no personal Drive storage quota, so an
            // upload aimed at anything but a shared drive fails with an opaque
            // storageQuotaExceeded at runtime.
            if ($type === 'gdrive' && empty($target['shared_drive_id']) && empty($target['oauth'])) {
                $issues[] = self::issue(
                    self::SEVERITY_ERROR,
                    'missing_shared_drive',
                    $pointer . '/shared_drive_id',
                    'Google Drive には共有ドライブIDが必要です。サービスアカウントには個人ドライブの容量がありません。'
                );
            }

            if ($type !== 'local' && empty($target['credential'])) {
                $issues[] = self::issue(
                    self::SEVERITY_ERROR,
                    'missing_storage_credential',
                    $pointer . '/credential',
                    'クラウドストレージには credential が必要です。'
                );
            }
        }

        $destinations = array_merge(array('local'), array_keys($storage));

        foreach ((array) ($document['backup']['destinations'] ?? array()) as $index => $destination) {
            if (! in_array((string) $destination, $destinations, true)) {
                $issues[] = self::issue(
                    self::SEVERITY_ERROR,
                    'unknown_destination',
                    '/backup/destinations/' . (int) $index,
                    sprintf('保存先「%s」が storage に定義されていません。', (string) $destination)
                );
            }
        }

        foreach ((array) ($document['schedules'] ?? array()) as $index => $schedule) {
            $pointer = '/schedules/' . (int) $index;

            $job = (string) ($schedule['job'] ?? '');
            $valid_jobs = array('backup', 'backup_cloud', 'sync_push', 'sync_pull', 'drift_check', 'verify', 'gc');

            if (! in_array($job, $valid_jobs, true)) {
                $issues[] = self::issue(
                    self::SEVERITY_ERROR,
                    'invalid_job',
                    $pointer . '/job',
                    sprintf('job は次のいずれかです: %s', implode(' / ', $valid_jobs))
                );
            }

            if (in_array($job, array('sync_push', 'sync_pull', 'drift_check'), true)) {
                $env = (string) ($schedule['env'] ?? '');

                if ($env === '' || ! in_array($env, $environments, true)) {
                    $issues[] = self::issue(
                        self::SEVERITY_ERROR,
                        'unknown_schedule_environment',
                        $pointer . '/env',
                        sprintf('環境「%s」が environments に定義されていません。', $env)
                    );
                }
            }

            // An unattended job that can write is the one place where an
            // incorrect configuration acts on production without a human ever
            // seeing a dry run.
            if ($job === 'sync_push' && ! empty($schedule['auto_apply'])) {
                $issues[] = self::issue(
                    self::SEVERITY_WARNING,
                    'unattended_apply',
                    $pointer . '/auto_apply',
                    'スケジュールでの自動適用が有効です。競合が発生した場合は停止しますが、内容を確認せずに本番へ反映されます。'
                );
            }

            foreach ((array) ($schedule['notify'] ?? array()) as $n_index => $notifier) {
                if (! in_array((string) $notifier, $notifiers, true)) {
                    $issues[] = self::issue(
                        self::SEVERITY_ERROR,
                        'unknown_notifier',
                        $pointer . '/notify/' . (int) $n_index,
                        sprintf('通知先「%s」が notify に定義されていません。', (string) $notifier)
                    );
                }
            }
        }
    }

    /**
     * @param array $document
     * @param array $context
     * @param array $issues
     * @return void
     */
    private static function check_credentials(array $document, array $context, array &$issues)
    {
        $referenced = Fsync_Config::credential_references($document);
        if ($referenced === array()) {
            return;
        }

        // The caller may supply the known ids, which keeps this function pure
        // and lets it run before the tables exist.
        $missing = isset($context['credentials'])
            ? array_values(array_diff($referenced, (array) $context['credentials']))
            : Fsync_Credentials::missing($referenced);

        foreach ($missing as $credential_id) {
            $issues[] = self::issue(
                self::SEVERITY_WARNING,
                'credential_not_set',
                '/',
                sprintf('認証情報「%s」がまだ登録されていません。管理画面から登録してください。', $credential_id)
            );
        }
    }

    /**
     * @param string $severity
     * @param string $code
     * @param string $pointer
     * @param string $message
     * @return array
     */
    private static function issue($severity, $code, $pointer, $message)
    {
        return array(
            'severity' => $severity,
            'code' => $code,
            'pointer' => $pointer === '' ? '/' : $pointer,
            'message' => $message,
        );
    }

    /**
     * Escape a key for use in a JSON Pointer (RFC 6901).
     *
     * @param string $key
     * @return string
     */
    public static function escape_pointer($key)
    {
        return str_replace(array('~', '/'), array('~0', '~1'), $key);
    }
}
