<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Replay protection.
 *
 * A signature is only good for the clock-skew window, but within that window a
 * captured request could be replayed. Recording each nonce closes it.
 *
 * The check is an INSERT against a unique index, not a SELECT followed by an
 * INSERT. Under concurrency the read-then-write form lets two simultaneous
 * copies of the same request both observe "not seen yet" and both proceed --
 * exactly the case replay protection exists to prevent. Letting the database
 * reject the duplicate is the only version that is actually atomic.
 *
 * If the nonce table is unavailable the request is refused rather than allowed
 * through unprotected: silently degrading security is worse than failing.
 */
final class Fsync_Nonce_Store
{
    /**
     * How long a nonce is remembered. Must exceed the clock skew tolerance on
     * both sides, or a request could become replayable again before its
     * timestamp expires.
     */
    const TTL = 900;

    /** Probability, per request, of running the sweep inline. */
    const GC_CHANCE = 50;

    /**
     * Record a nonce, failing if it has been seen before.
     *
     * @param string $key_id
     * @param string $nonce
     * @return true|WP_Error
     */
    public static function remember($key_id, $nonce)
    {
        global $wpdb;

        $nonce = trim((string) $nonce);
        if ($nonce === '' || strlen($nonce) > 64 || ! preg_match('/^[A-Za-z0-9_-]+$/', $nonce)) {
            return new WP_Error('fsync_nonce_invalid', 'nonceの形式が不正です。', array('status' => 401));
        }

        // Suppress the duplicate-key warning: a collision is an expected
        // outcome here, not an error condition to be logged by wpdb.
        $suppressed = $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert(
            Fsync_Schema::table('nonces'),
            array(
                'key_id' => (string) $key_id,
                'nonce' => $nonce,
                'expires_at' => Fsync_Utils::now() + self::TTL,
            ),
            array('%s', '%s', '%d')
        );
        $insert_error = (string) $wpdb->last_error;
        $wpdb->suppress_errors($suppressed);

        if ($inserted === false) {
            // Distinguish "already used" from "the table is broken". Both are a
            // refusal, but only one of them is an attack signal.
            if (! self::table_exists()) {
                return new WP_Error(
                    'fsync_nonce_store_unavailable',
                    'リプレイ防止用のテーブルを利用できないため、リクエストを受け付けられません。',
                    array('status' => 503, 'retryable' => true)
                );
            }

            if (preg_match('/(?:duplicate\s+entry|\b1062\b)/i', $insert_error) === 1) {
                return new WP_Error(
                    'fsync_nonce_replayed',
                    'このリクエストは既に処理済みです（リプレイの可能性）。',
                    array('status' => 401)
                );
            }

            return new WP_Error(
                'fsync_nonce_store_failed',
                'リプレイ防止情報を保存できないため、リクエストを受け付けられません。',
                array('status' => 503, 'retryable' => true)
            );
        }

        if (wp_rand(1, self::GC_CHANCE) === 1) {
            self::gc();
        }

        return true;
    }

    /**
     * Delete expired nonces.
     *
     * @return int Rows removed.
     */
    public static function gc()
    {
        global $wpdb;

        $table = Fsync_Schema::table('nonces');

        return (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM {$table} WHERE expires_at < %d", Fsync_Utils::now())
        );
    }

    /**
     * @return bool
     */
    public static function table_exists()
    {
        global $wpdb;

        $table = Fsync_Schema::table('nonces');
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        return $found === $table;
    }

    /**
     * Health check for the diagnostics panel.
     *
     * @return array{ok: bool, message: string, pending: int}
     */
    public static function status()
    {
        global $wpdb;

        if (! self::table_exists()) {
            return array(
                'ok' => false,
                'message' => 'リプレイ防止用のテーブルがありません。プラグインを再有効化してください。',
                'pending' => 0,
            );
        }

        $table = Fsync_Schema::table('nonces');
        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        return array(
            'ok' => true,
            'message' => 'リプレイ防止は有効です。',
            'pending' => $pending,
        );
    }
}
