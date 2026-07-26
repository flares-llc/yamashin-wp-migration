<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * HMAC-SHA256 request signing.
 *
 * The shared secret stays in both databases and never travels on the wire, so
 * a captured request reveals nothing reusable beyond its own replay window --
 * and the nonce store closes that.
 *
 * Fsync_Signer is written so an Ed25519 implementation can be dropped in later
 * without touching the canonical string: with public key signing the receiver
 * would hold only a public key, so a leaked production database would not
 * yield anything that can sign new requests.
 */
final class Fsync_Signer_Hmac
{
    const ALGORITHM = 'hmac-sha256';
    const HASH = 'sha256';

    /**
     * @param string $secret Raw shared secret.
     * @param string $canonical
     * @return string|WP_Error Lowercase hex.
     */
    public static function sign($secret, $canonical)
    {
        if ((string) $secret === '') {
            return new WP_Error('fsync_secret_missing', '署名用のシークレットが設定されていません。');
        }

        return hash_hmac(self::HASH, (string) $canonical, (string) $secret);
    }

    /**
     * @param string $secret
     * @param string $canonical
     * @param string $signature
     * @return bool
     */
    public static function verify($secret, $canonical, $signature)
    {
        $expected = self::sign($secret, $canonical);
        if (is_wp_error($expected)) {
            return false;
        }

        // Compare in constant time. A naive === leaks the position of the first
        // differing byte through timing, which is enough to forge a signature
        // one byte at a time given enough attempts.
        return Fsync_Utils::hash_equals($expected, (string) $signature);
    }
}
