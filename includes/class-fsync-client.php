<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Signed HTTP client for talking to a peer.
 *
 * Requests address the REST API as ?rest_route=/flares-sync/v1/... rather than
 * /wp-json/..., because the pretty-permalink form depends on rewrite rules that
 * are frequently unavailable on the shared hosting this plugin targets. The
 * signature covers the route rather than the URL path, so both forms sign
 * identically.
 */
final class Fsync_Client
{
    /** @var string */
    private $base_url;

    /** @var string */
    private $key_id;

    /** @var string */
    private $secret;

    /** @var string */
    private $algorithm;

    /** @var array|null */
    private $peer;

    /**
     * @param array $peer Row from Fsync_Peer.
     * @return self|WP_Error
     */
    public static function for_peer(array $peer)
    {
        if ($peer['url'] === '') {
            return new WP_Error(
                'fsync_peer_url_missing',
                sprintf('環境「%s」の接続先URLが設定されていません。', $peer['env_name'])
            );
        }

        $secret = Fsync_Credentials::get('peer-' . $peer['env_name']);
        if (is_wp_error($secret)) {
            return $secret;
        }

        $client = new self($peer['url'], $peer['outbound_key_id'], $secret);
        $client->peer = $peer;

        return $client;
    }

    /**
     * @param string $base_url
     * @param string $key_id
     * @param string $secret
     * @param string $algorithm
     */
    public function __construct($base_url, $key_id, $secret, $algorithm = Fsync_Signer_Hmac::ALGORITHM)
    {
        $this->base_url = trailingslashit((string) $base_url);
        $this->key_id = (string) $key_id;
        $this->secret = (string) $secret;
        $this->algorithm = (string) $algorithm;
    }

    /**
     * @param string $route Route within the plugin namespace, e.g. "handshake".
     * @param array $query
     * @param int $timeout
     * @return array|WP_Error
     */
    public function get($route, $query = array(), $timeout = 30)
    {
        return $this->request('GET', $route, null, $query, $timeout);
    }

    /**
     * @param string $route
     * @param array|null $body
     * @param array $query
     * @param int $timeout
     * @return array|WP_Error
     */
    public function post($route, $body = null, $query = array(), $timeout = 60)
    {
        return $this->request('POST', $route, $body, $query, $timeout);
    }

    /**
     * @param string $method
     * @param string $route
     * @param array|null $body
     * @param array $query
     * @param int $timeout
     * @return array|WP_Error
     */
    public function request($method, $route, $body = null, $query = array(), $timeout = 60)
    {
        $full_route = '/' . FSYNC_REST_NAMESPACE . '/' . ltrim((string) $route, '/');

        $encoded_body = '';
        if ($body !== null) {
            $encoded_body = Fsync_Utils::encode($body);
            if (is_wp_error($encoded_body)) {
                return $encoded_body;
            }
        }

        $nonce = Fsync_Utils::random_hex(16);
        if (is_wp_error($nonce)) {
            return $nonce;
        }

        $parts = array(
            'method' => $method,
            'route' => $full_route,
            'query' => $query,
            'body' => $encoded_body,
            'timestamp' => Fsync_Utils::now(),
            'nonce' => $nonce,
            'key_id' => $this->key_id,
        );

        $canonical = Fsync_Signer::canonical($parts);
        if (is_wp_error($canonical)) {
            return $canonical;
        }

        $signature = Fsync_Signer::sign($this->algorithm, $this->secret, $canonical);
        if (is_wp_error($signature)) {
            return $signature;
        }

        $headers = array_merge(
            Fsync_Signer::headers($parts, $signature, $this->algorithm),
            array(
                'Accept' => 'application/json',
                'User-Agent' => 'Flares-Sync/' . FSYNC_VERSION,
            )
        );

        $args = array(
            'method' => $method,
            'timeout' => (int) $timeout,
            // Never follow redirects. A redirect turns a signed POST into an
            // unsigned GET and produces failures that look like anything but
            // what they are.
            'redirection' => 0,
            'sslverify' => ! Fsync_Pairing::is_local_url($this->base_url),
            'headers' => $headers,
        );

        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
            $args['body'] = $encoded_body;
            $args['data_format'] = 'body';
        }

        $response = wp_remote_request($this->endpoint($full_route, $query), $args);

        return $this->decode($response);
    }

    /**
     * @param string $full_route
     * @param array $query
     * @return string
     */
    private function endpoint($full_route, array $query)
    {
        $url = add_query_arg('rest_route', $full_route, $this->base_url);

        return $query === array() ? $url : add_query_arg($query, $url);
    }

    /**
     * @param array|WP_Error $response
     * @return array|WP_Error
     */
    private function decode($response)
    {
        if (is_wp_error($response)) {
            return new WP_Error(
                'fsync_network_error',
                sprintf('接続に失敗しました: %s', $response->get_error_message()),
                array('retryable' => true)
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);

        // Record the peer's clock on every response, not only on failure, so
        // that drift is visible before it starts rejecting requests.
        $server_time = (int) wp_remote_retrieve_header($response, strtolower(Fsync_Signer::HEADER_SERVER_TIME));
        if ($server_time > 0 && $this->peer !== null) {
            Fsync_Peer::record_contact(
                $this->peer['peer_id'],
                is_array($decoded) ? $decoded : array(),
                $server_time - Fsync_Utils::now()
            );
        }

        if ($code >= 300 || $code === 0) {
            if ($code >= 300 && $code < 400) {
                return new WP_Error(
                    'fsync_unexpected_redirect',
                    sprintf(
                        '接続先がリダイレクト(HTTP %d)を返しました。接続先URLがサイトの正規URLと一致しているか確認してください。',
                        $code
                    ),
                    array('status' => $code)
                );
            }

            $message = is_array($decoded) && ! empty($decoded['message'])
                ? (string) $decoded['message']
                : sprintf('接続先がHTTP %d を返しました。', $code);

            $code_string = is_array($decoded) && ! empty($decoded['code'])
                ? (string) $decoded['code']
                : 'fsync_remote_error';

            $data = is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])
                ? $decoded['data']
                : array();
            $data['status'] = $code;

            return new WP_Error($code_string, $message, $data);
        }

        if (! is_array($decoded)) {
            return new WP_Error(
                'fsync_invalid_response',
                '接続先の応答をJSONとして解析できません。プラグインが有効か、別のプラグインが出力を混入していないか確認してください。',
                array('body' => substr($raw, 0, 500))
            );
        }

        return $decoded;
    }

    /**
     * Unauthenticated header diagnostic.
     *
     * Separate from request() precisely because it must work when signing
     * cannot: its purpose is to identify a host that strips our headers.
     *
     * @return array|WP_Error
     */
    public function echo_test()
    {
        $route = '/' . FSYNC_REST_NAMESPACE . '/echo';

        $nonce = Fsync_Utils::random_hex(16);
        if (is_wp_error($nonce)) {
            return $nonce;
        }

        $parts = array(
            'method' => 'GET',
            'route' => $route,
            'query' => array(),
            'body' => '',
            'timestamp' => Fsync_Utils::now(),
            'nonce' => $nonce,
            'key_id' => $this->key_id === '' ? 'diagnostic' : $this->key_id,
        );

        $response = wp_remote_get(
            $this->endpoint($route, array()),
            array(
                'timeout' => 20,
                'redirection' => 0,
                'sslverify' => ! Fsync_Pairing::is_local_url($this->base_url),
                'headers' => array_merge(
                    Fsync_Signer::headers($parts, str_repeat('0', 64), $this->algorithm),
                    array('Accept' => 'application/json')
                ),
            )
        );

        return $this->decode($response);
    }
}
