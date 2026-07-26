<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Connection keys and their capability scopes.
 *
 * The capability set is the main practical advantage over a WordPress
 * application password: a scheduled drift check can be given a key that is
 * physically incapable of writing anything, which is impossible with a
 * credential that always carries the full rights of its user.
 */
final class Fsync_Keys
{
    const PURPOSE = 'key';

    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_RETIRED = 'retired';

    const DIRECTION_INBOUND = 'inbound';
    const DIRECTION_OUTBOUND = 'outbound';

    /** Window during which a rotated key's predecessor is still accepted. */
    const ROTATION_GRACE = DAY_IN_SECONDS;

    /** Capabilities, in increasing order of danger. */
    const CAPABILITIES = array(
        'status' => 'ハンドシェイクと診断',
        'read' => '差分の読み取り（ドリフト検知・pull）',
        'write' => '変更の適用',
        'files' => 'ファイルの受信',
        'promote' => 'リリースの昇格',
        'restore' => '復元とロールバック',
        'admin' => 'キー管理',
    );

    /** Ready-made scopes offered in the UI. */
    const PRESETS = array(
        'readonly' => array('status', 'read'),
        'deploy' => array('status', 'read', 'write', 'files', 'promote'),
        'full' => array('status', 'read', 'write', 'files', 'promote', 'restore'),
    );

    /**
     * Issue a new key.
     *
     * The secret is returned exactly once, to be embedded in a pairing blob. It
     * is never retrievable in plaintext afterwards through any public path.
     *
     * @param array $args label, capabilities, env_name, expires_at, ip_allowlist, status
     * @return array{key_id: string, secret: string}|WP_Error
     */
    public static function issue($args = array())
    {
        global $wpdb;

        $key_id = Fsync_Utils::random_hex(8);
        if (is_wp_error($key_id)) {
            return $key_id;
        }

        try {
            $secret = base64_encode(random_bytes(32));
        } catch (Exception $exception) {
            return new WP_Error('fsync_random_failed', '安全な乱数を生成できません。');
        }

        $ciphertext = Fsync_Crypto::encrypt($secret, self::PURPOSE, $key_id);
        if (is_wp_error($ciphertext)) {
            return $ciphertext;
        }

        $capabilities = self::sanitize_capabilities($args['capabilities'] ?? self::PRESETS['readonly']);

        $encoded_caps = Fsync_Utils::encode($capabilities);
        $encoded_ips = Fsync_Utils::encode(self::sanitize_ips($args['ip_allowlist'] ?? array()));
        if (is_wp_error($encoded_caps) || is_wp_error($encoded_ips)) {
            return new WP_Error('fsync_key_encode_failed', 'キー情報を保存できません。');
        }

        $inserted = $wpdb->insert(
            Fsync_Schema::table('keys'),
            array(
                'key_id' => $key_id,
                'peer_id' => (string) ($args['peer_id'] ?? ''),
                'direction' => (string) ($args['direction'] ?? self::DIRECTION_INBOUND),
                'label' => substr((string) ($args['label'] ?? ''), 0, 191),
                'secret_ciphertext' => $ciphertext,
                'algorithm' => Fsync_Signer_Hmac::ALGORITHM,
                'capabilities' => $encoded_caps,
                'ip_allowlist' => $encoded_ips,
                'status' => (string) ($args['status'] ?? self::STATUS_PENDING),
                'supersedes' => (string) ($args['supersedes'] ?? ''),
                'grace_until' => 0,
                'expires_at' => (int) ($args['expires_at'] ?? 0),
                'created_at' => Fsync_Utils::now(),
            )
        );

        if ($inserted === false) {
            return new WP_Error('fsync_key_write_failed', 'キーを保存できませんでした。');
        }

        Fsync_Log::info(
            'key_issued',
            sprintf('接続キーを発行しました: %s', $key_id),
            array('key_id' => $key_id, 'data' => array('capabilities' => $capabilities))
        );

        return array('key_id' => $key_id, 'secret' => $secret);
    }

    /**
     * Key record without its secret. Safe to expose.
     *
     * @param string $key_id
     * @return array|null
     */
    public static function find($key_id)
    {
        global $wpdb;

        $table = Fsync_Schema::table('keys');
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE key_id = %s", (string) $key_id),
            ARRAY_A
        );

        return $row === null ? null : self::shape($row);
    }

    /**
     * Decrypted shared secret. Internal use only.
     *
     * @param string $key_id
     * @return string|WP_Error
     */
    public static function secret($key_id)
    {
        global $wpdb;

        $table = Fsync_Schema::table('keys');
        $ciphertext = $wpdb->get_var(
            $wpdb->prepare("SELECT secret_ciphertext FROM {$table} WHERE key_id = %s", (string) $key_id)
        );

        if ($ciphertext === null) {
            return new WP_Error('fsync_key_missing', '接続キーが見つかりません。');
        }

        return Fsync_Crypto::decrypt((string) $ciphertext, self::PURPOSE, (string) $key_id);
    }

    /**
     * @param array $filters status, direction, peer_id
     * @return array<int, array>
     */
    public static function all($filters = array())
    {
        global $wpdb;

        $table = Fsync_Schema::table('keys');
        $clauses = array('1=1');
        $params = array();

        foreach (array('status', 'direction', 'peer_id') as $column) {
            if (! empty($filters[$column])) {
                $clauses[] = "{$column} = %s";
                $params[] = (string) $filters[$column];
            }
        }

        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $clauses) . ' ORDER BY created_at DESC';
        $sql = $params === array() ? $sql : $wpdb->prepare($sql, $params);

        return array_map([self::class, 'shape'], (array) $wpdb->get_results($sql, ARRAY_A));
    }

    /**
     * Activate a key that was issued as part of a pairing blob.
     *
     * @param string $key_id
     * @param string $peer_id
     * @return true|WP_Error
     */
    public static function activate($key_id, $peer_id = '')
    {
        global $wpdb;

        $updated = $wpdb->update(
            Fsync_Schema::table('keys'),
            array('status' => self::STATUS_ACTIVE, 'peer_id' => (string) $peer_id),
            array('key_id' => (string) $key_id, 'status' => self::STATUS_PENDING)
        );

        if ($updated !== 1) {
            return new WP_Error(
                'fsync_pairing_consumed',
                'このペアリング情報は既に使用済みか、有効期限が切れています。'
            );
        }

        Fsync_Log::info(
            'key_activated',
            sprintf('接続キーを有効化しました: %s', $key_id),
            array('key_id' => (string) $key_id, 'peer_id' => (string) $peer_id)
        );

        return true;
    }

    /**
     * Issue a replacement key, keeping the old one valid for a grace period.
     *
     * Rotation is never automatic. Locking yourself out of production is a
     * worse outcome than a key that lived a while longer than ideal.
     *
     * @param string $key_id
     * @return array{key_id: string, secret: string}|WP_Error
     */
    public static function rotate($key_id)
    {
        global $wpdb;

        $existing = self::find($key_id);
        if ($existing === null) {
            return new WP_Error('fsync_key_missing', '接続キーが見つかりません。');
        }

        $issued = self::issue(
            array(
                'label' => $existing['label'],
                'capabilities' => $existing['capabilities'],
                'ip_allowlist' => $existing['ip_allowlist'],
                'peer_id' => $existing['peer_id'],
                'direction' => $existing['direction'],
                'status' => self::STATUS_PENDING,
                'supersedes' => $existing['key_id'],
            )
        );

        if (is_wp_error($issued)) {
            return $issued;
        }

        $wpdb->update(
            Fsync_Schema::table('keys'),
            array('grace_until' => Fsync_Utils::now() + self::ROTATION_GRACE),
            array('key_id' => $existing['key_id'])
        );

        Fsync_Log::warning(
            'key_rotated',
            sprintf('接続キーをローテーションしました: %s → %s', $existing['key_id'], $issued['key_id']),
            array('key_id' => $existing['key_id'])
        );

        return $issued;
    }

    /**
     * Revoke a key immediately.
     *
     * @param string $key_id
     * @return true
     */
    public static function retire($key_id)
    {
        global $wpdb;

        $wpdb->update(
            Fsync_Schema::table('keys'),
            array('status' => self::STATUS_RETIRED, 'grace_until' => 0),
            array('key_id' => (string) $key_id)
        );

        Fsync_Log::warning(
            'key_retired',
            sprintf('接続キーを失効しました: %s', $key_id),
            array('key_id' => (string) $key_id)
        );

        return true;
    }

    /**
     * Record that a key was used, for the audit view.
     *
     * @param string $key_id
     * @return void
     */
    public static function touch($key_id)
    {
        global $wpdb;

        $wpdb->update(
            Fsync_Schema::table('keys'),
            array('last_used_at' => Fsync_Utils::now()),
            array('key_id' => (string) $key_id)
        );
    }

    /**
     * Whether a key row may currently be used to sign requests.
     *
     * @param array $key
     * @return true|WP_Error
     */
    public static function usable(array $key)
    {
        $now = Fsync_Utils::now();

        if ($key['status'] === self::STATUS_RETIRED) {
            return new WP_Error('fsync_key_retired', 'この接続キーは失効しています。', array('status' => 401));
        }

        if ($key['status'] === self::STATUS_PENDING) {
            return new WP_Error(
                'fsync_key_pending',
                'この接続キーはまだペアリングが完了していません。',
                array('status' => 401)
            );
        }

        if ($key['expires_at'] > 0 && $key['expires_at'] < $now) {
            return new WP_Error('fsync_key_expired', 'この接続キーは有効期限切れです。', array('status' => 401));
        }

        // A superseded key stays usable until its grace period ends, so that a
        // rotation cannot strand a client mid-run.
        if ($key['grace_until'] > 0 && $key['grace_until'] < $now) {
            return new WP_Error(
                'fsync_key_superseded',
                'この接続キーはローテーション済みで、猶予期間も終了しています。',
                array('status' => 401)
            );
        }

        return true;
    }

    /**
     * @param array $key
     * @param string $capability
     * @return bool
     */
    public static function can(array $key, $capability)
    {
        return in_array((string) $capability, (array) $key['capabilities'], true);
    }

    /**
     * @param mixed $capabilities
     * @return array<int, string>
     */
    public static function sanitize_capabilities($capabilities)
    {
        $valid = array_keys(self::CAPABILITIES);
        $out = array();

        foreach ((array) $capabilities as $capability) {
            $capability = (string) $capability;
            if (in_array($capability, $valid, true)) {
                $out[] = $capability;
            }
        }

        // Every key can at least identify itself; without this a key could be
        // issued that cannot even complete a handshake.
        if (! in_array('status', $out, true)) {
            $out[] = 'status';
        }

        return array_values(array_unique($out));
    }

    /**
     * @param mixed $ips
     * @return array<int, string>
     */
    public static function sanitize_ips($ips)
    {
        $out = array();

        foreach ((array) $ips as $ip) {
            $ip = trim((string) $ip);
            if ($ip !== '') {
                $out[] = $ip;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array $row
     * @return array
     */
    private static function shape($row)
    {
        $capabilities = json_decode((string) $row['capabilities'], true);
        $ips = json_decode((string) $row['ip_allowlist'], true);

        return array(
            'key_id' => (string) $row['key_id'],
            'peer_id' => (string) $row['peer_id'],
            'direction' => (string) $row['direction'],
            'label' => (string) $row['label'],
            'algorithm' => (string) $row['algorithm'],
            'capabilities' => is_array($capabilities) ? $capabilities : array(),
            'ip_allowlist' => is_array($ips) ? $ips : array(),
            'status' => (string) $row['status'],
            'supersedes' => (string) $row['supersedes'],
            'grace_until' => (int) $row['grace_until'],
            'expires_at' => (int) $row['expires_at'],
            'last_used_at' => (int) $row['last_used_at'],
            'created_at' => (int) $row['created_at'],
        );
    }
}
