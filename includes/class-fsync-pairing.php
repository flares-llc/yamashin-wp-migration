<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pairing: turning "two WordPress sites" into "two sites that trust each other"
 * with a single copy and paste.
 *
 * The receiver issues a key and emits a blob containing it. The blob is the
 * only time the shared secret is ever transmitted, and it is consumed by the
 * first successful confirmation, so a blob left in a chat log is inert.
 *
 * The confirmation itself is a signed request: possession of the secret is
 * proven by using it, which means a blob that was copied incorrectly fails
 * immediately and visibly rather than half-working.
 */
final class Fsync_Pairing
{
    const BLOB_VERSION = 1;

    /** How long an unconsumed pairing blob stays valid. */
    const TTL = 1800;

    /**
     * Issue a pairing blob. Runs on the receiving site.
     *
     * connect_url exists because the address a peer must dial is not always
     * home_url(): a site behind a load balancer, on an internal hostname, or in
     * a container network is reached at a different address than the one it
     * renders links with. Defaults to home_url().
     *
     * @param array $args env_name, label, capabilities, ip_allowlist, connect_url
     * @return array{blob: string, key_id: string, expires_at: int}|WP_Error
     */
    public static function create(array $args = array())
    {
        $env_name = Fsync_Peer::normalize_env_name($args['env_name'] ?? 'local');
        if (is_wp_error($env_name)) {
            return $env_name;
        }

        // Validate before issuing the key. Otherwise a typo in this field leaves
        // a pending key whose blob can never be used.
        $connect_url = trim((string) ($args['connect_url'] ?? ''));
        $connect_url = self::normalize_url($connect_url === '' ? home_url('/') : $connect_url);
        if (is_wp_error($connect_url)) {
            return new WP_Error('fsync_pairing_connect_url', $connect_url->get_error_message());
        }

        $issued = Fsync_Keys::issue(
            array(
                'label' => (string) ($args['label'] ?? $env_name),
                'capabilities' => $args['capabilities'] ?? Fsync_Keys::PRESETS['deploy'],
                'ip_allowlist' => $args['ip_allowlist'] ?? array(),
                'direction' => Fsync_Keys::DIRECTION_INBOUND,
                'status' => Fsync_Keys::STATUS_PENDING,
            )
        );

        if (is_wp_error($issued)) {
            return $issued;
        }

        $expires_at = Fsync_Utils::now() + self::TTL;

        $payload = array(
            'v' => self::BLOB_VERSION,
            'site' => $connect_url,
            'home' => home_url('/'),
            'role' => (string) get_option('fsync_site_role', ''),
            'env' => $env_name,
            'key_id' => $issued['key_id'],
            'secret' => $issued['secret'],
            'caps' => Fsync_Keys::sanitize_capabilities($args['capabilities'] ?? Fsync_Keys::PRESETS['deploy']),
            'alg' => Fsync_Signer_Hmac::ALGORITHM,
            'protocol' => FSYNC_PROTOCOL,
            'hash_algo_version' => FSYNC_HASH_ALGO_VERSION,
            'plugin_version' => FSYNC_VERSION,
            'expires_at' => $expires_at,
        );

        $encoded = Fsync_Utils::encode($payload);
        if (is_wp_error($encoded)) {
            return $encoded;
        }

        return array(
            'blob' => Fsync_Utils::base64url_encode($encoded),
            'key_id' => $issued['key_id'],
            'expires_at' => $expires_at,
        );
    }

    /**
     * Decode and validate a blob. Runs on the initiating site.
     *
     * @param string $blob
     * @return array|WP_Error
     */
    public static function parse($blob)
    {
        $blob = trim((string) $blob);
        if ($blob === '') {
            return new WP_Error('fsync_pairing_empty', '接続情報が空です。');
        }

        // Tolerate the whitespace and line breaks that survive a copy/paste
        // through a chat client or an email.
        $blob = (string) preg_replace('/\s+/', '', $blob);

        $decoded = Fsync_Utils::base64url_decode($blob);
        if ($decoded === false) {
            return new WP_Error('fsync_pairing_malformed', '接続情報を解読できません。貼り付け内容を確認してください。');
        }

        $payload = json_decode($decoded, true);
        if (! is_array($payload)) {
            return new WP_Error('fsync_pairing_malformed', '接続情報の形式が不正です。');
        }

        if ((int) ($payload['v'] ?? 0) !== self::BLOB_VERSION) {
            return new WP_Error('fsync_pairing_version', 'この接続情報の形式には対応していません。');
        }

        foreach (array('site', 'env', 'key_id', 'secret') as $required) {
            if (empty($payload[$required])) {
                return new WP_Error(
                    'fsync_pairing_incomplete',
                    sprintf('接続情報に %s が含まれていません。', $required)
                );
            }
        }

        if (! empty($payload['expires_at']) && (int) $payload['expires_at'] < Fsync_Utils::now()) {
            return new WP_Error(
                'fsync_pairing_expired',
                '接続情報の有効期限が切れています。接続先で再発行してください。'
            );
        }

        if (! empty($payload['protocol']) && (string) $payload['protocol'] !== FSYNC_PROTOCOL) {
            return new WP_Error(
                'fsync_pairing_protocol',
                sprintf(
                    'プロトコルのバージョンが一致しません（相手: %s / このサイト: %s）。両方のプラグインを同じバージョンに更新してください。',
                    (string) $payload['protocol'],
                    FSYNC_PROTOCOL
                )
            );
        }

        if (
            ! empty($payload['hash_algo_version'])
            && (int) $payload['hash_algo_version'] !== FSYNC_HASH_ALGO_VERSION
        ) {
            return new WP_Error(
                'fsync_pairing_hash_algo',
                'ハッシュ方式のバージョンが一致しません。両方のプラグインを同じバージョンに更新してください。'
            );
        }

        $url = self::normalize_url((string) $payload['site']);
        if (is_wp_error($url)) {
            return $url;
        }

        $env_name = Fsync_Peer::normalize_env_name($payload['env']);
        if (is_wp_error($env_name)) {
            return $env_name;
        }

        return array(
            'site' => $url,
            'home' => (string) ($payload['home'] ?? $payload['site']),
            'role' => (string) ($payload['role'] ?? ''),
            'env' => $env_name,
            'key_id' => (string) $payload['key_id'],
            'secret' => (string) $payload['secret'],
            'caps' => (array) ($payload['caps'] ?? array()),
            'algorithm' => (string) ($payload['alg'] ?? Fsync_Signer_Hmac::ALGORITHM),
            'plugin_version' => (string) ($payload['plugin_version'] ?? ''),
            'expires_at' => (int) ($payload['expires_at'] ?? 0),
        );
    }

    /**
     * Store the peer and its secret locally. Runs on the initiating site.
     *
     * The signed confirmation call is made by the caller afterwards, so that a
     * network failure leaves a peer record that can be retried rather than
     * losing the pasted blob.
     *
     * @param string $blob
     * @param string $env_name Optional local name for the environment.
     * @return array{peer_id: string, credential_id: string, parsed: array}|WP_Error
     */
    public static function import($blob, $env_name = '')
    {
        $parsed = self::parse($blob);
        if (is_wp_error($parsed)) {
            return $parsed;
        }

        $env_name = $env_name === '' ? $parsed['env'] : $env_name;
        $env_name = Fsync_Peer::normalize_env_name($env_name);
        if (is_wp_error($env_name)) {
            return $env_name;
        }

        $credential_id = 'peer-' . $env_name;
        $existing = Fsync_Peer::by_env($env_name);

        if ($existing !== null && ! self::same_site($existing['url'], $parsed['site'])) {
            return new WP_Error(
                'fsync_pairing_env_conflict',
                sprintf(
                    '環境名「%s」は別のサイト（%s）に使用されています。別の環境名を指定してください。',
                    $env_name,
                    $existing['url']
                )
            );
        }

        $snapshot = self::snapshot_import_state($env_name);
        $stored = Fsync_Credentials::put($credential_id, 'peer', $parsed['secret']);
        if (is_wp_error($stored)) {
            $restored = self::restore_import_state($env_name, $snapshot, $stored->get_error_code());

            return is_wp_error($restored) ? $restored : $stored;
        }

        $peer_id = Fsync_Peer::upsert(
            array(
                'peer_id' => $existing === null ? '' : $existing['peer_id'],
                'env_name' => $env_name,
                'site_role' => $parsed['role'],
                'url' => $parsed['site'],
                'outbound_key_id' => $parsed['key_id'],
            )
        );

        if (is_wp_error($peer_id)) {
            $restored = self::restore_import_state($env_name, $snapshot, $peer_id->get_error_code());

            return is_wp_error($restored) ? $restored : $peer_id;
        }

        Fsync_Log::info(
            'pairing_imported',
            sprintf('接続情報を取り込みました: %s (%s)', $env_name, $parsed['site']),
            array('peer_id' => $peer_id, 'key_id' => $parsed['key_id'])
        );

        return array(
            'peer_id' => $peer_id,
            'credential_id' => $credential_id,
            'parsed' => array_diff_key($parsed, array('secret' => null)),
        );
    }

    /**
     * Import a blob and complete the handshake in one step.
     *
     * Import is committed before the network call, so a confirmation that fails
     * for a transient reason leaves a peer record that can be retried instead
     * of discarding a blob the operator may no longer have.
     *
     * @param string $blob
     * @param string $env_name
     * @return array|WP_Error
     */
    public static function connect($blob, $env_name = '')
    {
        $parsed = self::parse($blob);
        if (is_wp_error($parsed)) {
            return $parsed;
        }

        $resolved_env = $env_name === '' ? $parsed['env'] : $env_name;
        $resolved_env = Fsync_Peer::normalize_env_name($resolved_env);
        if (is_wp_error($resolved_env)) {
            return $resolved_env;
        }

        // Keep an exact database checkpoint. Re-pairing an existing environment
        // replaces its key and credential, so deleting on failure would be just
        // as damaging as leaving a new orphan behind.
        $snapshot = self::snapshot_import_state($resolved_env);
        $imported = self::import($blob, $env_name);
        if (is_wp_error($imported)) {
            return $imported;
        }

        $peer = Fsync_Peer::find($imported['peer_id']);
        if ($peer === null) {
            $restored = self::restore_import_state($resolved_env, $snapshot, 'fsync_peer_missing');
            if (is_wp_error($restored)) {
                return $restored;
            }

            return new WP_Error('fsync_peer_missing', 'ピア情報を保存できませんでした。');
        }

        $client = Fsync_Client::for_peer($peer);
        if (is_wp_error($client)) {
            $restored = self::restore_import_state($resolved_env, $snapshot, $client->get_error_code());

            return is_wp_error($restored) ? $restored : $client;
        }

        $response = $client->post(
            'pair/confirm',
            array(
                'env_name' => Fsync_Config_Io::active_env() ?: 'local',
                'site_role' => (string) get_option('fsync_site_role', ''),
                'url' => home_url('/'),
            )
        );

        if (is_wp_error($response)) {
            $data = $response->get_error_data();
            $retryable = is_array($data) && ! empty($data['retryable']);

            if (! $retryable) {
                $restored = self::restore_import_state(
                    $resolved_env,
                    $snapshot,
                    $response->get_error_code()
                );
                if (is_wp_error($restored)) {
                    return $restored;
                }
            }

            return $response;
        }

        // The peer tells us what it calls itself; record that rather than what
        // the blob claimed, so the two sides agree on naming.
        $updated = Fsync_Peer::upsert(
            array(
                'peer_id' => $peer['peer_id'],
                'env_name' => $peer['env_name'],
                'site_role' => (string) ($response['site_role'] ?? $peer['site_role']),
                'url' => $peer['url'],
                'outbound_key_id' => $peer['outbound_key_id'],
                'last_contact_at' => Fsync_Utils::now(),
            )
        );

        // Confirmation has already consumed the remote key. The imported row
        // contains all critical connection data, so a nonessential contact-time
        // update must not turn a successful pairing into an unretryable failure.
        if (is_wp_error($updated)) {
            Fsync_Log::warning(
                'pairing_contact_update_failed',
                $updated->get_error_message(),
                array('peer_id' => $peer['peer_id'], 'key_id' => $peer['outbound_key_id'])
            );
        }

        Fsync_Log::info(
            'pairing_completed',
            sprintf('接続を確立しました: %s', $peer['env_name']),
            array('peer_id' => $peer['peer_id'], 'key_id' => $peer['outbound_key_id'])
        );

        return array(
            'peer_id' => $peer['peer_id'],
            'env_name' => $peer['env_name'],
            'capabilities' => (array) ($response['capabilities'] ?? array()),
            'remote' => $response,
        );
    }

    /**
     * Consume the pairing. Runs on the receiving site, from /pair/confirm.
     *
     * By the time this is reached the request has already been verified as
     * signed by the key being confirmed, so possession of the secret is proven.
     *
     * @param string $key_id
     * @param array $peer Details reported by the initiator.
     * @return array|WP_Error
     */
    public static function confirm($key_id, array $peer = array())
    {
        $key = Fsync_Keys::find($key_id);
        if ($key === null) {
            return new WP_Error('fsync_key_unknown', '接続キーが見つかりません。', array('status' => 404));
        }

        if ($key['status'] !== Fsync_Keys::STATUS_PENDING) {
            return new WP_Error(
                'fsync_pairing_consumed',
                'このペアリングは既に完了しています。',
                array('status' => 409)
            );
        }

        if ($key['created_at'] + self::TTL < Fsync_Utils::now()) {
            Fsync_Keys::retire($key_id);

            return new WP_Error(
                'fsync_pairing_expired',
                'ペアリングの有効期限が切れています。接続情報を再発行してください。',
                array('status' => 410)
            );
        }

        $env_name = Fsync_Peer::normalize_env_name($peer['env_name'] ?? 'local');
        if (is_wp_error($env_name)) {
            return $env_name;
        }

        $url = self::normalize_url((string) ($peer['url'] ?? ''));
        if (is_wp_error($url)) {
            return $url;
        }

        $existing = Fsync_Peer::by_env($env_name);
        if ($existing !== null && ! self::same_site($existing['url'], $url)) {
            return new WP_Error(
                'fsync_pairing_env_conflict',
                sprintf(
                    '環境名「%s」は別のサイト（%s）に使用されています。接続元で別の環境名を設定してください。',
                    $env_name,
                    $existing['url']
                ),
                array('status' => 409)
            );
        }

        $snapshot = self::snapshot_import_state($env_name);

        $peer_id = Fsync_Peer::upsert(
            array(
                'peer_id' => $existing === null ? '' : $existing['peer_id'],
                'env_name' => $env_name,
                'site_role' => (string) ($peer['site_role'] ?? ''),
                'url' => $url,
                'last_contact_at' => Fsync_Utils::now(),
            )
        );

        if (is_wp_error($peer_id)) {
            return $peer_id;
        }

        $activated = Fsync_Keys::activate($key_id, $peer_id);
        if (is_wp_error($activated)) {
            $restored = self::restore_import_state(
                $env_name,
                $snapshot,
                $activated->get_error_code(),
                false
            );

            return is_wp_error($restored) ? $restored : $activated;
        }

        // A peer ledger has one identity and one current inbound credential.
        // Leaving older keys active after re-pairing would let a superseded or
        // compromised secret continue authenticating indefinitely.
        $retired = Fsync_Keys::retire_other_inbound($peer_id, $key_id);
        if (is_wp_error($retired)) {
            // The new key has already been consumed and is the only credential
            // the initiator retained. Do not report a retryable pairing failure;
            // surface the cleanup problem prominently in the audit log instead.
            Fsync_Log::error(
                'peer_keys_retire_failed',
                $retired->get_error_message(),
                array('key_id' => $key_id, 'peer_id' => $peer_id)
            );
        }

        return array(
            'peer_id' => $peer_id,
            'env_name' => $env_name,
            'capabilities' => $key['capabilities'],
        );
    }

    /**
     * Validate and normalize a peer URL.
     *
     * @param string $url
     * @return string|WP_Error
     */
    public static function normalize_url($url)
    {
        $url = esc_url_raw(trim((string) $url));
        $parts = wp_parse_url($url);
        $parts = is_array($parts) ? $parts : array();
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if ($url === '' || $host === '' || ! in_array($scheme, array('http', 'https'), true)) {
            return new WP_Error('fsync_pairing_url', '接続先URLが不正です。');
        }

        if (
            isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return new WP_Error('fsync_pairing_url', '接続先URLに認証情報・クエリ・フラグメントは指定できません。');
        }

        if ($scheme !== 'https' && ! self::is_local_url($url)) {
            return new WP_Error(
                'fsync_pairing_insecure',
                '接続先がHTTPSではありません。署名は保護されますが、通信内容が平文になるため許可していません。'
            );
        }

        // DNS hostnames and schemes are case-insensitive, and an explicit
        // default port names the same origin as an omitted one. Canonicalizing
        // these prevents a harmless spelling difference from triggering the
        // environment-collision guard during re-pairing.
        $host = strtolower($host);
        if ($host[0] !== '[') {
            $host = rtrim($host, '.');
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : 0;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = 0;
        }

        $normalized = $scheme . '://' . $host;
        if ($port > 0) {
            $normalized .= ':' . $port;
        }
        $normalized .= (string) ($parts['path'] ?? '');

        return trailingslashit($normalized);
    }

    /**
     * @param string $left
     * @param string $right
     * @return bool
     */
    private static function same_site($left, $right)
    {
        $left = self::normalize_url($left);
        $right = self::normalize_url($right);

        return ! is_wp_error($left) && ! is_wp_error($right) && $left === $right;
    }

    /**
     * Capture raw rows so ciphertext and timestamps can be restored exactly.
     * The snapshot never leaves this class or reaches a response.
     *
     * @param string $env_name
     * @return array{peer: array|null, credential: array|null}
     */
    private static function snapshot_import_state($env_name)
    {
        global $wpdb;

        $peer_table = Fsync_Schema::table('peers');
        $credential_table = Fsync_Schema::table('credentials');
        $credential_id = 'peer-' . $env_name;

        return array(
            'peer' => $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$peer_table} WHERE env_name = %s", $env_name),
                ARRAY_A
            ),
            'credential' => $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$credential_table} WHERE credential_id = %s",
                    $credential_id
                ),
                ARRAY_A
            ),
        );
    }

    /**
     * Restore an import checkpoint after a terminal failure.
     *
     * @param string $env_name
     * @param array $snapshot
     * @param string $reason
     * @param bool $restore_credential
     * @return true|WP_Error
     */
    private static function restore_import_state($env_name, array $snapshot, $reason, $restore_credential = true)
    {
        global $wpdb;

        $failed = array();
        $peer_table = Fsync_Schema::table('peers');
        if (is_array($snapshot['peer'] ?? null)) {
            if ($wpdb->replace($peer_table, $snapshot['peer']) === false) {
                $failed[] = 'peer';
            }
        } else {
            if ($wpdb->delete($peer_table, array('env_name' => $env_name)) === false) {
                $failed[] = 'peer';
            }
        }

        if ($restore_credential) {
            $credential_table = Fsync_Schema::table('credentials');
            if (is_array($snapshot['credential'] ?? null)) {
                if ($wpdb->replace($credential_table, $snapshot['credential']) === false) {
                    $failed[] = 'credential';
                }
            } else {
                if (
                    $wpdb->delete(
                        $credential_table,
                        array('credential_id' => 'peer-' . $env_name)
                    ) === false
                ) {
                    $failed[] = 'credential';
                }
            }
        }

        if ($failed !== array()) {
            $error = new WP_Error(
                'fsync_pairing_rollback_failed',
                sprintf(
                    'ペアリング失敗後の状態を復元できませんでした（%s）。接続一覧を確認してください。',
                    implode(', ', $failed)
                ),
                array('status' => 500, 'original_error' => (string) $reason, 'failed' => $failed)
            );
            Fsync_Log::error(
                'pairing_import_rollback_failed',
                $error->get_error_message(),
                array('verdict' => (string) $reason, 'data' => array('env_name' => $env_name))
            );

            return $error;
        }

        Fsync_Log::warning(
            'pairing_import_rolled_back',
            sprintf('ペアリングの取り込みを巻き戻しました: %s', $env_name),
            array('verdict' => (string) $reason, 'data' => array('env_name' => $env_name))
        );

        return true;
    }

    /**
     * Whether a URL points at a development host, where plain HTTP is normal.
     *
     * @param string $url
     * @return bool
     */
    public static function is_local_url($url)
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return false;
        }

        $host = rtrim(trim(strtolower($host), '[]'), '.');

        if (in_array($host, array('localhost', '127.0.0.1', '::1', 'host.docker.internal'), true)) {
            return true;
        }

        foreach (array('.local', '.test', '.localhost', '.internal', '.lan') as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        // A single-label hostname cannot be a public DNS name -- every
        // internet-reachable host has at least one dot. So a bare name is a
        // container service, a LAN host or an /etc/hosts entry, all of which
        // legitimately speak plain HTTP.
        if (strpos($host, '.') === false) {
            return true;
        }

        // RFC1918 ranges, which is what a docker-compose or LAN setup uses.
        return Fsync_Auth::ip_matches($host, '10.0.0.0/8')
            || Fsync_Auth::ip_matches($host, '172.16.0.0/12')
            || Fsync_Auth::ip_matches($host, '192.168.0.0/16');
    }
}
