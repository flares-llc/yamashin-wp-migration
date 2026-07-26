<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Handshake, status and connection diagnostics.
 */
final class Fsync_Rest_Status
{
    /**
     * @return void
     */
    public static function register_routes()
    {
        register_rest_route(
            FSYNC_REST_NAMESPACE,
            '/handshake',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => [self::class, 'handshake'],
                'permission_callback' => Fsync_Auth::require_capability('status'),
            )
        );

        register_rest_route(
            FSYNC_REST_NAMESPACE,
            '/status',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => [self::class, 'status'],
                'permission_callback' => Fsync_Rest::admin_or_capability('status'),
            )
        );

        // Deliberately unauthenticated. Its entire purpose is to diagnose the
        // case where authentication cannot succeed because a proxy, WAF or
        // PHP-CGI setup is stripping our headers before PHP sees them -- a
        // failure that is otherwise an undiagnosable 401. It reveals only which
        // header NAMES arrived and the server clock: no values, and nothing an
        // unauthenticated caller could not already infer.
        register_rest_route(
            FSYNC_REST_NAMESPACE,
            '/echo',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => [self::class, 'echo_headers'],
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Everything a peer needs in order to negotiate a run.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handshake($request)
    {
        $environment = Fsync_Env::report();
        $key = Fsync_Auth::current_key();
        $env_name = Fsync_Config_Io::active_env();

        return Fsync_Rest::respond(
            array(
                'ok' => true,
                'protocol' => FSYNC_PROTOCOL,
                'plugin_version' => FSYNC_VERSION,
                'schema_version' => FSYNC_SCHEMA_VERSION,
                'hash_algo_version' => FSYNC_HASH_ALGO_VERSION,
                'env_name' => $env_name,
                'site_role' => (string) get_option('fsync_site_role', ''),
                'site' => $environment['site'],
                'limits' => $environment['limits'],
                'caps' => $environment['caps'],
                'db' => array(
                    'charset' => $environment['db']['charset'],
                    'supports_utf8mb4' => $environment['db']['supports_utf8mb4'],
                    'server_version' => $environment['db']['server_version'],
                ),
                // Computed for the environment the caller claims to be, since
                // scope may legitimately differ per peer.
                'scope_fingerprint' => self::fingerprint_for($request),
                'capabilities' => $key === null ? array() : $key['capabilities'],
                'warnings' => Fsync_Env::warnings(),
                'blockers' => Fsync_Env::blockers(),
            )
        );
    }

    /**
     * Local health summary for the admin screens.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function status($request)
    {
        $config = Fsync_Config_Io::load();

        return Fsync_Rest::respond(
            array(
                'ok' => true,
                'receiver_enabled' => Fsync_Auth::receiver_enabled(),
                'active_env' => Fsync_Config_Io::active_env(),
                'crypto' => Fsync_Crypto::check(),
                'stale_credentials' => Fsync_Credentials::stale(),
                'nonce_store' => Fsync_Nonce_Store::status(),
                'config' => array(
                    'source' => $config['source'],
                    'path' => $config['path'],
                    'error' => $config['error'] === null ? null : $config['error']->get_error_message(),
                    'file_backed' => Fsync_Config_Io::is_file_backed(),
                ),
                'peers' => array_map(
                    static function ($peer) {
                        return array(
                            'peer_id' => $peer['peer_id'],
                            'env_name' => $peer['env_name'],
                            'url' => $peer['url'],
                            'status' => $peer['status'],
                            'last_contact_at' => $peer['last_contact_at'],
                            'clock_skew' => $peer['clock_skew'],
                        );
                    },
                    Fsync_Peer::all()
                ),
                'keys' => array_map(
                    static function ($key) {
                        return array(
                            'key_id' => $key['key_id'],
                            'label' => $key['label'],
                            'status' => $key['status'],
                            'capabilities' => $key['capabilities'],
                            'last_used_at' => $key['last_used_at'],
                        );
                    },
                    Fsync_Keys::all()
                ),
                'environment' => Fsync_Env::report(),
            )
        );
    }

    /**
     * Report which of our headers survived the trip.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function echo_headers($request)
    {
        $received = array();
        $missing = array();

        foreach (Fsync_Signer::required_headers() as $header) {
            if ((string) $request->get_header($header) !== '') {
                $received[] = $header;
                continue;
            }

            $missing[] = $header;
        }

        return Fsync_Rest::respond(
            array(
                'ok' => true,
                'protocol' => FSYNC_PROTOCOL,
                'plugin_version' => FSYNC_VERSION,
                'server_time' => Fsync_Utils::now(),
                'received_headers' => $received,
                'missing_headers' => $missing,
                'receiver_enabled' => Fsync_Auth::receiver_enabled(),
                'hint' => $missing === array()
                    ? 'すべての署名ヘッダーが到達しています。'
                    : 'ヘッダーがサーバー側で削除されています。WAFやセキュリティプラグイン、mod_security の設定を確認してください。',
            )
        );
    }

    /**
     * Scope fingerprint for the environment the caller identifies as.
     *
     * @param WP_REST_Request $request
     * @return string
     */
    private static function fingerprint_for($request)
    {
        $key = Fsync_Auth::current_key();
        $env_name = '';

        if ($key !== null && $key['peer_id'] !== '') {
            $peer = Fsync_Peer::find($key['peer_id']);
            $env_name = $peer === null ? '' : $peer['env_name'];
        }

        $fingerprint = Fsync_Config::scope_fingerprint($env_name);

        return is_wp_error($fingerprint) ? '' : $fingerprint;
    }
}
