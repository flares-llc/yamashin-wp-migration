<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Peer ledger: one row per environment this site is paired with.
 *
 * peer_id is minted once at pairing and stored on both sides. A site URL is
 * never used as identity because it changes -- a staging site moves domains, a
 * production site gains or loses www -- and an identity that changes silently
 * re-creates every baseline as if the peer were brand new.
 */
final class Fsync_Peer
{
    const STATUS_ACTIVE = 'active';
    const STATUS_DISABLED = 'disabled';

    /**
     * Create or update a peer record.
     *
     * @param array $args peer_id, env_name, site_role, url, outbound_key_id,
     *                    scope_fingerprint, handshake
     * @return string|WP_Error peer_id
     */
    public static function upsert(array $args)
    {
        global $wpdb;

        $peer_id = (string) ($args['peer_id'] ?? '');
        if ($peer_id === '') {
            $peer_id = Fsync_Utils::random_hex(8);
            if (is_wp_error($peer_id)) {
                return $peer_id;
            }
        }

        $env_name = self::normalize_env_name($args['env_name'] ?? '');
        if (is_wp_error($env_name)) {
            return $env_name;
        }

        $handshake = Fsync_Utils::encode((array) ($args['handshake'] ?? array()));
        if (is_wp_error($handshake)) {
            $handshake = '{}';
        }

        $row = array(
            'peer_id' => $peer_id,
            'env_name' => $env_name,
            'site_role' => substr((string) ($args['site_role'] ?? ''), 0, 32),
            'url' => esc_url_raw((string) ($args['url'] ?? '')),
            'outbound_key_id' => substr((string) ($args['outbound_key_id'] ?? ''), 0, 32),
            'scope_fingerprint' => substr((string) ($args['scope_fingerprint'] ?? ''), 0, 64),
            'handshake' => $handshake,
            'last_contact_at' => (int) ($args['last_contact_at'] ?? 0),
            'clock_skew' => (int) ($args['clock_skew'] ?? 0),
            'status' => (string) ($args['status'] ?? self::STATUS_ACTIVE),
        );

        $existing = self::find($peer_id);

        if ($existing === null) {
            $row['created_at'] = Fsync_Utils::now();
            $result = $wpdb->insert(Fsync_Schema::table('peers'), $row);
        } else {
            $result = $wpdb->update(Fsync_Schema::table('peers'), $row, array('peer_id' => $peer_id));
        }

        if ($result === false) {
            return new WP_Error(
                'fsync_peer_write_failed',
                'ピア情報を保存できませんでした。環境名が重複していないか確認してください。'
            );
        }

        return $peer_id;
    }

    /**
     * @param string $peer_id
     * @return array|null
     */
    public static function find($peer_id)
    {
        global $wpdb;

        $table = Fsync_Schema::table('peers');
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE peer_id = %s", (string) $peer_id),
            ARRAY_A
        );

        return $row === null ? null : self::shape($row);
    }

    /**
     * @param string $env_name
     * @return array|null
     */
    public static function by_env($env_name)
    {
        global $wpdb;

        $table = Fsync_Schema::table('peers');
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE env_name = %s", (string) $env_name),
            ARRAY_A
        );

        return $row === null ? null : self::shape($row);
    }

    /**
     * @return array<int, array>
     */
    public static function all()
    {
        global $wpdb;

        $table = Fsync_Schema::table('peers');

        return array_map(
            [self::class, 'shape'],
            (array) $wpdb->get_results("SELECT * FROM {$table} ORDER BY env_name ASC", ARRAY_A)
        );
    }

    /**
     * Record the outcome of a handshake.
     *
     * @param string $peer_id
     * @param array $handshake
     * @param int $clock_skew
     * @return void
     */
    public static function record_contact($peer_id, array $handshake, $clock_skew = 0)
    {
        global $wpdb;

        $encoded = Fsync_Utils::encode($handshake);

        $wpdb->update(
            Fsync_Schema::table('peers'),
            array(
                'handshake' => is_wp_error($encoded) ? '{}' : $encoded,
                'last_contact_at' => Fsync_Utils::now(),
                'clock_skew' => (int) $clock_skew,
            ),
            array('peer_id' => (string) $peer_id)
        );
    }

    /**
     * @param string $peer_id
     * @return void
     */
    public static function forget($peer_id)
    {
        global $wpdb;

        $wpdb->delete(Fsync_Schema::table('peers'), array('peer_id' => (string) $peer_id));

        Fsync_Log::warning(
            'peer_removed',
            sprintf('ピアを削除しました: %s', $peer_id),
            array('peer_id' => (string) $peer_id)
        );
    }

    /**
     * @param mixed $env_name
     * @return string|WP_Error
     */
    public static function normalize_env_name($env_name)
    {
        $env_name = strtolower(trim((string) $env_name));

        if ($env_name === '' || ! preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $env_name)) {
            return new WP_Error(
                'fsync_env_name_invalid',
                '環境名は英数字・ハイフン・アンダースコアのみ使用できます。'
            );
        }

        return $env_name;
    }

    /**
     * @param array $row
     * @return array
     */
    private static function shape($row)
    {
        $handshake = json_decode((string) $row['handshake'], true);

        return array(
            'peer_id' => (string) $row['peer_id'],
            'env_name' => (string) $row['env_name'],
            'site_role' => (string) $row['site_role'],
            'url' => (string) $row['url'],
            'outbound_key_id' => (string) $row['outbound_key_id'],
            'scope_fingerprint' => (string) $row['scope_fingerprint'],
            'handshake' => is_array($handshake) ? $handshake : array(),
            'last_contact_at' => (int) $row['last_contact_at'],
            'clock_skew' => (int) $row['clock_skew'],
            'status' => (string) $row['status'],
            'created_at' => (int) $row['created_at'],
        );
    }
}
