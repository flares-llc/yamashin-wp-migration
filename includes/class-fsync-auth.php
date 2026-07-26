<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Authentication gate for every REST route.
 *
 * Two independent switches must both be on for a request to be accepted: the
 * site must have explicitly opted in to acting as a receiver, and the key must
 * carry the capability the route requires.
 *
 * The receiver switch is deliberately NOT part of the JSON configuration
 * document. Configuration is authored by an agent and committed to a
 * repository, and a file that travels between environments must not be able to
 * turn a site into a write target. Enabling reception stays a local, deliberate
 * action taken in the admin screen of the site being enabled.
 */
final class Fsync_Auth
{
    const OPTION_RECEIVER_ENABLED = 'fsync_receiver_enabled';
    const OPTION_TRUSTED_PROXIES = 'fsync_trusted_proxies';

    /** @var array|null Key row for the request currently being served. */
    private static $current = null;

    /**
     * Build a permission_callback for a route.
     *
     * @param string $capability
     * @return callable
     */
    public static function require_capability($capability)
    {
        return static function ($request) use ($capability) {
            return Fsync_Auth::authorize($request, $capability);
        };
    }

    /**
     * Authenticate and authorize a request.
     *
     * @param WP_REST_Request $request
     * @param string $capability
     * @return true|WP_Error
     */
    public static function authorize($request, $capability)
    {
        $result = self::run_checks($request, $capability);

        if (is_wp_error($result)) {
            Fsync_Log::warning(
                'auth_denied',
                $result->get_error_message(),
                array(
                    'key_id' => (string) $request->get_header(Fsync_Signer::HEADER_KEY),
                    'route' => (string) $request->get_route(),
                    'verdict' => $result->get_error_code(),
                    'ip' => self::client_ip(),
                    'data' => array('capability' => $capability),
                )
            );

            return $result;
        }

        return true;
    }

    /**
     * The ordered checks. Split out so that authorize() owns logging and this
     * reads as a plain list of conditions.
     *
     * @param WP_REST_Request $request
     * @param string $capability
     * @return true|WP_Error
     */
    private static function run_checks($request, $capability)
    {
        if (! self::receiver_enabled()) {
            return new WP_Error(
                'fsync_receiver_disabled',
                'このサイトは受信を許可していません。管理画面で受信を有効にしてください。',
                array('status' => 403)
            );
        }

        $key_id = trim((string) $request->get_header(Fsync_Signer::HEADER_KEY));
        if ($key_id === '') {
            return new WP_Error(
                'fsync_key_header_missing',
                sprintf(
                    '%s ヘッダーがありません。サーバーがヘッダーを削除している可能性があります。接続診断を実行してください。',
                    Fsync_Signer::HEADER_KEY
                ),
                array('status' => 401)
            );
        }

        $key = Fsync_Keys::find($key_id);
        if ($key === null) {
            return new WP_Error('fsync_key_unknown', '接続キーが見つかりません。', array('status' => 401));
        }

        $usable = Fsync_Keys::usable($key);
        if (is_wp_error($usable)) {
            return $usable;
        }

        $timestamp = (int) $request->get_header(Fsync_Signer::HEADER_TIMESTAMP);
        $clock = Fsync_Signer::check_timestamp($timestamp);
        if (is_wp_error($clock)) {
            return $clock;
        }

        $secret = Fsync_Keys::secret($key_id);
        if (is_wp_error($secret)) {
            return $secret;
        }

        $nonce = (string) $request->get_header(Fsync_Signer::HEADER_NONCE);
        $algorithm = (string) $request->get_header(Fsync_Signer::HEADER_ALGORITHM);

        $canonical = Fsync_Signer::canonical(
            array(
                'method' => (string) $request->get_method(),
                'route' => (string) $request->get_route(),
                'query' => (array) $request->get_query_params(),
                'body' => (string) $request->get_body(),
                'timestamp' => $timestamp,
                'nonce' => $nonce,
                'key_id' => $key_id,
            )
        );

        if (is_wp_error($canonical)) {
            return new WP_Error(
                'fsync_signature_incomplete',
                '署名に必要なヘッダーが不足しています。',
                array('status' => 401)
            );
        }

        $signature = (string) $request->get_header(Fsync_Signer::HEADER_SIGNATURE);
        $algorithm = $algorithm === '' ? $key['algorithm'] : $algorithm;

        if (! Fsync_Signer::verify($algorithm, $secret, $canonical, $signature)) {
            return new WP_Error('fsync_signature_invalid', '署名が一致しません。', array('status' => 401));
        }

        // Only after the signature is proven valid. Consuming a nonce before
        // that would let an unauthenticated caller fill the table and, worse,
        // burn the nonce of a legitimate request it captured.
        $remembered = Fsync_Nonce_Store::remember($key_id, $nonce);
        if (is_wp_error($remembered)) {
            return $remembered;
        }

        if (! Fsync_Keys::can($key, $capability)) {
            return new WP_Error(
                'fsync_capability_missing',
                sprintf('この接続キーには「%s」の権限がありません。', Fsync_Keys::CAPABILITIES[$capability] ?? $capability),
                array('status' => 403)
            );
        }

        $allowed = self::check_ip($key);
        if (is_wp_error($allowed)) {
            return $allowed;
        }

        self::$current = $key;
        Fsync_Keys::touch($key_id);

        return true;
    }

    /**
     * Key row for the request being served, once authorized.
     *
     * @return array|null
     */
    public static function current_key()
    {
        return self::$current;
    }

    /**
     * @return bool
     */
    public static function receiver_enabled()
    {
        return (bool) get_option(self::OPTION_RECEIVER_ENABLED, false);
    }

    /**
     * @param bool $enabled
     * @return void
     */
    public static function set_receiver_enabled($enabled)
    {
        update_option(self::OPTION_RECEIVER_ENABLED, (bool) $enabled, false);

        Fsync_Log::warning(
            $enabled ? 'receiver_enabled' : 'receiver_disabled',
            $enabled ? '受信を有効にしました。' : '受信を無効にしました。'
        );
    }

    /**
     * @param array $key
     * @return true|WP_Error
     */
    private static function check_ip(array $key)
    {
        if ($key['ip_allowlist'] === array()) {
            return true;
        }

        $ip = self::client_ip();
        foreach ($key['ip_allowlist'] as $allowed) {
            if (self::ip_matches($ip, $allowed)) {
                return true;
            }
        }

        return new WP_Error(
            'fsync_ip_denied',
            sprintf('この接続元IPは許可されていません: %s', $ip),
            array('status' => 403)
        );
    }

    /**
     * Client IP address.
     *
     * X-Forwarded-For is only consulted when the operator has explicitly
     * declared which proxies are trusted. Honouring it unconditionally would
     * let any caller forge both the audit trail and the IP allowlist by
     * setting a header.
     *
     * @return string
     */
    public static function client_ip()
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        $trusted = (array) get_option(self::OPTION_TRUSTED_PROXIES, array());
        if ($trusted === array() || $remote === '') {
            return $remote;
        }

        $is_trusted = false;
        foreach ($trusted as $cidr) {
            if (self::ip_matches($remote, (string) $cidr)) {
                $is_trusted = true;
                break;
            }
        }

        if (! $is_trusted) {
            return $remote;
        }

        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded === '') {
            return $remote;
        }

        // The left-most entry is the original client; everything after it was
        // appended by intermediaries.
        $parts = array_map('trim', explode(',', $forwarded));
        $candidate = $parts[0] ?? '';

        return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : $remote;
    }

    /**
     * Match an address against a literal IP or a CIDR range.
     *
     * @param string $ip
     * @param string $pattern
     * @return bool
     */
    public static function ip_matches($ip, $pattern)
    {
        $ip = trim((string) $ip);
        $pattern = trim((string) $pattern);

        if ($ip === '' || $pattern === '') {
            return false;
        }

        if (strpos($pattern, '/') === false) {
            return $ip === $pattern;
        }

        list($subnet, $bits) = explode('/', $pattern, 2);
        $bits = (int) $bits;

        $ip_packed = @inet_pton($ip);
        $subnet_packed = @inet_pton($subnet);

        if ($ip_packed === false || $subnet_packed === false) {
            return false;
        }

        // Mixing IPv4 and IPv6 would compare unrelated byte strings.
        if (strlen($ip_packed) !== strlen($subnet_packed)) {
            return false;
        }

        $max_bits = strlen($ip_packed) * 8;
        if ($bits < 0 || $bits > $max_bits) {
            return false;
        }

        $whole_bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($whole_bytes > 0 && strncmp($ip_packed, $subnet_packed, $whole_bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainder) & 0xFF;

        return (ord($ip_packed[$whole_bytes]) & $mask) === (ord($subnet_packed[$whole_bytes]) & $mask);
    }
}
