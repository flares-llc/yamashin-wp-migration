<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Request signing: canonical string construction and algorithm dispatch.
 *
 * WARNING: the canonical string is a wire protocol. Changing its shape breaks
 * every existing pairing, because both sides must build byte-identical input.
 * A change therefore requires bumping FSYNC_PROTOCOL, which is included as the
 * first line so that old and new signatures can never collide.
 *
 * Two deliberate choices:
 *
 * - The Authorization header is not used. Some shared hosts and PHP-CGI setups
 *   strip it before PHP sees it, producing a 401 with no diagnosable cause. Our
 *   own X-Fsync-* headers can be stripped too, but /status echoes back which
 *   headers actually arrived, so the failure is identifiable.
 *
 * - The path component is the REST route, not the URL path. Requests are sent
 *   as ?rest_route=/flares-sync/v1/... so they work without pretty permalinks,
 *   which means the URL path is /index.php on one site and /flares-sync/v1/...
 *   on another. Signing the route keeps both sides in agreement.
 */
final class Fsync_Signer
{
    const HEADER_KEY = 'X-Fsync-Key';
    const HEADER_TIMESTAMP = 'X-Fsync-Timestamp';
    const HEADER_NONCE = 'X-Fsync-Nonce';
    const HEADER_ALGORITHM = 'X-Fsync-Alg';
    const HEADER_SIGNATURE = 'X-Fsync-Signature';
    const HEADER_SOURCE = 'X-Fsync-Source';
    const HEADER_SERVER_TIME = 'X-Fsync-Server-Time';

    /**
     * How far a peer's clock may differ from ours, in seconds.
     *
     * Generous enough to survive an unsynchronised VPS, tight enough that a
     * captured request is not replayable for long. Paired with a nonce store,
     * this window is the only period in which a replay could be attempted.
     */
    const CLOCK_SKEW_TOLERANCE = 300;

    /** Query parameter used to address the REST route without permalinks. */
    const ROUTE_PARAM = 'rest_route';

    /**
     * Query parameters excluded from the signature.
     *
     * rest_route is excluded because it duplicates the route, which is already
     * signed, and because WordPress moves it between the query string and the
     * route depending on permalink configuration.
     */
    const UNSIGNED_QUERY_PARAMS = array(self::ROUTE_PARAM);

    /**
     * Build the canonical string that gets signed.
     *
     * @param array{method: string, route: string, query: array, body: string,
     *              timestamp: int, nonce: string, key_id: string} $parts
     * @return string|WP_Error
     */
    public static function canonical(array $parts)
    {
        foreach (array('method', 'route', 'timestamp', 'nonce', 'key_id') as $required) {
            if (! isset($parts[$required]) || (string) $parts[$required] === '') {
                return new WP_Error(
                    'fsync_canonical_incomplete',
                    sprintf('署名対象の要素が不足しています: %s', $required)
                );
            }
        }

        return implode(
            "\n",
            array(
                FSYNC_PROTOCOL,
                strtoupper((string) $parts['method']),
                self::normalize_route((string) $parts['route']),
                self::normalize_query((array) ($parts['query'] ?? array())),
                self::body_hash((string) ($parts['body'] ?? '')),
                (string) (int) $parts['timestamp'],
                (string) $parts['nonce'],
                (string) $parts['key_id'],
            )
        );
    }

    /**
     * Canonical form of a REST route: leading slash, no trailing slash.
     *
     * @param string $route
     * @return string
     */
    public static function normalize_route($route)
    {
        $route = '/' . trim(str_replace('\\', '/', (string) $route), '/');

        return (string) preg_replace('#/+#', '/', $route);
    }

    /**
     * Deterministic rendering of query parameters.
     *
     * Sorted by key then value so that parameter order in the URL cannot change
     * the signature, and repeated keys are handled without ambiguity.
     *
     * @param array $query
     * @return string
     */
    public static function normalize_query(array $query)
    {
        $pairs = array();

        foreach ($query as $key => $value) {
            $key = (string) $key;
            if (in_array($key, self::UNSIGNED_QUERY_PARAMS, true)) {
                continue;
            }

            foreach ((array) $value as $item) {
                if (is_bool($item)) {
                    $item = $item ? 'true' : 'false';
                }

                $pairs[] = rawurlencode($key) . '=' . rawurlencode((string) $item);
            }
        }

        sort($pairs, SORT_STRING);

        return implode('&', $pairs);
    }

    /**
     * @param string $body Raw request body bytes.
     * @return string 64 lowercase hex characters.
     */
    public static function body_hash($body)
    {
        return hash('sha256', (string) $body);
    }

    /**
     * @param string $algorithm
     * @return string|WP_Error Class name implementing sign()/verify().
     */
    public static function implementation($algorithm)
    {
        $algorithm = strtolower(trim((string) $algorithm));

        if ($algorithm === '' || $algorithm === Fsync_Signer_Hmac::ALGORITHM) {
            return 'Fsync_Signer_Hmac';
        }

        return new WP_Error(
            'fsync_algorithm_unsupported',
            sprintf('未対応の署名アルゴリズムです: %s', $algorithm)
        );
    }

    /**
     * @param string $algorithm
     * @param string $secret
     * @param string $canonical
     * @return string|WP_Error
     */
    public static function sign($algorithm, $secret, $canonical)
    {
        $class = self::implementation($algorithm);
        if (is_wp_error($class)) {
            return $class;
        }

        return call_user_func(array($class, 'sign'), $secret, $canonical);
    }

    /**
     * @param string $algorithm
     * @param string $secret
     * @param string $canonical
     * @param string $signature
     * @return bool
     */
    public static function verify($algorithm, $secret, $canonical, $signature)
    {
        $class = self::implementation($algorithm);
        if (is_wp_error($class)) {
            return false;
        }

        return (bool) call_user_func(array($class, 'verify'), $secret, $canonical, $signature);
    }

    /**
     * Validate a timestamp against our clock.
     *
     * @param int $timestamp
     * @param int|null $now
     * @return true|WP_Error Error data carries the measured skew so the caller
     *                       can report the actual number of seconds.
     */
    public static function check_timestamp($timestamp, $now = null)
    {
        $now = $now === null ? Fsync_Utils::now() : (int) $now;
        $skew = (int) $timestamp - $now;

        if (abs($skew) > self::CLOCK_SKEW_TOLERANCE) {
            return new WP_Error(
                'fsync_clock_skew',
                sprintf(
                    'リクエストの時刻が%d秒ずれています。許容範囲は±%d秒です。サーバーの時刻設定を確認してください。',
                    $skew,
                    self::CLOCK_SKEW_TOLERANCE
                ),
                array('skew' => $skew, 'server_time' => $now, 'status' => 401)
            );
        }

        return true;
    }

    /**
     * Headers a client must send, given a prepared canonical input.
     *
     * @param array $parts Same shape as canonical().
     * @param string $signature
     * @param string $algorithm
     * @return array<string, string>
     */
    public static function headers(array $parts, $signature, $algorithm = Fsync_Signer_Hmac::ALGORITHM)
    {
        return array(
            self::HEADER_KEY => (string) $parts['key_id'],
            self::HEADER_TIMESTAMP => (string) (int) $parts['timestamp'],
            self::HEADER_NONCE => (string) $parts['nonce'],
            self::HEADER_ALGORITHM => $algorithm,
            self::HEADER_SIGNATURE => $signature,
            self::HEADER_SOURCE => home_url('/'),
        );
    }

    /**
     * Header names that must survive the trip, for the diagnostics screen.
     *
     * @return array<int, string>
     */
    public static function required_headers()
    {
        return array(
            self::HEADER_KEY,
            self::HEADER_TIMESTAMP,
            self::HEADER_NONCE,
            self::HEADER_ALGORITHM,
            self::HEADER_SIGNATURE,
        );
    }
}
