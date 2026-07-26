<?php

if (! defined('ABSPATH')) {
    exit;
}

/** Immutable releases, dry-run plans, conflict resolutions and receipts. */
final class Fsync_Release
{
    const STATUS_BUILT = 'built';
    const STATUS_AWAITING_OBJECTS = 'awaiting_objects';
    const STATUS_DRY_RUN_READY = 'dry_run_ready';
    const STATUS_APPLYING = 'applying';
    const STATUS_APPLIED = 'applied';
    const STATUS_VERIFIED = 'verified';
    const STATUS_FAILED = 'failed';
    const STATUS_ROLLED_BACK = 'rolled_back';
    const STATUS_CANCELLED = 'cancelled';

    /** Build a source release without mutating a peer. */
    public static function create($peer_id, $profile = 'full', $direction = 'push', $idempotency_key = '')
    {
        global $wpdb;

        if ((string) $direction !== 'push') {
            return new WP_Error('fsync_direction_invalid', 'pullはFsync_Job::create_pull()から開始してください。');
        }

        $peer = Fsync_Peer::find((string) $peer_id);
        if ($peer === null) {
            return new WP_Error('fsync_peer_missing', '接続先が見つかりません。');
        }
        $idempotency_hash = '';
        if ((string) $idempotency_key !== '') {
            if (! Fsync_Utils::is_public_id((string) $idempotency_key)) {
                return new WP_Error('fsync_idempotency_key_required', 'リリース作成には32桁hexのidempotency_keyが必要です。');
            }
            $idempotency_hash = hash('sha256', (string) $idempotency_key);
            $existing_id = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT release_id FROM ' . Fsync_Schema::table('releases') . ' WHERE peer_id = %s AND direction = %s AND idempotency_hash = %s ORDER BY created_at DESC LIMIT 1',
                    $peer['peer_id'],
                    (string) $direction,
                    $idempotency_hash
                )
            );
            if (is_string($existing_id) && $existing_id !== '') {
                $existing = self::get($existing_id);
                if (is_wp_error($existing)) {
                    return $existing;
                }
                if ((string) ($existing['summary']['profile'] ?? '') !== (string) $profile) {
                    return new WP_Error('fsync_idempotency_conflict', '同じidempotency_keyが異なるprofileに使用されています。', array('status' => 409));
                }

                return array('release' => $existing, 'manifest' => Fsync_Manifest::get($existing['manifest_id']), 'idempotent' => true);
            }
        }
        $manifest = Fsync_Manifest::build($profile, $peer['env_name']);
        if (is_wp_error($manifest)) {
            return $manifest;
        }
        $release_id = Fsync_Utils::random_hex(16);
        if (is_wp_error($release_id)) {
            return $release_id;
        }
        $config_hash = Fsync_Utils::canonical_hash(Fsync_Config::document());
        if (is_wp_error($config_hash)) {
            return $config_hash;
        }
        $source_environment = self::environment_summary();
        $allowed_options = (array) (Fsync_Config::scope($peer['env_name'])['options']['allow'] ?? array());
        $source_environment['warned_options'] = array_values(array_intersect($allowed_options, Fsync_Config::WARN_OPTIONS));
        $source_environment['promotion_proofs'] = self::promotion_proofs($peer['env_name'], $manifest['root_hash'], $profile);
        $effective_scope = Fsync_Portable::effective_scope(Fsync_Config::scope($peer['env_name']), $profile);
        if ($profile === 'full' && in_array(($effective_scope['files']['core'] ?? false), array('checksum-only', 'sync'), true)) {
            $source_environment['core_checksums'] = Fsync_Env::core_checksums();
        }
        $summary = array(
            'profile' => $profile,
            'source_env' => Fsync_Config_Io::active_env(),
            'target_env' => $peer['env_name'],
            'source' => $source_environment,
            'manifest_root' => $manifest['root_hash'],
            'counts' => array('total' => $manifest['item_count']),
        );
        $saved = self::insert_release(
            array(
                'release_id' => $release_id,
                'peer_id' => $peer['peer_id'],
                'direction' => $direction,
                'status' => self::STATUS_BUILT,
                'manifest_id' => $manifest['manifest_id'],
                'scope_fingerprint' => $manifest['scope_fingerprint'],
                'config_hash' => $config_hash,
                'idempotency_hash' => $idempotency_hash,
                'summary' => $summary,
            )
        );
        if (is_wp_error($saved)) {
            return $saved;
        }

        Fsync_Log::info('release_created', '移行リリースを作成しました。', array('peer_id' => $peer_id, 'data' => array('release_id' => $release_id)));

        return array('release' => self::get($release_id), 'manifest' => $manifest);
    }

    /**
     * Create the target-side dry-run from a peer manifest.
     *
     * @param string $release_id
     * @param array $source_manifest
     * @param array $source_environment
     * @param string $peer_id
     * @return array|WP_Error
     */
    public static function prepare($release_id, array $source_manifest, array $source_environment, $peer_id)
    {
        global $wpdb;

        if (! Fsync_Utils::is_public_id($release_id)) {
            return new WP_Error('fsync_release_id_invalid', 'リリースIDが不正です。');
        }
        $existing = self::get($release_id);
        if (! is_wp_error($existing)) {
            return self::prepare_response($existing, $source_manifest);
        }

        $peer = Fsync_Peer::find((string) $peer_id);
        if ($peer === null) {
            return new WP_Error('fsync_peer_missing', '送信元ピアが見つかりません。');
        }
        $expected_scope = Fsync_Config::scope_fingerprint($peer['env_name']);
        if (is_wp_error($expected_scope)
            || ! hash_equals((string) $expected_scope, (string) ($source_manifest['scope_fingerprint'] ?? ''))) {
            return new WP_Error('fsync_scope_mismatch', '移行スコープの指紋が一致しません。', array('status' => 409));
        }
        if (is_multisite()) {
            return new WP_Error('fsync_multisite_unsupported', 'WordPress Multisiteは対応していません。');
        }
        $source_wp = (string) ($source_environment['wp_version'] ?? '');
        if ($source_wp !== '' && $source_wp !== (string) get_bloginfo('version')) {
            return new WP_Error('fsync_wp_version_mismatch', 'WordPress本体のバージョンが一致しません。', array('status' => 409));
        }

        $accepted = Fsync_Manifest::accept($source_manifest, $peer_id);
        if (is_wp_error($accepted)) {
            return $accepted;
        }
        $profile = (string) ($source_manifest['profile'] ?? 'full');
        $target_manifest = Fsync_Manifest::build($profile, $peer['env_name']);
        if (is_wp_error($target_manifest)) {
            return $target_manifest;
        }
        $receipt = self::latest_receipt($peer_id, $expected_scope);
        $base = $receipt === null ? array() : (array) ($receipt['data']['baseline'] ?? array());
        $policy = (array) (Fsync_Config::document()['sync']['policy'] ?? array());
        $aligned = self::align_target_items(
            (array) $source_manifest['items'],
            (array) $target_manifest['items']
        );
        if (is_wp_error($aligned)) {
            return $aligned;
        }
        $diff = Fsync_Diff::compare(
            (array) $source_manifest['items'],
            $aligned['items'],
            $base,
            false
        );
        $diff = self::apply_delete_policy(
            $diff,
            $aligned['items'],
            Fsync_Config::scope($peer['env_name']),
            ! empty($policy['allow_delete'])
        );
        $plan_hash = self::plan_hash($release_id, $source_manifest, $target_manifest, $diff['items']);
        if (is_wp_error($plan_hash)) {
            return $plan_hash;
        }
        $config_hash = Fsync_Utils::canonical_hash(Fsync_Config::document());
        if (is_wp_error($config_hash)) {
            return $config_hash;
        }
        $required = self::required_objects_from_items($source_manifest['items'], $diff['items']);
        $missing = Fsync_Store::missing($required);
        $summary = array(
            'profile' => $profile,
            'source_env' => (string) ($source_environment['env_name'] ?? $peer['env_name']),
            'target_env' => Fsync_Config_Io::active_env(),
            'source' => $source_environment,
            'target' => self::environment_summary(),
            'source_manifest_id' => $source_manifest['manifest_id'],
            'target_manifest_id' => $target_manifest['manifest_id'],
            'source_manifest_root' => $source_manifest['root_hash'],
            'target_manifest_root' => $target_manifest['root_hash'],
            'counts' => $diff['counts'],
            'required_objects' => $required,
            'missing_objects' => $missing,
            'preflight' => self::preflight(
                $source_manifest,
                $target_manifest,
                $diff,
                $source_environment,
                $profile,
                Fsync_Portable::effective_scope(Fsync_Config::scope($peer['env_name']), $profile)
            ),
        );
        $status = $missing === array() ? self::STATUS_DRY_RUN_READY : self::STATUS_AWAITING_OBJECTS;
        $saved = self::insert_release(
            array(
                'release_id' => $release_id,
                'peer_id' => $peer_id,
                'direction' => 'push',
                'status' => $status,
                'manifest_id' => $source_manifest['manifest_id'],
                'base_receipt_id' => $receipt === null ? '' : $receipt['receipt_id'],
                'scope_fingerprint' => $expected_scope,
                'config_hash' => $config_hash,
                'plan_hash' => $plan_hash,
                'summary' => $summary,
            )
        );
        if (is_wp_error($saved)) {
            return $saved;
        }

        foreach ($diff['items'] as $item) {
            $inserted = $wpdb->insert(
                Fsync_Schema::table('release_items'),
                array(
                    'release_id' => $release_id,
                    'item_key' => $item['key'],
                    'target_item_key' => (string) ($aligned['target_keys'][$item['key']] ?? $item['key']),
                    'entity_kind' => $item['kind'],
                    'entity_uid' => $item['uid'],
                    'action' => $item['action'],
                    'source_hash' => $item['source_hash'],
                    'target_hash' => $item['target_hash'],
                    'base_hash' => $item['base_hash'],
                    'payload_hash' => $item['payload_hash'],
                    'has_relationships' => ! empty($source_manifest['items'][$item['key']]['has_relationships']) ? 1 : 0,
                    'resolution' => '',
                    'status' => 'pending',
                    'error' => '',
                )
            );
            if ($inserted === false) {
                return new WP_Error('fsync_release_items_failed', '差分項目を保存できません。');
            }
        }

        return self::prepare_response(self::get($release_id), $source_manifest);
    }

    /** Verify all objects and issue a one-time exact-plan confirmation. */
    public static function finalize_dry_run($release_id)
    {
        global $wpdb;

        $release = self::get($release_id);
        if (is_wp_error($release)) {
            return $release;
        }
        if (in_array($release['status'], array(self::STATUS_APPLYING, self::STATUS_APPLIED, self::STATUS_VERIFIED), true)) {
            return new WP_Error('fsync_release_already_applied', 'このリリースは既に適用中または適用済みです。', array('status' => 409));
        }
        $missing = Fsync_Store::missing((array) ($release['summary']['required_objects'] ?? array()));
        if ($missing !== array()) {
            self::update_summary($release_id, array('missing_objects' => $missing), self::STATUS_AWAITING_OBJECTS);

            return new WP_Error('fsync_release_objects_missing', '必要なオブジェクトが揃っていません。', array('missing' => $missing, 'status' => 409));
        }

        $payloads = self::validate_source_payloads($release);
        if (is_wp_error($payloads)) {
            return $payloads;
        }

        $blockers = array_values((array) ($release['summary']['preflight']['blockers'] ?? array()));
        if ($blockers !== array()) {
            return new WP_Error('fsync_dry_run_blocked', 'ドライランに解消されていない阻害要因があります。', array('blockers' => $blockers, 'status' => 409));
        }
        $code_health = self::code_health($release);
        if (is_wp_error($code_health)) {
            return $code_health;
        }
        if ($code_health !== array()) {
            $preflight = (array) ($release['summary']['preflight'] ?? array());
            $preflight['blockers'][] = array(
                'code' => 'php_syntax_invalid',
                'message' => sprintf('移行するPHPファイルに構文エラーが%d件あります。', count($code_health)),
                'files' => $code_health,
            );
            $preflight['ok'] = false;
            self::update_summary($release_id, array('preflight' => $preflight), $release['status']);

            return new WP_Error('fsync_code_health_failed', '移行するPHPファイルの構文検査に失敗しました。', array('files' => $code_health, 'status' => 409));
        }
        $confirmation = Fsync_Utils::random_hex(24);
        if (is_wp_error($confirmation)) {
            return $confirmation;
        }
        $wpdb->update(
            Fsync_Schema::table('releases'),
            array(
                'status' => self::STATUS_DRY_RUN_READY,
                'confirmation_hash' => hash('sha256', $confirmation),
                'updated_at' => Fsync_Utils::now(),
            ),
            array('release_id' => $release_id)
        );

        $release = self::get($release_id);
        $release['confirmation'] = $confirmation;

        return $release;
    }

    /** Apply explicit conflict decisions and bind them into a new plan hash. */
    public static function resolve($release_id, $plan_hash, array $resolutions)
    {
        global $wpdb;

        $release = self::get($release_id);
        if (is_wp_error($release)) {
            return $release;
        }
        if (! hash_equals($release['plan_hash'], (string) $plan_hash)) {
            $current = self::items_by_key($release_id);
            $already_applied = $resolutions !== array();
            foreach ($resolutions as $key => $resolution) {
                if (! isset($current[$key]) || (string) $current[$key]['resolution'] !== (string) $resolution) {
                    $already_applied = false;
                    break;
                }
            }
            if ($already_applied) {
                return self::finalize_dry_run($release_id);
            }

            return new WP_Error('fsync_plan_changed', '差分計画が更新されています。ドライランを確認し直してください。', array('status' => 409));
        }
        foreach ($resolutions as $key => $resolution) {
            if (! in_array($resolution, array('source', 'target', 'skip'), true)) {
                return new WP_Error('fsync_resolution_invalid', sprintf('競合解決方法が不正です: %s', $key));
            }
            $wpdb->update(
                Fsync_Schema::table('release_items'),
                array('resolution' => $resolution),
                array('release_id' => $release_id, 'item_key' => (string) $key, 'action' => Fsync_Diff::ACTION_CONFLICT)
            );
        }
        $items = self::items($release_id);
        $new_hash = Fsync_Utils::canonical_hash(
            array(
                'release_id' => $release_id,
                'scope_fingerprint' => $release['scope_fingerprint'],
                'items' => array_map(static function ($item) {
                    return array(
                        'key' => $item['item_key'],
                        'action' => $item['action'],
                        'source_hash' => $item['source_hash'],
                        'target_hash' => $item['target_hash'],
                        'base_hash' => $item['base_hash'],
                        'resolution' => $item['resolution'],
                    );
                }, $items),
            )
        );
        if (is_wp_error($new_hash)) {
            return $new_hash;
        }
        $wpdb->update(
            Fsync_Schema::table('releases'),
            array('plan_hash' => $new_hash, 'confirmation_hash' => '', 'updated_at' => Fsync_Utils::now()),
            array('release_id' => $release_id)
        );

        return self::finalize_dry_run($release_id);
    }

    public static function verify_confirmation(array $release, $plan_hash, $confirmation)
    {
        if (! hash_equals((string) $release['plan_hash'], (string) $plan_hash)) {
            return new WP_Error('fsync_plan_changed', '差分計画が更新されています。', array('status' => 409));
        }
        if ($release['confirmation_hash'] === ''
            || ! hash_equals($release['confirmation_hash'], hash('sha256', (string) $confirmation))) {
            return new WP_Error('fsync_confirmation_invalid', '適用確認が一致しません。ドライランをやり直してください。', array('status' => 403));
        }
        foreach (self::items($release['release_id']) as $item) {
            if ($item['action'] === Fsync_Diff::ACTION_CONFLICT && $item['resolution'] === '') {
                return new WP_Error('fsync_conflicts_unresolved', '未解決の競合があります。', array('status' => 409));
            }
            if ($item['action'] === Fsync_Diff::ACTION_DELETE && $item['resolution'] !== 'source') {
                return new WP_Error('fsync_deletes_unconfirmed', '削除対象が明示確認されていません。', array('status' => 409));
            }
        }

        return true;
    }

    /** Confirm all delete rows separately; this changes the bound plan. */
    public static function confirm_deletes($release_id, $plan_hash)
    {
        global $wpdb;
        $release = self::get($release_id);
        if (is_wp_error($release)) {
            return $release;
        }
        if (! hash_equals($release['plan_hash'], (string) $plan_hash)) {
            $pending = array_filter(self::items($release_id), static function ($item) {
                return $item['action'] === Fsync_Diff::ACTION_DELETE && $item['resolution'] !== 'source';
            });
            if ($pending === array()) {
                return self::finalize_dry_run($release_id);
            }

            return new WP_Error('fsync_plan_changed', '差分計画が更新されています。', array('status' => 409));
        }
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Fsync_Schema::table('release_items') . " SET resolution = 'source' WHERE release_id = %s AND action = 'delete'",
                $release_id
            )
        );

        return self::resolve($release_id, $plan_hash, array());
    }

    public static function get($release_id)
    {
        global $wpdb;
        if (! Fsync_Utils::is_public_id($release_id)) {
            return new WP_Error('fsync_release_id_invalid', 'リリースIDが不正です。');
        }
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . Fsync_Schema::table('releases') . ' WHERE release_id = %s', $release_id),
            ARRAY_A
        );
        if ($row === null) {
            return new WP_Error('fsync_release_missing', 'リリースが見つかりません。', array('status' => 404));
        }

        return self::shape($row);
    }

    public static function all($limit = 50)
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . Fsync_Schema::table('releases') . ' ORDER BY created_at DESC LIMIT %d', max(1, min(200, (int) $limit))),
            ARRAY_A
        );

        return array_map([self::class, 'shape'], (array) $rows);
    }

    public static function items($release_id)
    {
        global $wpdb;

        return (array) $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . Fsync_Schema::table('release_items') . ' WHERE release_id = %s ORDER BY item_key ASC', $release_id),
            ARRAY_A
        );
    }

    public static function set_status($release_id, $status, array $summary_patch = array())
    {
        $release = self::get($release_id);
        if (is_wp_error($release)) {
            return $release;
        }

        return self::update_summary($release_id, $summary_patch, $status);
    }

    /** Persist a verified baseline after a successful apply. */
    public static function receipt(array $release, array $verified_manifest)
    {
        global $wpdb;
        $receipt_id = Fsync_Utils::random_hex(16);
        if (is_wp_error($receipt_id)) {
            return $receipt_id;
        }
        // A receipt tracks only content owned by this source. Copying the
        // entire target manifest here would turn unrelated target-only rows
        // into deletion candidates on the next run. A target/skip conflict
        // records the source hash as the common base so the preserved target
        // divergence does not reappear as a source update next time.
        $baseline = array();
        foreach (self::items($release['release_id']) as $item) {
            $key = (string) $item['item_key'];
            if ($item['action'] === Fsync_Diff::ACTION_DELETE) {
                continue;
            }
            if ($item['action'] === Fsync_Diff::ACTION_BLOCKED && (string) $item['base_hash'] !== '') {
                $baseline[$key] = (string) $item['base_hash'];
                continue;
            }
            if ((string) $item['source_hash'] !== '') {
                $baseline[$key] = (string) $item['source_hash'];
            }
        }
        $data = array(
            'baseline' => $baseline,
            'counts' => $release['summary']['counts'] ?? array(),
            'snapshot_id' => $release['summary']['snapshot_id'] ?? '',
        );
        $encoded = Fsync_Utils::encode($data);
        if (is_wp_error($encoded)) {
            return $encoded;
        }
        $saved = $wpdb->insert(
            Fsync_Schema::table('receipts'),
            array(
                'receipt_id' => $receipt_id,
                'release_id' => $release['release_id'],
                'peer_id' => $release['peer_id'],
                'source_env' => (string) ($release['summary']['source_env'] ?? ''),
                'target_env' => (string) ($release['summary']['target_env'] ?? ''),
                'manifest_root' => (string) ($verified_manifest['root_hash'] ?? ''),
                'plan_hash' => $release['plan_hash'],
                'status' => 'verified',
                'data' => $encoded,
                'applied_at' => Fsync_Utils::now(),
            )
        );
        if ($saved === false) {
            return new WP_Error('fsync_receipt_save_failed', '受領証を保存できません。');
        }

        return array('receipt_id' => $receipt_id, 'data' => $data);
    }

    public static function latest_receipt($peer_id, $scope_fingerprint = '')
    {
        global $wpdb;
        $receipts = Fsync_Schema::table('receipts');
        $releases = Fsync_Schema::table('releases');
        if ((string) $scope_fingerprint !== '') {
            $sql = $wpdb->prepare(
                "SELECT rc.* FROM {$receipts} rc INNER JOIN {$releases} rl ON rl.release_id = rc.release_id WHERE rc.peer_id = %s AND rc.status = 'verified' AND rl.scope_fingerprint = %s ORDER BY rc.applied_at DESC LIMIT 1",
                $peer_id,
                (string) $scope_fingerprint
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$receipts} WHERE peer_id = %s AND status = 'verified' ORDER BY applied_at DESC LIMIT 1",
                $peer_id
            );
        }
        $row = $wpdb->get_row($sql, ARRAY_A);
        if ($row === null) {
            return null;
        }
        $data = json_decode((string) $row['data'], true);
        $row['data'] = is_array($data) ? $data : array();

        return $row;
    }

    /** Persist the signed peer result used as a later promotion gate proof. */
    public static function record_remote_verification($release_id, $environment, array $result)
    {
        $release = self::get((string) $release_id);
        if (is_wp_error($release) || (string) ($result['status'] ?? '') !== self::STATUS_VERIFIED) {
            return $release;
        }
        $proof = array(
            'environment' => (string) $environment,
            'status' => self::STATUS_VERIFIED,
            'receipt_id' => (string) ($result['receipt_id'] ?? ''),
            'snapshot_id' => (string) ($result['snapshot_id'] ?? ''),
            'manifest_root' => (string) ($result['manifest_root'] ?? ''),
            'source_manifest_root' => (string) ($result['source_manifest_root'] ?? ''),
            'verified_at' => Fsync_Utils::now_iso(),
        );

        return self::update_summary($release['release_id'], array('remote_verification' => $proof), $release['status']);
    }

    private static function insert_release(array $args)
    {
        global $wpdb;
        $encoded = Fsync_Utils::encode((array) ($args['summary'] ?? array()));
        if (is_wp_error($encoded)) {
            return $encoded;
        }
        $now = Fsync_Utils::now();
        $row = array(
            'release_id' => $args['release_id'],
            'peer_id' => (string) ($args['peer_id'] ?? ''),
            'direction' => (string) ($args['direction'] ?? 'push'),
            'status' => (string) ($args['status'] ?? self::STATUS_BUILT),
            'manifest_id' => (string) ($args['manifest_id'] ?? ''),
            'base_receipt_id' => (string) ($args['base_receipt_id'] ?? ''),
            'scope_fingerprint' => (string) ($args['scope_fingerprint'] ?? ''),
            'config_hash' => (string) ($args['config_hash'] ?? ''),
            'plan_hash' => (string) ($args['plan_hash'] ?? ''),
            'confirmation_hash' => '',
            'idempotency_hash' => (string) ($args['idempotency_hash'] ?? ''),
            'summary' => $encoded,
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        );
        if ($wpdb->insert(Fsync_Schema::table('releases'), $row) === false) {
            return new WP_Error('fsync_release_save_failed', 'リリースを保存できません。');
        }

        return true;
    }

    private static function update_summary($release_id, array $patch, $status)
    {
        global $wpdb;
        $release = self::get($release_id);
        if (is_wp_error($release)) {
            return $release;
        }
        $summary = array_merge($release['summary'], $patch);
        $encoded = Fsync_Utils::encode($summary);
        if (is_wp_error($encoded)) {
            return $encoded;
        }
        $updated = $wpdb->update(
            Fsync_Schema::table('releases'),
            array('status' => $status, 'summary' => $encoded, 'updated_at' => Fsync_Utils::now()),
            array('release_id' => $release_id)
        );

        return $updated === false ? new WP_Error('fsync_release_update_failed', 'リリースを更新できません。') : self::get($release_id);
    }

    private static function prepare_response(array $release, array $manifest)
    {
        $required = self::required_objects_from_items((array) ($manifest['items'] ?? array()), self::items_by_key($release['release_id']));
        $missing = Fsync_Store::missing($required);

        return array(
            'release' => $release,
            'missing_objects' => $missing,
            'suggested_chunk_bytes' => Fsync_Env::suggested_chunk_bytes(),
        );
    }

    private static function items_by_key($release_id)
    {
        $out = array();
        foreach (self::items($release_id) as $item) {
            $out[$item['item_key']] = $item;
        }

        return $out;
    }

    private static function required_objects_from_items(array $source_items, array $diff_items)
    {
        $required = array();
        // Every payload is authenticated and scope-validated before a plan can
        // be confirmed, including rows that currently compare unchanged.
        foreach ($source_items as $item) {
            $payload_hash = (string) ($item['payload_hash'] ?? '');
            if (Fsync_Utils::is_sha256($payload_hash)) {
                $required[] = $payload_hash;
            }
        }
        foreach ($diff_items as $key => $diff) {
            $action = (string) ($diff['action'] ?? '');
            if (! in_array($action, array(Fsync_Diff::ACTION_CREATE, Fsync_Diff::ACTION_UPDATE, Fsync_Diff::ACTION_CONFLICT), true)) {
                continue;
            }
            foreach ((array) ($source_items[$key]['objects'] ?? array()) as $hash) {
                if (Fsync_Utils::is_sha256($hash)) {
                    $required[] = $hash;
                }
            }
        }

        return array_values(array_unique($required));
    }

    /** Authenticate portable payloads and enforce the receiving scope. */
    private static function validate_source_payloads(array $release)
    {
        $manifest = Fsync_Manifest::get((string) $release['manifest_id']);
        if (is_wp_error($manifest)) {
            return $manifest;
        }
        $peer = Fsync_Peer::find((string) $release['peer_id']);
        if ($peer === null) {
            return new WP_Error('fsync_peer_missing', '送信元ピアが見つかりません。');
        }
        $profile = (string) ($manifest['profile'] ?? 'content');
        $scope = Fsync_Portable::effective_scope(Fsync_Config::scope((string) $peer['env_name']), $profile);
        $records = array();
        $available = array();
        foreach ((array) ($manifest['items'] ?? array()) as $key => $item) {
            $raw = Fsync_Store::get((string) ($item['payload_hash'] ?? ''));
            if (is_wp_error($raw)) {
                return $raw;
            }
            if (! hash_equals((string) $item['payload_hash'], hash('sha256', $raw))) {
                return new WP_Error('fsync_payload_hash_mismatch', sprintf('payloadのハッシュが一致しません: %s', $key));
            }
            $record = Fsync_Utils::decode($raw);
            if (is_wp_error($record) || ! is_array($record)) {
                return new WP_Error('fsync_payload_invalid', sprintf('payload JSONが不正です: %s', $key));
            }
            $allowed_keys = array('format_version', 'kind', 'uid', 'data', 'objects');
            $unknown = array_diff(array_keys($record), $allowed_keys);
            $record_key = Fsync_Portable::key($record);
            $record_hash = Fsync_Utils::canonical_hash($record);
            $record_objects = (array) ($record['objects'] ?? array());
            $manifest_objects = (array) ($item['objects'] ?? array());
            $expected_objects = array_values(array_unique(array_merge(array((string) $item['payload_hash']), $record_objects)));
            sort($expected_objects, SORT_STRING);
            sort($manifest_objects, SORT_STRING);
            if ($unknown !== array()
                || (int) ($record['format_version'] ?? 0) !== Fsync_Portable::FORMAT_VERSION
                || ! is_array($record['data'] ?? null)
                || ! Fsync_Utils::is_list($record_objects)
                || count($record_objects) !== count(array_unique($record_objects))
                || (string) $key !== $record_key
                || (string) ($item['kind'] ?? '') !== (string) ($record['kind'] ?? '')
                || (string) ($item['uid'] ?? '') !== (string) ($record['uid'] ?? '')
                || is_wp_error($record_hash)
                || ! hash_equals((string) ($item['hash'] ?? ''), (string) $record_hash)
                || ! hash_equals((string) ($item['identity_key'] ?? ''), Fsync_Portable::identity_key($record))
                || (bool) ($item['has_relationships'] ?? false) !== Fsync_Portable::has_relationships($record)
                || $expected_objects !== $manifest_objects) {
                return new WP_Error('fsync_payload_manifest_mismatch', sprintf('payloadとmanifestの内容が一致しません: %s', $key), array('status' => 409));
            }
            foreach ($record_objects as $object_hash) {
                if (! Fsync_Utils::is_sha256($object_hash)) {
                    return new WP_Error('fsync_payload_object_invalid', sprintf('payloadのobject IDが不正です: %s', $key));
                }
            }
            $allowed = self::record_allowed_by_scope($record, $scope, $profile);
            if (is_wp_error($allowed)) {
                return $allowed;
            }
            $records[$key] = $record;
            $available[(string) $record['kind']][(string) $record['uid']] = true;
        }
        foreach ($records as $key => $record) {
            foreach (self::record_references($record) as $reference) {
                $kind = (string) ($reference['kind'] ?? '');
                $uid = (string) ($reference['uid'] ?? '');
                if ($uid === '' || isset($available[$kind][$uid]) || Fsync_Identity::local_id($kind, $uid) > 0) {
                    continue;
                }

                return new WP_Error(
                    'fsync_reference_out_of_scope',
                    sprintf('参照先が移行scopeに含まれず、移行先にも存在しません: %s -> %s:%s', $key, $kind, $uid),
                    array('status' => 409, 'item_key' => $key, 'reference' => $reference)
                );
            }
        }

        return true;
    }

    private static function record_references(array $record)
    {
        $kind = (string) ($record['kind'] ?? '');
        $data = (array) ($record['data'] ?? array());
        $references = array();
        if ($kind === 'post') {
            if ((string) ($data['parent_uid'] ?? '') !== '') {
                $references[] = array('kind' => 'post', 'uid' => (string) $data['parent_uid']);
            }
            foreach ((array) ($data['taxonomies'] ?? array()) as $uids) {
                foreach ((array) $uids as $uid) {
                    $references[] = array('kind' => 'term', 'uid' => (string) $uid);
                }
            }
            if (preg_match_all('/\{\{FSYNC_REF:post:([a-f0-9-]{36})\}\}/', (string) ($data['content'] ?? ''), $matches)) {
                foreach (array_unique($matches[1]) as $uid) {
                    $references[] = array('kind' => 'post', 'uid' => (string) $uid);
                }
            }
        } elseif ($kind === 'term' && (string) ($data['parent_uid'] ?? '') !== '') {
            $references[] = array('kind' => 'term', 'uid' => (string) $data['parent_uid']);
        } elseif ($kind === 'comment') {
            $references[] = array('kind' => 'post', 'uid' => (string) ($data['post_uid'] ?? ''));
            if ((string) ($data['parent_uid'] ?? '') !== '') {
                $references[] = array('kind' => 'comment', 'uid' => (string) $data['parent_uid']);
            }
        }
        self::collect_reference_nodes($data, $references);

        return $references;
    }

    private static function collect_reference_nodes($node, array &$references)
    {
        if (! is_array($node)) {
            return;
        }
        if (isset($node['fsync_ref'], $node['uids'])) {
            foreach ((array) $node['uids'] as $uid) {
                if ($uid !== null && (string) $uid !== '') {
                    $references[] = array('kind' => (string) $node['fsync_ref'], 'uid' => (string) $uid);
                }
            }

            return;
        }
        foreach ($node as $child) {
            self::collect_reference_nodes($child, $references);
        }
    }

    /** A signed peer may send only records included by the local target scope. */
    private static function record_allowed_by_scope(array $record, array $scope, $profile)
    {
        $kind = (string) ($record['kind'] ?? '');
        $data = (array) ($record['data'] ?? array());
        $allowed = false;
        if ($kind === 'post') {
            $rules = (array) ($scope['post_types'][(string) ($data['post_type'] ?? '')] ?? array());
            $allowed = $rules !== array() && in_array((string) ($data['status'] ?? ''), (array) ($rules['statuses'] ?? array()), true);
            foreach (array_keys((array) ($data['taxonomies'] ?? array())) as $taxonomy) {
                if (! in_array((string) $taxonomy, (array) ($rules['taxonomies'] ?? array()), true)) {
                    $allowed = false;
                    break;
                }
            }
        } elseif ($kind === 'term') {
            $allowed = isset($scope['taxonomies'][(string) ($data['taxonomy'] ?? '')]);
        } elseif ($kind === 'comment') {
            $allowed = ! empty($scope['comments']);
        } elseif ($kind === 'user') {
            $allowed = $profile === 'full' && ! empty($scope['users']['enabled']);
            if (isset($data['password_hash']) && empty($scope['users']['passwords'])) {
                $allowed = false;
            }
        } elseif ($kind === 'option') {
            $name = (string) ($data['name'] ?? '');
            $allowed = $name !== '' && ! Fsync_Config::is_protected_option($name) && self::option_allowed($name, (array) ($scope['options']['allow'] ?? array()));
        } elseif ($kind === 'table') {
            foreach ((array) ($scope['tables'] ?? array()) as $table) {
                if ((string) ($table['name'] ?? '') === (string) ($data['table'] ?? '')
                    && Fsync_Utils::canonicalize((array) $table) === Fsync_Utils::canonicalize((array) ($data['config'] ?? array()))) {
                    $allowed = $profile === 'full';
                    break;
                }
            }
        } elseif ($kind === 'file') {
            $allowed = $profile === 'full' || (string) ($data['category'] ?? '') === 'uploads';
            $allowed = $allowed && self::file_allowed((string) ($data['category'] ?? ''), (string) ($data['path'] ?? ''), (array) ($scope['files'] ?? array()));
            $content_hash = (string) ($data['content_hash'] ?? '');
            $allowed = $allowed && Fsync_Utils::is_sha256($content_hash) && in_array($content_hash, (array) ($record['objects'] ?? array()), true);
        } elseif ($kind === 'runtime') {
            $files = (array) ($scope['files'] ?? array());
            $plugin_rule = $files['plugins'] ?? false;
            $expected_plugin_mode = $plugin_rule === true ? 'all' : (is_array($plugin_rule) && $plugin_rule !== array() ? 'selected' : 'none');
            $selected_plugins = $expected_plugin_mode === 'selected' ? array_values(array_unique(array_map('strval', $plugin_rule))) : array();
            sort($selected_plugins, SORT_STRING);
            $manifest_selected = array_values((array) ($data['managed_plugins'] ?? array()));
            sort($manifest_selected, SORT_STRING);
            $theme_rule = $files['theme'] ?? false;
            $expected_theme_mode = $theme_rule === true ? 'all' : (is_array($theme_rule) && $theme_rule !== array() ? 'selected' : 'none');
            $selected_themes = $expected_theme_mode === 'selected' ? array_values(array_unique(array_map('strval', $theme_rule))) : array();
            sort($selected_themes, SORT_STRING);
            $manifest_themes = array_values((array) ($data['managed_themes'] ?? array()));
            sort($manifest_themes, SORT_STRING);
            $allowed = $profile === 'full'
                && (string) ($data['wp_version'] ?? '') === (string) get_bloginfo('version')
                && (string) ($data['plugins_mode'] ?? '') === $expected_plugin_mode
                && $manifest_selected === $selected_plugins
                && (string) ($data['theme_mode'] ?? '') === $expected_theme_mode
                && $manifest_themes === $selected_themes;
            foreach ((array) ($data['active_plugins'] ?? array()) as $plugin) {
                $safe_plugin = Fsync_Utils::normalize_relative_path((string) $plugin);
                $slug = is_wp_error($safe_plugin) ? '' : (dirname($safe_plugin) === '.' ? $safe_plugin : dirname($safe_plugin));
                if (is_wp_error($safe_plugin) || (string) $plugin === plugin_basename(FSYNC_FILE)
                    || ($expected_plugin_mode === 'none')
                    || ($expected_plugin_mode === 'selected' && ! in_array($slug, $selected_plugins, true))) {
                    $allowed = false;
                    break;
                }
            }
            $stylesheet = (string) ($data['stylesheet'] ?? '');
            $template = (string) ($data['template'] ?? '');
            foreach (array($stylesheet, $template) as $theme) {
                if ($theme === '') {
                    continue;
                }
                $safe_theme = Fsync_Utils::normalize_relative_path($theme);
                if (is_wp_error($safe_theme) || strpos($safe_theme, '/') !== false
                    || $expected_theme_mode === 'none'
                    || ($expected_theme_mode === 'selected' && ! in_array($safe_theme, $selected_themes, true))) {
                    $allowed = false;
                }
            }
        }
        if (! $allowed) {
            return new WP_Error('fsync_payload_out_of_scope', sprintf('許可された移行scope外の項目を拒否しました: %s', Fsync_Portable::key($record)), array('status' => 403));
        }

        return true;
    }

    private static function option_allowed($name, array $patterns)
    {
        foreach ($patterns as $pattern) {
            $pattern = (string) $pattern;
            if ($pattern === $name
                || (strlen($pattern) > 2 && $pattern[0] === '/' && substr($pattern, -1) === '/' && @preg_match($pattern, $name) === 1)) {
                return true;
            }
        }

        return false;
    }

    private static function file_allowed($category, $path, array $rules)
    {
        $safe = Fsync_Utils::normalize_relative_path($path);
        if (is_wp_error($safe) || $safe !== $path) {
            return false;
        }
        if ($category === 'uploads') {
            return ! empty($rules['uploads']);
        }
        if (strpos($category, 'theme/') === 0) {
            $slug = substr($category, 6);
            return $rules['theme'] === true || in_array($slug, (array) ($rules['theme'] ?? array()), true);
        }
        if ($category === 'plugins') {
            $slug = strpos($path, '/') === false ? $path : strstr($path, '/', true);
            return $rules['plugins'] === true || in_array($slug, (array) ($rules['plugins'] ?? array()), true);
        }
        if ($category === 'mu-plugins') {
            return ! empty($rules['mu_plugins']) && $path !== 'fsync-guard.php';
        }
        if ($category === 'core') {
            return ($rules['core'] ?? false) === 'sync';
        }

        return false;
    }

    /**
     * Map an independently-created target row to the source key by a unique
     * natural identity. Ambiguity is a hard stop: guessing here can overwrite
     * or snapshot the wrong row.
     *
     * @return array|WP_Error
     */
    private static function align_target_items(array $source_items, array $target_items)
    {
        $source_index = array();
        $target_index = array();
        foreach ($source_items as $key => $item) {
            $identity = (string) ($item['identity_key'] ?? '');
            if ($identity !== '') {
                $source_index[$identity][] = (string) $key;
            }
        }
        foreach ($target_items as $key => $item) {
            $identity = (string) ($item['identity_key'] ?? '');
            if ($identity !== '') {
                $target_index[$identity][] = (string) $key;
            }
        }
        foreach (array_merge(array_keys($source_index), array_keys($target_index)) as $identity) {
            if (count((array) ($source_index[$identity] ?? array())) > 1
                || count((array) ($target_index[$identity] ?? array())) > 1) {
                return new WP_Error('fsync_identity_ambiguous', '同じ自然キーを持つ複数の移行項目があり、安全に対応付けできません。', array('identity_key' => $identity, 'status' => 409));
            }
        }

        $aligned = $target_items;
        $target_keys = array();
        foreach ($source_items as $source_key => $source_item) {
            $target_keys[$source_key] = $source_key;
            if (isset($target_items[$source_key])) {
                continue;
            }
            $identity = (string) ($source_item['identity_key'] ?? '');
            $candidate = (string) (($target_index[$identity][0] ?? ''));
            if ($identity === '' || $candidate === '' || ! isset($target_items[$candidate])) {
                continue;
            }
            $aligned[$source_key] = $target_items[$candidate];
            unset($aligned[$candidate]);
            $target_keys[$source_key] = $candidate;
        }

        return array('items' => $aligned, 'target_keys' => $target_keys);
    }

    /** Require both the global switch and the matching scope switch. */
    private static function apply_delete_policy(array $diff, array $target_items, array $scope, $global_allow)
    {
        if (! $global_allow) {
            return $diff;
        }
        foreach ((array) ($diff['items'] ?? array()) as $key => $item) {
            if (($item['action'] ?? '') !== Fsync_Diff::ACTION_BLOCKED || ! isset($target_items[$key])) {
                continue;
            }
            $raw = Fsync_Store::get((string) ($target_items[$key]['payload_hash'] ?? ''));
            $record = is_wp_error($raw) ? $raw : Fsync_Utils::decode($raw);
            if (is_wp_error($record) || ! self::scope_allows_delete((array) $record, $scope)) {
                continue;
            }
            $diff['items'][$key]['action'] = Fsync_Diff::ACTION_DELETE;
            $diff['counts'][Fsync_Diff::ACTION_BLOCKED]--;
            $diff['counts'][Fsync_Diff::ACTION_DELETE]++;
        }

        return $diff;
    }

    private static function scope_allows_delete(array $record, array $scope)
    {
        $kind = (string) ($record['kind'] ?? '');
        $data = (array) ($record['data'] ?? array());
        if ($kind === 'post') {
            return ! empty($scope['post_types'][(string) ($data['post_type'] ?? '')]['delete']);
        }
        if ($kind === 'term') {
            return ! empty($scope['taxonomies'][(string) ($data['taxonomy'] ?? '')]['delete']);
        }
        if ($kind === 'comment') {
            return ! empty($scope['comments_delete']);
        }
        if ($kind === 'user') {
            return ! empty($scope['users']['delete']);
        }
        if ($kind === 'option') {
            return ! empty($scope['options']['delete']);
        }
        if ($kind === 'file') {
            return ! empty($scope['files']['delete']);
        }
        if ($kind === 'table') {
            foreach ((array) ($scope['tables'] ?? array()) as $table) {
                if ((string) ($table['name'] ?? '') === (string) ($data['table'] ?? '')) {
                    return ! empty($table['delete']);
                }
            }
        }

        return false;
    }

    private static function plan_hash($release_id, array $source, array $target, array $items)
    {
        return Fsync_Utils::canonical_hash(
            array(
                'release_id' => $release_id,
                'scope_fingerprint' => $source['scope_fingerprint'],
                'source_root' => $source['root_hash'],
                'target_root' => $target['root_hash'],
                'items' => array_map(static function ($item) {
                    return array(
                        'key' => $item['key'],
                        'action' => $item['action'],
                        'source_hash' => $item['source_hash'],
                        'target_hash' => $item['target_hash'],
                        'base_hash' => $item['base_hash'],
                    );
                }, $items),
            )
        );
    }

    private static function preflight(array $source, array $target, array $diff, array $source_environment = array(), $profile = 'content', array $scope = array())
    {
        $blockers = Fsync_Env::blockers();
        $warnings = Fsync_Env::warnings();
        if (! is_writable(Fsync_Fs::private_dir())) {
            $blockers[] = array('code' => 'private_storage_not_writable', 'message' => '非公開保存領域へ書き込めません。');
        }
        $free = Fsync_Env::free_disk_bytes();
        // Snapshot CAS plus code-directory staging may temporarily need both
        // source and target bytes on the same volume.
        $needed = max(5242880, (int) ($source['total_bytes'] ?? 0) + (int) ($target['total_bytes'] ?? 0));
        if ($free > 0 && $free < $needed) {
            $blockers[] = array('code' => 'disk_space_insufficient', 'message' => 'スナップショットと受信オブジェクトを保存する空き容量が不足しています。');
        }
        if ((int) ($diff['counts'][Fsync_Diff::ACTION_CONFLICT] ?? 0) > 0) {
            $warnings[] = array('code' => 'conflicts_present', 'message' => '対象側にも変更された項目があります。適用前に競合解決が必要です。');
        }
        if ((int) ($diff['counts'][Fsync_Diff::ACTION_BLOCKED] ?? 0) > 0) {
            $warnings[] = array('code' => 'deletes_blocked', 'message' => '削除許可がないため保留された項目があります。');
        }
        if ($profile === 'full') {
            $file_scope = (array) ($scope['files'] ?? array());
            $core_mode = $file_scope['core'] ?? false;
            $source_core = (array) ($source_environment['core_checksums'] ?? array());
            $target_core = Fsync_Env::core_checksums();
            if (in_array($core_mode, array('checksum-only', 'sync'), true)) {
                foreach (array('source' => $source_core, 'target' => $target_core) as $side => $report) {
                    if (empty($report['available'])) {
                        $issue = array('code' => 'core_checksums_unavailable_' . $side, 'message' => $side . 'のWordPress公式チェックサムを取得できません。');
                        if ($core_mode === 'sync') {
                            $blockers[] = $issue;
                        } else {
                            $warnings[] = $issue;
                        }
                        continue;
                    }
                    $problems = count((array) ($report['modified'] ?? array())) + count((array) ($report['missing'] ?? array()));
                    if ($problems > 0) {
                        $issue = array(
                            'code' => 'core_checksums_mismatch_' . $side,
                            'message' => sprintf('%sのWordPress本体で公式チェックサム不一致が%d件あります。', $side, $problems),
                        );
                        if ($core_mode === 'sync') {
                            $blockers[] = $issue;
                        } else {
                            $warnings[] = $issue;
                        }
                    }
                    if ((array) ($report['extra'] ?? array()) !== array()) {
                        $issue = array(
                            'code' => 'core_extra_files_' . $side,
                            'message' => sprintf('%sのWordPress本体に公式配布外ファイルが%d件あります。', $side, count($report['extra'])),
                        );
                        if ($core_mode === 'sync') {
                            $blockers[] = $issue;
                        } else {
                            $warnings[] = $issue;
                        }
                    }
                }
            }
            $discovered_tables = Fsync_Introspect::tables();
            $registered_tables = array_fill_keys(array_map(static function ($table) {
                return (string) ($table['name'] ?? '');
            }, (array) ($scope['tables'] ?? array())), true);
            $discovered_tables = array_values(array_filter((array) $discovered_tables, static function ($table) use ($registered_tables) {
                $name = (string) (($table['table'] ?? '') ?: ($table['name'] ?? ''));

                return ! isset($registered_tables[$name]);
            }));
            if ($discovered_tables !== array()) {
                $warnings[] = array(
                    'code' => 'custom_tables_unregistered',
                    'message' => sprintf('設定に登録されていない独自テーブルが%d件あります。対象外でよいか確認してください。', count($discovered_tables)),
                    'tables' => array_values(array_map(static function ($table) {
                        return (string) ($table['table'] ?? '');
                    }, $discovered_tables)),
                );
            }
            foreach (self::writable_scope_paths($file_scope) as $label => $path) {
                if ($path === '' || (! is_dir($path) && ! is_dir(dirname($path)))
                    || (is_dir($path) ? ! is_writable($path) : ! is_writable(dirname($path)))) {
                    $blockers[] = array('code' => 'target_path_not_writable_' . sanitize_key($label), 'message' => sprintf('移行先の%sへ書き込めません。', $label));
                }
            }
        }
        foreach ((array) ($source_environment['warned_options'] ?? array()) as $option) {
            $warnings[] = array('code' => 'dangerous_option_' . sanitize_key($option), 'message' => sprintf('環境固有になり得るオプション「%s」が移行対象です。', $option));
        }
        $source_php = (string) ($source_environment['php_version'] ?? '');
        if ($source_php !== '' && version_compare($source_php, PHP_VERSION, '>')) {
            $warnings[] = array('code' => 'php_target_older', 'message' => sprintf('移行元PHP %sより移行先PHP %sが古いため、変更コードを対象側で構文検査します。', $source_php, PHP_VERSION));
        }
        $target_environment = Fsync_Config::environment(Fsync_Config_Io::active_env());
        foreach ((array) ($target_environment['requires_verified_on'] ?? array()) as $gate) {
            $proof = (array) (($source_environment['promotion_proofs'] ?? array())[$gate] ?? array());
            if ((string) ($proof['status'] ?? '') !== self::STATUS_VERIFIED
                || ! hash_equals(
                    (string) ($source['root_hash'] ?? ''),
                    (string) (($proof['source_manifest_root'] ?? '') ?: ($proof['manifest_root'] ?? ''))
                )) {
                $blockers[] = array(
                    'code' => 'promotion_gate_missing_' . sanitize_key($gate),
                    'message' => sprintf('同一マニフェストを「%s」で検証した受領証がないため、この環境へ昇格できません。', $gate),
                );
            }
        }

        return array(
            'ok' => $blockers === array(),
            'blockers' => self::dedupe_issues($blockers),
            'warnings' => self::dedupe_issues($warnings),
            'free_disk_bytes' => $free,
            'required_disk_bytes' => $needed,
            'source_items' => (int) ($source['item_count'] ?? 0),
            'target_items' => (int) ($target['item_count'] ?? 0),
        );
    }

    private static function writable_scope_paths(array $files)
    {
        $paths = array();
        if (! empty($files['uploads'])) {
            $uploads = wp_upload_dir(null, false);
            $paths['uploads'] = (string) ($uploads['basedir'] ?? '');
        }
        if (! empty($files['plugins'])) {
            $paths['plugins'] = WP_PLUGIN_DIR;
        }
        if (! empty($files['theme'])) {
            $paths['themes'] = get_theme_root();
        }
        if (! empty($files['mu_plugins']) && defined('WPMU_PLUGIN_DIR')) {
            $paths['mu-plugins'] = WPMU_PLUGIN_DIR;
        }
        if (($files['core'] ?? false) === 'sync') {
            $paths['core'] = ABSPATH;
        }

        return $paths;
    }

    private static function dedupe_issues(array $issues)
    {
        $out = array();
        foreach ($issues as $issue) {
            if (is_array($issue)) {
                // Keep structured context such as the affected files or
                // tables. The administration wizard, REST API and MCP clients
                // all use it to explain how an operator can clear a blocker.
                $issue['code'] = (string) ($issue['code'] ?? 'unknown');
                $issue['message'] = (string) ($issue['message'] ?? '');
            } else {
                $issue = array('code' => 'unknown', 'message' => (string) $issue);
            }
            $out[$issue['code'] . "\n" . $issue['message']] = $issue;
        }

        return array_values($out);
    }

    /** Parse only PHP files that the plan would actually replace. */
    private static function code_health(array $release)
    {
        $manifest = Fsync_Manifest::get((string) $release['manifest_id']);
        if (is_wp_error($manifest)) {
            return $manifest;
        }
        $errors = array();
        foreach (self::items($release['release_id']) as $item) {
            if (! in_array($item['action'], array(Fsync_Diff::ACTION_CREATE, Fsync_Diff::ACTION_UPDATE, Fsync_Diff::ACTION_CONFLICT), true)
                || ($item['action'] === Fsync_Diff::ACTION_CONFLICT && $item['resolution'] !== 'source')) {
                continue;
            }
            $manifest_item = $manifest['items'][$item['item_key']] ?? null;
            if (! is_array($manifest_item) || $item['entity_kind'] !== 'file') {
                continue;
            }
            $raw_record = Fsync_Store::get((string) $manifest_item['payload_hash']);
            $record = is_wp_error($raw_record) ? $raw_record : Fsync_Utils::decode($raw_record);
            if (is_wp_error($record)) {
                return $record;
            }
            $path = (string) ($record['data']['path'] ?? '');
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }
            $code = Fsync_Store::get((string) ($record['data']['content_hash'] ?? ''));
            if (is_wp_error($code)) {
                return $code;
            }
            try {
                token_get_all($code, TOKEN_PARSE);
            } catch (ParseError $error) {
                $errors[] = array('path' => (string) ($record['data']['category'] ?? '') . '/' . $path, 'message' => $error->getMessage());
            }
        }

        return $errors;
    }

    /** Find prior verified deployments of the exact source manifest. */
    private static function promotion_proofs($target_environment, $manifest_root, $profile)
    {
        global $wpdb;

        $environment = Fsync_Config::environment((string) $target_environment);
        $required = array_values((array) ($environment['requires_verified_on'] ?? array()));
        if ($required === array()) {
            return array();
        }
        $rows = $wpdb->get_results(
            'SELECT * FROM ' . Fsync_Schema::table('releases') . ' ORDER BY created_at DESC LIMIT 500',
            ARRAY_A
        );
        $proofs = array();
        foreach ((array) $rows as $row) {
            $candidate = self::shape($row);
            $summary = (array) $candidate['summary'];
            $gate = (string) ($summary['target_env'] ?? '');
            $proof = (array) ($summary['remote_verification'] ?? array());
            if (! in_array($gate, $required, true) || isset($proofs[$gate])) {
                continue;
            }
            if ((string) ($summary['profile'] ?? '') !== (string) $profile
                || ! hash_equals((string) $manifest_root, (string) ($summary['manifest_root'] ?? ''))
                || (string) ($proof['status'] ?? '') !== self::STATUS_VERIFIED
                || ! hash_equals(
                    (string) $manifest_root,
                    (string) (($proof['source_manifest_root'] ?? '') ?: ($proof['manifest_root'] ?? ''))
                )) {
                continue;
            }
            $proofs[$gate] = array_merge($proof, array('source_release_id' => $candidate['release_id']));
        }

        return $proofs;
    }

    private static function environment_summary()
    {
        $environment = Fsync_Env::report();

        return array(
            'env_name' => Fsync_Config_Io::active_env(),
            'wp_version' => $environment['wp_version'],
            'php_version' => $environment['php_version'],
            'plugin_version' => $environment['plugin_version'],
            'home_url' => $environment['site']['home_url'],
            'site_url' => $environment['site']['site_url'],
            'uploads_baseurl' => $environment['site']['uploads_baseurl'],
        );
    }

    private static function shape(array $row)
    {
        $summary = json_decode((string) $row['summary'], true);

        return array(
            'release_id' => (string) $row['release_id'],
            'peer_id' => (string) $row['peer_id'],
            'direction' => (string) $row['direction'],
            'status' => (string) $row['status'],
            'manifest_id' => (string) $row['manifest_id'],
            'base_receipt_id' => (string) $row['base_receipt_id'],
            'scope_fingerprint' => (string) $row['scope_fingerprint'],
            'config_hash' => (string) $row['config_hash'],
            'plan_hash' => (string) $row['plan_hash'],
            'confirmation_hash' => (string) $row['confirmation_hash'],
            'summary' => is_array($summary) ? $summary : array(),
            'created_by' => (int) $row['created_by'],
            'created_at' => (int) $row['created_at'],
            'updated_at' => (int) $row['updated_at'],
        );
    }
}
