<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The configuration authoring API.
 *
 * These four routes are the loop an agent runs without ever opening the admin
 * screen: read what the site actually contains, fetch a schema constrained to
 * it, submit a document, and get back either precise locations to fix or a
 * summary of what applying it would change.
 */
final class Fsync_Rest_Config
{
    /**
     * @return void
     */
    public static function register_routes()
    {
        register_rest_route(
            FSYNC_REST_NAMESPACE,
            '/config',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => [self::class, 'read'],
                'permission_callback' => Fsync_Rest::admin_or_capability('status'),
            )
        );

        register_rest_route(
            FSYNC_REST_NAMESPACE,
            '/config/schema',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => [self::class, 'schema'],
                'permission_callback' => Fsync_Rest::admin_or_capability('status'),
            )
        );

        register_rest_route(
            FSYNC_REST_NAMESPACE,
            '/config/introspect',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => [self::class, 'introspect'],
                'permission_callback' => Fsync_Rest::admin_or_capability('read'),
                'args' => array(
                    'include_meta_keys' => array('type' => 'boolean', 'default' => true),
                    'include_options' => array('type' => 'boolean', 'default' => true),
                    'include_users' => array('type' => 'boolean', 'default' => true),
                ),
            )
        );

        register_rest_route(
            FSYNC_REST_NAMESPACE,
            '/config/validate',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'validate'],
                'permission_callback' => Fsync_Rest::admin_or_capability('status'),
            )
        );

        register_rest_route(
            FSYNC_REST_NAMESPACE,
            '/config/apply',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'apply'],
                'permission_callback' => Fsync_Rest::admin_or_capability('admin'),
            )
        );

        register_rest_route(
            FSYNC_REST_NAMESPACE,
            '/config/history',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => [self::class, 'history'],
                'permission_callback' => Fsync_Rest::admin_or_capability('admin'),
            )
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function read($request)
    {
        $loaded = Fsync_Config_Io::load();

        return Fsync_Rest::respond(
            array(
                'ok' => true,
                'source' => $loaded['source'],
                'path' => $loaded['path'],
                'file_backed' => Fsync_Config_Io::is_file_backed(),
                'error' => $loaded['error'] === null ? null : $loaded['error']->get_error_message(),
                'document' => $loaded['document'],
                'effective' => Fsync_Config::document(),
                'active_env' => Fsync_Config_Io::active_env(),
                'scope_fingerprints' => self::fingerprints(),
            )
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function schema($request)
    {
        return Fsync_Rest::respond(Fsync_Config_Schema::generate());
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function introspect($request)
    {
        return Fsync_Rest::respond(
            Fsync_Introspect::report(
                array(
                    'include_meta_keys' => (bool) $request->get_param('include_meta_keys'),
                    'include_options' => (bool) $request->get_param('include_options'),
                    'include_users' => (bool) $request->get_param('include_users'),
                )
            )
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function validate($request)
    {
        $document = self::document_from($request);
        if (is_wp_error($document)) {
            return Fsync_Rest::error($document);
        }

        $result = Fsync_Config_Validate::check($document, self::validation_context());

        return Fsync_Rest::respond(
            array(
                'ok' => $result['ok'],
                'errors' => $result['errors'],
                'warnings' => $result['warnings'],
                'error_count' => count($result['errors']),
                'warning_count' => count($result['warnings']),
            )
        );
    }

    /**
     * Validate, then store, reporting what changed.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function apply($request)
    {
        $document = self::document_from($request);
        if (is_wp_error($document)) {
            return Fsync_Rest::error($document);
        }

        $result = Fsync_Config_Validate::check($document, self::validation_context());

        if (! $result['ok']) {
            return Fsync_Rest::error(
                new WP_Error(
                    'fsync_config_invalid',
                    sprintf('設定に%d件のエラーがあります。', count($result['errors'])),
                    array('status' => 422, 'errors' => $result['errors'], 'warnings' => $result['warnings'])
                )
            );
        }

        $before = Fsync_Config::document();

        $saved = Fsync_Config_Io::save($document, (string) $request->get_param('note'));
        if (is_wp_error($saved)) {
            return Fsync_Rest::error($saved);
        }

        Fsync_Config::flush();
        $after = Fsync_Config::document();

        Fsync_Log::info('config_applied', '設定を適用しました。');

        return Fsync_Rest::respond(
            array(
                'ok' => true,
                'warnings' => $result['warnings'],
                'changes' => self::diff($before, $after),
                'scope_fingerprints' => self::fingerprints(),
            )
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function history($request)
    {
        return Fsync_Rest::respond(
            array(
                'ok' => true,
                'entries' => Fsync_Config_Io::history((int) ($request->get_param('limit') ?: 20)),
            )
        );
    }

    /**
     * Accept either a parsed object or a raw JSONC string, because an agent
     * writing a file naturally has the latter.
     *
     * @param WP_REST_Request $request
     * @return array|WP_Error
     */
    private static function document_from($request)
    {
        $raw = $request->get_param('raw');
        if (is_string($raw) && $raw !== '') {
            return Fsync_Config_Io::parse($raw);
        }

        $document = $request->get_param('document');
        if (is_array($document)) {
            return $document;
        }

        return new WP_Error(
            'fsync_config_missing',
            'document（オブジェクト）または raw（JSONC文字列）を指定してください。',
            array('status' => 400)
        );
    }

    /**
     * @return array
     */
    private static function validation_context()
    {
        return array(
            'introspect' => Fsync_Introspect::report(
                array('include_meta_keys' => false, 'include_options' => false, 'include_users' => false)
            ),
            'credentials' => array_column(Fsync_Credentials::all(), 'credential_id'),
        );
    }

    /**
     * Fingerprints per configured environment, so a mismatch between two sites
     * can be seen without running a sync.
     *
     * @return array<string, string>
     */
    private static function fingerprints()
    {
        $out = array();

        foreach (array_keys(Fsync_Config::environments()) as $env_name) {
            $fingerprint = Fsync_Config::scope_fingerprint((string) $env_name);
            $out[(string) $env_name] = is_wp_error($fingerprint) ? '' : $fingerprint;
        }

        return $out;
    }

    /**
     * Shallow report of which top-level sections changed.
     *
     * @param array $before
     * @param array $after
     * @return array<int, string>
     */
    private static function diff(array $before, array $after)
    {
        $changed = array();

        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $left = Fsync_Utils::canonical_hash($before[$key] ?? null);
            $right = Fsync_Utils::canonical_hash($after[$key] ?? null);

            if (is_wp_error($left) || is_wp_error($right) || $left !== $right) {
                $changed[] = (string) $key;
            }
        }

        return $changed;
    }
}
