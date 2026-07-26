<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Route registration and shared response helpers.
 *
 * Controllers stay thin: they validate shape, call a service class, and shape
 * the response. No business logic lives in this layer, so that the same
 * operations can be driven locally without going through HTTP.
 */
final class Fsync_Rest
{
    /**
     * @return void
     */
    public static function register_hooks()
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    /**
     * @return void
     */
    public static function register_routes()
    {
        Fsync_Rest_Status::register_routes();
        Fsync_Rest_Config::register_routes();
        Fsync_Rest_Keys::register_routes();
    }

    /**
     * Permission callback accepting either a signed peer request or a local
     * administrator.
     *
     * Both paths are needed: the admin screens call these routes with a cookie
     * and a nonce, while an agent authoring the configuration signs with a key.
     *
     * @param string $capability
     * @return callable
     */
    public static function admin_or_capability($capability)
    {
        return static function ($request) use ($capability) {
            if (current_user_can('manage_options')) {
                return true;
            }

            return Fsync_Auth::authorize($request, $capability);
        };
    }

    /**
     * Convert a WP_Error into a response that carries its HTTP status.
     *
     * WordPress defaults an error without a status to 500, which mislabels
     * every validation failure as a server fault.
     *
     * @param WP_Error $error
     * @return WP_Error
     */
    public static function error(WP_Error $error)
    {
        $data = $error->get_error_data();
        $data = is_array($data) ? $data : array();

        if (! isset($data['status'])) {
            $data['status'] = 400;
        }

        return new WP_Error($error->get_error_code(), $error->get_error_message(), $data);
    }

    /**
     * Standard success envelope, always carrying our clock so that the caller
     * can measure skew on every response rather than only when it fails.
     *
     * @param array $payload
     * @param int $status
     * @return WP_REST_Response
     */
    public static function respond(array $payload, $status = 200)
    {
        $response = new WP_REST_Response($payload, $status);
        $response->header(Fsync_Signer::HEADER_SERVER_TIME, (string) Fsync_Utils::now());

        return $response;
    }
}
