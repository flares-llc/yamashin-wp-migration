<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pairing confirmation and key lifecycle.
 */
final class Fsync_Rest_Keys
{
    /**
     * @return void
     */
    public static function register_routes()
    {
        // Confirmation is authenticated by the very key being confirmed: the
        // request must already carry a valid signature made with the secret
        // from the pairing blob. Possession is therefore proven by use, and a
        // blob that was copied incorrectly fails here rather than appearing to
        // work and breaking later.
        register_rest_route(
            FSYNC_REST_NAMESPACE,
            '/pair/confirm',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'confirm'],
                'permission_callback' => [self::class, 'confirm_permission'],
            )
        );

        register_rest_route(
            FSYNC_REST_NAMESPACE,
            '/keys',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => [self::class, 'index'],
                'permission_callback' => Fsync_Rest::admin_or_capability('admin'),
            )
        );

        register_rest_route(
            FSYNC_REST_NAMESPACE,
            '/keys/(?P<key_id>[a-f0-9]{4,32})/retire',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'retire'],
                'permission_callback' => Fsync_Rest::admin_or_capability('admin'),
            )
        );
    }

    /**
     * A pending key cannot pass the normal gate, which requires an active key,
     * so confirmation performs the same checks with the pending state allowed.
     *
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public static function confirm_permission($request)
    {
        if (! Fsync_Auth::receiver_enabled()) {
            return new WP_Error(
                'fsync_receiver_disabled',
                'このサイトは受信を許可していません。',
                array('status' => 403)
            );
        }

        $key_id = trim((string) $request->get_header(Fsync_Signer::HEADER_KEY));
        $key = $key_id === '' ? null : Fsync_Keys::find($key_id);

        if ($key === null) {
            return new WP_Error('fsync_key_unknown', '接続キーが見つかりません。', array('status' => 401));
        }

        if ($key['status'] !== Fsync_Keys::STATUS_PENDING) {
            return new WP_Error(
                'fsync_pairing_consumed',
                'このペアリングは既に完了しています。',
                array('status' => 409)
            );
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

        $canonical = Fsync_Signer::canonical(
            array(
                'method' => (string) $request->get_method(),
                'route' => (string) $request->get_route(),
                'query' => (array) $request->get_query_params(),
                'body' => (string) $request->get_body(),
                'timestamp' => $timestamp,
                'nonce' => (string) $request->get_header(Fsync_Signer::HEADER_NONCE),
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

        $algorithm = (string) $request->get_header(Fsync_Signer::HEADER_ALGORITHM);
        $algorithm = $algorithm === '' ? $key['algorithm'] : $algorithm;

        if (
            ! Fsync_Signer::verify(
                $algorithm,
                $secret,
                $canonical,
                (string) $request->get_header(Fsync_Signer::HEADER_SIGNATURE)
            )
        ) {
            Fsync_Log::warning(
                'pairing_signature_invalid',
                'ペアリング確認の署名が一致しませんでした。',
                array('key_id' => $key_id, 'ip' => Fsync_Auth::client_ip())
            );

            return new WP_Error('fsync_signature_invalid', '署名が一致しません。', array('status' => 401));
        }

        $remembered = Fsync_Nonce_Store::remember(
            $key_id,
            (string) $request->get_header(Fsync_Signer::HEADER_NONCE)
        );
        if (is_wp_error($remembered)) {
            return $remembered;
        }

        // The initial confirmation must obey the same source-IP restriction
        // as every request made after activation. Otherwise a stolen blob can
        // be consumed from an address the operator explicitly denied.
        return Fsync_Auth::check_ip($key);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function confirm($request)
    {
        $key_id = (string) $request->get_header(Fsync_Signer::HEADER_KEY);

        $result = Fsync_Pairing::confirm(
            $key_id,
            array(
                'env_name' => (string) $request->get_param('env_name'),
                'site_role' => (string) $request->get_param('site_role'),
                'url' => (string) $request->get_param('url'),
            )
        );

        if (is_wp_error($result)) {
            return Fsync_Rest::error($result);
        }

        $environment = Fsync_Env::report();

        return Fsync_Rest::respond(
            array(
                'ok' => true,
                'peer_id' => $result['peer_id'],
                'env_name' => Fsync_Config_Io::active_env(),
                'site_role' => (string) get_option('fsync_site_role', ''),
                'capabilities' => $result['capabilities'],
                'protocol' => FSYNC_PROTOCOL,
                'plugin_version' => FSYNC_VERSION,
                'hash_algo_version' => FSYNC_HASH_ALGO_VERSION,
                'limits' => $environment['limits'],
                'site' => $environment['site'],
            )
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function index($request)
    {
        return Fsync_Rest::respond(
            array(
                'ok' => true,
                'keys' => Fsync_Keys::all(),
                'capability_labels' => Fsync_Keys::CAPABILITIES,
            )
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function retire($request)
    {
        $retired = Fsync_Keys::retire((string) $request->get_param('key_id'));
        if (is_wp_error($retired)) {
            return Fsync_Rest::error($retired);
        }

        return Fsync_Rest::respond(array('ok' => true));
    }
}
