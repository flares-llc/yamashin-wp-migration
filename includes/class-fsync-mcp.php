<?php

if (! defined('ABSPATH')) {
    exit;
}

/** Minimal standards-compliant Streamable HTTP MCP server for this site. */
final class Fsync_Mcp
{
    const PROTOCOL_VERSION = '2025-11-25';

    public static function register_routes()
    {
        register_rest_route(
            FSYNC_REST_NAMESPACE,
            '/mcp',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'handle'],
                'permission_callback' => [Fsync_Mcp_Token::class, 'authenticate'],
            )
        );
    }

    public static function handle($request)
    {
        $message = $request->get_json_params();
        if (! is_array($message) || ($message['jsonrpc'] ?? '') !== '2.0' || ! is_string($message['method'] ?? null)) {
            return self::rpc_error($message['id'] ?? null, -32600, 'Invalid Request');
        }
        $id = $message['id'] ?? null;
        $method = (string) $message['method'];
        $params = is_array($message['params'] ?? null) ? $message['params'] : array();

        if ($method === 'notifications/initialized' || strpos($method, 'notifications/') === 0) {
            $response = new WP_REST_Response(null, 202);
            $response->header('Cache-Control', 'no-store');
            $response->header('MCP-Protocol-Version', self::PROTOCOL_VERSION);

            return $response;
        }
        if ($method === 'initialize') {
            if ((string) ($params['protocolVersion'] ?? '') !== self::PROTOCOL_VERSION) {
                return self::rpc_error($id, -32602, 'Unsupported MCP protocol version. Supported: ' . self::PROTOCOL_VERSION);
            }
            return self::rpc_result(
                $id,
                array(
                    'protocolVersion' => self::PROTOCOL_VERSION,
                    'capabilities' => array(
                        'tools' => array('listChanged' => false),
                        'resources' => array('subscribe' => false, 'listChanged' => false),
                        'prompts' => array('listChanged' => false),
                    ),
                    'serverInfo' => array('name' => 'yamashin-wp-migration', 'version' => FSYNC_VERSION),
                    'instructions' => 'Always run a dry run, resolve conflicts, and verify the exact plan hash before apply or rollback.',
                )
            );
        }
        if ($method === 'ping') {
            return self::rpc_result($id, array());
        }
        if ($method === 'tools/list') {
            return self::rpc_result($id, array('tools' => self::tools()));
        }
        if ($method === 'tools/call') {
            $name = (string) ($params['name'] ?? '');
            $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : array();

            return self::rpc_result($id, self::call_tool($name, $arguments));
        }
        if ($method === 'resources/list') {
            return self::rpc_result($id, array('resources' => self::resources()));
        }
        if ($method === 'resources/read') {
            $read = self::read_resource((string) ($params['uri'] ?? ''));
            if (is_wp_error($read)) {
                return self::rpc_error($id, -32002, $read->get_error_message());
            }

            return self::rpc_result($id, array('contents' => array($read)));
        }
        if ($method === 'prompts/list') {
            return self::rpc_result($id, array('prompts' => self::prompts()));
        }
        if ($method === 'prompts/get') {
            $prompt = self::get_prompt((string) ($params['name'] ?? ''), (array) ($params['arguments'] ?? array()));
            if (is_wp_error($prompt)) {
                return self::rpc_error($id, -32602, $prompt->get_error_message());
            }

            return self::rpc_result($id, $prompt);
        }

        return self::rpc_error($id, -32601, 'Method not found');
    }

    private static function tools()
    {
        $object = array('type' => 'object', 'additionalProperties' => false, 'properties' => new stdClass());

        return array(
            self::tool('status', 'Read connection, environment and health status.', $object),
            self::tool('introspect', 'Inspect registered content, tables, files and users before authoring configuration.', array(
                'type' => 'object', 'additionalProperties' => false,
                'properties' => array(
                    'include_meta_keys' => array('type' => 'boolean'),
                    'include_options' => array('type' => 'boolean'),
                    'include_users' => array('type' => 'boolean'),
                ),
            )),
            self::tool('config_get', 'Read authored and effective configuration.', $object),
            self::tool('config_schema', 'Read the JSON Schema generated for this WordPress site.', $object),
            self::tool('config_validate', 'Validate a configuration without saving it.', array(
                'type' => 'object', 'required' => array('document'), 'additionalProperties' => false,
                'properties' => array('document' => array('type' => 'object')),
            )),
            self::tool('config_apply', 'Save a validated configuration. Requires explicit confirm=true.', array(
                'type' => 'object', 'required' => array('document', 'idempotency_key', 'confirm'), 'additionalProperties' => false,
                'properties' => array('document' => array('type' => 'object'), 'idempotency_key' => self::id_property(), 'confirm' => array('type' => 'boolean'), 'note' => array('type' => 'string')),
            )),
            self::tool('peers_list', 'List paired WordPress environments.', $object),
            self::tool('release_create', 'Start an immutable push or pull migration. Pull requires pairing in both directions.', array(
                'type' => 'object', 'required' => array('peer_id', 'idempotency_key'), 'additionalProperties' => false,
                'properties' => array(
                    'peer_id' => array('type' => 'string'),
                    'profile' => array('type' => 'string', 'enum' => array('content', 'full')),
                    'direction' => array('type' => 'string', 'enum' => array('push', 'pull')),
                    'idempotency_key' => self::id_property(),
                ),
            )),
            self::tool('release_list', 'List recent releases.', $object),
            self::tool('release_get', 'Read a release plan and its item-level diff.', self::id_schema('release_id')),
            self::tool('release_dry_run', 'Verify received objects and bind a confirmation to the exact plan.', self::idempotent_id_schema('release_id')),
            self::tool('conflicts_resolve', 'Resolve every selected conflict and issue a new exact-plan confirmation.', array(
                'type' => 'object', 'required' => array('release_id', 'plan_hash', 'resolutions', 'idempotency_key'), 'additionalProperties' => false,
                'properties' => array(
                    'release_id' => array('type' => 'string'), 'plan_hash' => array('type' => 'string'),
                    'resolutions' => array('type' => 'object', 'additionalProperties' => array('type' => 'string', 'enum' => array('source', 'target', 'skip'))), 'idempotency_key' => self::id_property(),
                ),
            )),
            self::tool('deletes_confirm', 'Confirm the exact delete set and issue a new plan confirmation.', array(
                'type' => 'object', 'required' => array('release_id', 'plan_hash', 'idempotency_key', 'confirm'), 'additionalProperties' => false,
                'properties' => array('release_id' => array('type' => 'string'), 'plan_hash' => array('type' => 'string'), 'idempotency_key' => self::id_property(), 'confirm' => array('type' => 'boolean')),
            )),
            self::tool('release_apply', 'Apply a reviewed local release. Requires plan_hash, one-time confirmation, caller-stable idempotency_key and confirm=true.', array(
                'type' => 'object', 'required' => array('release_id', 'plan_hash', 'confirmation', 'idempotency_key', 'confirm'), 'additionalProperties' => false,
                'properties' => array(
                    'release_id' => array('type' => 'string'), 'plan_hash' => array('type' => 'string'),
                    'confirmation' => array('type' => 'string'),
                    'idempotency_key' => array('type' => 'string', 'pattern' => '^[a-f0-9]{32}$'),
                    'confirm' => array('type' => 'boolean'),
                ),
            )),
            self::tool('job_get', 'Read resumable transfer or apply progress.', self::id_schema('job_id')),
            self::tool('job_continue', 'Run the next bounded job step.', self::idempotent_id_schema('job_id')),
            self::tool('job_conflicts_resolve', 'Resolve conflicts on the remote reviewed plan and rotate its confirmation.', array(
                'type' => 'object', 'required' => array('job_id', 'plan_hash', 'resolutions', 'idempotency_key'), 'additionalProperties' => false,
                'properties' => array(
                    'job_id' => array('type' => 'string'), 'plan_hash' => array('type' => 'string'),
                    'resolutions' => array('type' => 'object', 'additionalProperties' => array('type' => 'string', 'enum' => array('source', 'target', 'skip'))), 'idempotency_key' => self::id_property(),
                ),
            )),
            self::tool('job_deletes_confirm', 'Confirm the exact remote delete set and rotate its confirmation.', array(
                'type' => 'object', 'required' => array('job_id', 'plan_hash', 'idempotency_key', 'confirm'), 'additionalProperties' => false,
                'properties' => array('job_id' => array('type' => 'string'), 'plan_hash' => array('type' => 'string'), 'idempotency_key' => self::id_property(), 'confirm' => array('type' => 'boolean')),
            )),
            self::tool('job_confirm', 'Apply a reviewed remote plan after transfer.', array(
                'type' => 'object', 'required' => array('job_id', 'plan_hash', 'idempotency_key', 'confirm'), 'additionalProperties' => false,
                'properties' => array('job_id' => array('type' => 'string'), 'plan_hash' => array('type' => 'string'), 'idempotency_key' => self::id_property(), 'confirm' => array('type' => 'boolean')),
            )),
            self::tool('job_cancel', 'Cancel a queued or running job.', self::idempotent_id_schema('job_id')),
            self::tool('snapshots_list', 'List rollback snapshots.', $object),
            self::tool('snapshot_rollback', 'Restore a snapshot. Requires the exact snapshot id and confirm=true.', array(
                'type' => 'object', 'required' => array('snapshot_id', 'plan_hash', 'idempotency_key', 'confirm'), 'additionalProperties' => false,
                'properties' => array(
                    'snapshot_id' => array('type' => 'string', 'pattern' => '^[a-f0-9]{32}$'),
                    'plan_hash' => array('type' => 'string', 'pattern' => '^[a-f0-9]{64}$'),
                    'idempotency_key' => array('type' => 'string', 'pattern' => '^[a-f0-9]{32}$'),
                    'confirm' => array('type' => 'boolean'),
                ),
            )),
        );
    }

    private static function call_tool($name, array $args)
    {
        $capabilities = array(
            'status' => 'status', 'introspect' => 'read', 'config_get' => 'status', 'config_schema' => 'status',
            'config_validate' => 'status', 'config_apply' => 'write', 'peers_list' => 'status',
            'release_create' => 'write', 'release_list' => 'read', 'release_get' => 'read', 'release_dry_run' => 'write',
            'conflicts_resolve' => 'write', 'deletes_confirm' => 'write', 'release_apply' => 'write',
            'job_get' => 'read', 'job_continue' => 'write', 'job_conflicts_resolve' => 'write',
            'job_deletes_confirm' => 'write', 'job_confirm' => 'write', 'job_cancel' => 'write',
            'snapshots_list' => 'read', 'snapshot_rollback' => 'restore',
        );
        if (! isset($capabilities[$name])) {
            return self::tool_error(new WP_Error('fsync_mcp_tool_unknown', 'Unknown tool: ' . $name));
        }
        $allowed = Fsync_Mcp_Token::require_capability($capabilities[$name]);
        if (is_wp_error($allowed)) {
            return self::tool_error($allowed);
        }
        $mutating = array(
            'config_apply', 'release_create', 'release_dry_run', 'conflicts_resolve', 'deletes_confirm', 'release_apply',
            'job_continue', 'job_conflicts_resolve', 'job_deletes_confirm', 'job_confirm', 'job_cancel', 'snapshot_rollback',
        );
        if (in_array($name, $mutating, true) && ! Fsync_Utils::is_public_id((string) ($args['idempotency_key'] ?? ''))) {
            return self::tool_error(new WP_Error('fsync_idempotency_key_required', $name . ' requires a stable 32-character idempotency_key.'));
        }

        switch ($name) {
            case 'status':
                $result = array('receiver_enabled' => Fsync_Auth::receiver_enabled(), 'active_env' => Fsync_Config_Io::active_env(), 'environment' => Fsync_Env::report());
                break;
            case 'introspect':
                $result = Fsync_Introspect::report($args);
                break;
            case 'config_get':
                $loaded = Fsync_Config_Io::load();
                $result = array('source' => $loaded['source'], 'document' => $loaded['document'], 'effective' => Fsync_Config::document(), 'active_env' => Fsync_Config_Io::active_env());
                break;
            case 'config_schema':
                $result = Fsync_Config_Schema::generate();
                break;
            case 'config_validate':
                $result = self::validate_config((array) ($args['document'] ?? array()));
                break;
            case 'config_apply':
                if (($args['confirm'] ?? false) !== true) {
                    $result = new WP_Error('fsync_confirmation_required', 'confirm=true is required to save configuration.');
                    break;
                }
                $result = self::save_config((array) ($args['document'] ?? array()), (string) ($args['note'] ?? 'MCP'));
                break;
            case 'peers_list':
                $result = Fsync_Peer::all();
                break;
            case 'release_create':
                $direction = (string) ($args['direction'] ?? 'push');
                if ($direction === 'pull') {
                    $result = Fsync_Job::create_pull(
                        (string) ($args['peer_id'] ?? ''),
                        (string) ($args['profile'] ?? 'full'),
                        (string) ($args['idempotency_key'] ?? '')
                    );
                    break;
                }
                if ($direction !== 'push') {
                    $result = new WP_Error('fsync_direction_invalid', 'direction must be push or pull.');
                    break;
                }
                $created = Fsync_Release::create(
                    (string) ($args['peer_id'] ?? ''),
                    (string) ($args['profile'] ?? 'full'),
                    $direction,
                    (string) ($args['idempotency_key'] ?? '')
                );
                if (is_wp_error($created)) {
                    $result = $created;
                    break;
                }
                $job = ! empty($created['idempotent'])
                    ? Fsync_Job::find_latest($created['release']['release_id'], 'push_release')
                    : null;
                if ($job === null) {
                    $job = Fsync_Job::create('push_release', array('peer_id' => (string) $args['peer_id']), $created['release']['release_id']);
                }
                $result = is_wp_error($job) ? $job : array('release' => Fsync_Rest_Migration::public_release($created['release']), 'job' => Fsync_Rest_Migration::public_job($job));
                break;
            case 'release_list':
                $result = array_map([Fsync_Rest_Migration::class, 'public_release'], Fsync_Release::all());
                break;
            case 'release_get':
                $release = Fsync_Release::get((string) ($args['release_id'] ?? ''));
                $result = is_wp_error($release) ? $release : array('release' => Fsync_Rest_Migration::public_release($release), 'items' => Fsync_Release::items($release['release_id']));
                break;
            case 'release_dry_run':
                if (! Fsync_Utils::is_public_id((string) ($args['idempotency_key'] ?? ''))) {
                    $result = new WP_Error('fsync_idempotency_key_required', 'release_dry_run requires a stable 32-character idempotency_key.');
                    break;
                }
                $release = Fsync_Release::finalize_dry_run((string) ($args['release_id'] ?? ''));
                if (is_wp_error($release)) {
                    $result = $release;
                    break;
                }
                $confirmation = (string) ($release['confirmation'] ?? '');
                unset($release['confirmation']);
                $result = array(
                    'release' => Fsync_Rest_Migration::public_release($release),
                    'items' => Fsync_Rest_Migration::public_items($release['release_id']),
                    'confirmation' => $confirmation,
                );
                break;
            case 'conflicts_resolve':
                $result = self::review_result(Fsync_Release::resolve((string) ($args['release_id'] ?? ''), (string) ($args['plan_hash'] ?? ''), (array) ($args['resolutions'] ?? array())));
                break;
            case 'deletes_confirm':
                $result = ($args['confirm'] ?? false) === true
                    ? self::review_result(Fsync_Release::confirm_deletes((string) ($args['release_id'] ?? ''), (string) ($args['plan_hash'] ?? '')))
                    : new WP_Error('fsync_confirmation_required', 'confirm=true is required for deletes.');
                break;
            case 'release_apply':
                if (($args['confirm'] ?? false) !== true) {
                    $result = new WP_Error('fsync_confirmation_required', 'confirm=true is required to apply a release.');
                    break;
                }
                $idempotency_key = (string) ($args['idempotency_key'] ?? '');
                $result = Fsync_Utils::is_public_id($idempotency_key)
                    ? Fsync_Job::queue_apply(
                        (string) ($args['release_id'] ?? ''),
                        (string) ($args['plan_hash'] ?? ''),
                        (string) ($args['confirmation'] ?? ''),
                        $idempotency_key
                    )
                    : new WP_Error('fsync_idempotency_key_required', 'release_apply requires a stable 32-character idempotency_key.');
                if (is_array($result) && isset($result['job_id'])) {
                    $result = Fsync_Rest_Migration::public_job($result);
                }
                break;
            case 'job_get':
                $result = Fsync_Job::get((string) ($args['job_id'] ?? ''));
                if (is_array($result)) {
                    $result = Fsync_Rest_Migration::public_job($result);
                }
                break;
            case 'job_continue':
                $result = Fsync_Utils::is_public_id((string) ($args['idempotency_key'] ?? ''))
                    ? Fsync_Job::run((string) ($args['job_id'] ?? ''))
                    : new WP_Error('fsync_idempotency_key_required', 'job_continue requires a stable 32-character idempotency_key.');
                if (is_array($result)) {
                    $result = Fsync_Rest_Migration::public_job($result);
                }
                break;
            case 'job_conflicts_resolve':
                $result = Fsync_Job::resolve_remote(
                    (string) ($args['job_id'] ?? ''),
                    (string) ($args['plan_hash'] ?? ''),
                    (array) ($args['resolutions'] ?? array())
                );
                if (is_array($result)) {
                    $result = Fsync_Rest_Migration::public_job($result);
                }
                break;
            case 'job_deletes_confirm':
                $result = ($args['confirm'] ?? false) === true
                    ? Fsync_Job::confirm_remote_deletes((string) ($args['job_id'] ?? ''), (string) ($args['plan_hash'] ?? ''))
                    : new WP_Error('fsync_confirmation_required', 'confirm=true is required for remote deletes.');
                if (is_array($result)) {
                    $result = Fsync_Rest_Migration::public_job($result);
                }
                break;
            case 'job_confirm':
                $result = ($args['confirm'] ?? false) === true
                    ? Fsync_Job::confirm_remote_apply((string) ($args['job_id'] ?? ''), (string) ($args['plan_hash'] ?? ''))
                    : new WP_Error('fsync_confirmation_required', 'confirm=true is required to apply the remote plan.');
                if (is_array($result)) {
                    $result = Fsync_Rest_Migration::public_job($result);
                }
                break;
            case 'job_cancel':
                $result = Fsync_Utils::is_public_id((string) ($args['idempotency_key'] ?? ''))
                    ? Fsync_Job::cancel((string) ($args['job_id'] ?? ''))
                    : new WP_Error('fsync_idempotency_key_required', 'job_cancel requires a stable 32-character idempotency_key.');
                if (is_array($result)) {
                    $result = Fsync_Rest_Migration::public_job($result);
                }
                break;
            case 'snapshots_list':
                $result = Fsync_Snapshot::all();
                break;
            case 'snapshot_rollback':
                if (($args['confirm'] ?? false) !== true) {
                    $result = new WP_Error('fsync_confirmation_required', 'confirm=true is required to rollback.');
                    break;
                }
                if (! Fsync_Utils::is_public_id((string) ($args['idempotency_key'] ?? ''))) {
                    $result = new WP_Error('fsync_idempotency_key_required', 'snapshot_rollback requires a stable 32-character idempotency_key.');
                    break;
                }
                $authorized = Fsync_Snapshot::authorize_rollback(
                    (string) ($args['snapshot_id'] ?? ''),
                    (string) ($args['plan_hash'] ?? '')
                );
                $result = is_wp_error($authorized)
                    ? $authorized
                    : Fsync_Snapshot::restore((string) ($args['snapshot_id'] ?? ''));
                break;
            default:
                $result = new WP_Error('fsync_mcp_tool_unknown', 'Unknown tool.');
        }

        return is_wp_error($result) ? self::tool_error($result) : self::tool_success($result);
    }

    private static function resources()
    {
        return array(
            self::resource('fsync://site/status', 'Site status', 'Current environment, health and receiver state.'),
            self::resource('fsync://config/effective', 'Effective configuration', 'Merged environment-aware configuration.'),
            self::resource('fsync://config/schema', 'Configuration schema', 'Site-specific JSON Schema.'),
            self::resource('fsync://peers', 'Peers', 'Paired WordPress environments.'),
            self::resource('fsync://releases', 'Releases', 'Recent migration releases.'),
            self::resource('fsync://jobs', 'Jobs', 'Transfer and apply jobs.'),
            self::resource('fsync://snapshots', 'Snapshots', 'Available rollback snapshots.'),
            self::resource('fsync://docs/architecture', 'Architecture', 'Public architecture specification.', 'text/markdown'),
            self::resource('fsync://docs/portable-format', 'Portable format', 'Portable entity and manifest format.', 'text/markdown'),
        );
    }

    private static function read_resource($uri)
    {
        $required = self::resource_capability((string) $uri);
        if ($required !== '') {
            $allowed = Fsync_Mcp_Token::require_capability($required);
            if (is_wp_error($allowed)) {
                return $allowed;
            }
        }
        $map = array(
            'fsync://site/status' => array('receiver_enabled' => Fsync_Auth::receiver_enabled(), 'active_env' => Fsync_Config_Io::active_env(), 'environment' => Fsync_Env::report()),
            'fsync://config/effective' => Fsync_Config::document(),
            'fsync://config/schema' => Fsync_Config_Schema::generate(),
            'fsync://peers' => Fsync_Peer::all(),
            'fsync://releases' => array_map([Fsync_Rest_Migration::class, 'public_release'], Fsync_Release::all()),
            'fsync://jobs' => array_map([Fsync_Rest_Migration::class, 'public_job'], Fsync_Job::all()),
            'fsync://snapshots' => Fsync_Snapshot::all(),
        );
        if (array_key_exists($uri, $map)) {
            $encoded = Fsync_Utils::encode($map[$uri]);
            if (is_wp_error($encoded)) {
                return $encoded;
            }

            return array('uri' => $uri, 'mimeType' => 'application/json', 'text' => $encoded);
        }
        $docs = array(
            'fsync://docs/architecture' => FSYNC_DIR . 'docs/ARCHITECTURE.md',
            'fsync://docs/portable-format' => FSYNC_DIR . 'docs/PORTABLE_FORMAT.md',
        );
        if (isset($docs[$uri]) && is_readable($docs[$uri])) {
            return array('uri' => $uri, 'mimeType' => 'text/markdown', 'text' => (string) file_get_contents($docs[$uri]));
        }

        return new WP_Error('fsync_mcp_resource_missing', 'Resource not found.');
    }

    private static function resource_capability($uri)
    {
        if (in_array($uri, array('fsync://site/status', 'fsync://config/effective', 'fsync://config/schema', 'fsync://docs/architecture', 'fsync://docs/portable-format'), true)) {
            return 'status';
        }
        if (in_array($uri, array('fsync://peers', 'fsync://releases', 'fsync://jobs', 'fsync://snapshots'), true)) {
            return 'read';
        }

        return '';
    }

    private static function review_result($release)
    {
        if (is_wp_error($release)) {
            return $release;
        }
        $confirmation = (string) ($release['confirmation'] ?? '');
        unset($release['confirmation']);

        return array(
            'release' => Fsync_Rest_Migration::public_release($release),
            'items' => Fsync_Rest_Migration::public_items($release['release_id']),
            'confirmation' => $confirmation,
        );
    }

    private static function prompts()
    {
        return array(
            array('name' => 'configure_migration', 'description' => 'Inspect the site and author a least-privilege migration configuration.', 'arguments' => array(array('name' => 'target_env', 'required' => true))),
            array('name' => 'plan_migration', 'description' => 'Build and review a safe one-way migration plan.', 'arguments' => array(array('name' => 'peer_id', 'required' => true))),
            array('name' => 'diagnose_failure', 'description' => 'Diagnose a failed transfer, apply, verification or rollback.', 'arguments' => array(array('name' => 'job_id', 'required' => false), array('name' => 'release_id', 'required' => false))),
        );
    }

    private static function get_prompt($name, array $args)
    {
        if ($name === 'configure_migration') {
            $text = 'Inspect fsync://config/schema and the introspect tool. Draft a least-privilege config for target environment ' . (string) ($args['target_env'] ?? '') . '. Validate it. Do not save until the user explicitly confirms.';
        } elseif ($name === 'plan_migration') {
            $text = 'Create a full dry-run for peer ' . (string) ($args['peer_id'] ?? '') . '. Continue the job until it awaits confirmation. Summarize creates, updates, conflicts, blocked deletes, disk requirements and warnings. Never apply without an explicit user confirmation of the exact plan_hash.';
        } elseif ($name === 'diagnose_failure') {
            $text = 'Read status, release and job resources for job ' . (string) ($args['job_id'] ?? '') . ' and release ' . (string) ($args['release_id'] ?? '') . '. Identify the failing phase and the safest recovery. Prefer retry for idempotent transfers and snapshot rollback for partial apply.';
        } else {
            return new WP_Error('fsync_mcp_prompt_missing', 'Prompt not found.');
        }

        return array('description' => 'Yamashin WP Migration operational prompt', 'messages' => array(array('role' => 'user', 'content' => array('type' => 'text', 'text' => $text))));
    }

    private static function validate_config(array $document)
    {
        return Fsync_Config_Validate::check(
            $document,
            array(
                'introspect' => Fsync_Introspect::report(array('include_meta_keys' => false, 'include_options' => false, 'include_users' => false)),
                'credentials' => array_column(Fsync_Credentials::all(), 'credential_id'),
            )
        );
    }

    private static function save_config(array $document, $note)
    {
        $validated = self::validate_config($document);
        if (! $validated['ok']) {
            return new WP_Error('fsync_config_invalid', 'Configuration validation failed.', array('errors' => $validated['errors']));
        }
        $saved = Fsync_Config_Io::save($document, $note);
        if (is_wp_error($saved)) {
            return $saved;
        }
        Fsync_Config::flush();

        return array('ok' => true, 'warnings' => $validated['warnings'], 'scope_fingerprint' => Fsync_Config::scope_fingerprint());
    }

    private static function tool($name, $description, array $input_schema)
    {
        return array('name' => $name, 'description' => $description, 'inputSchema' => $input_schema);
    }

    private static function id_schema($name)
    {
        return array('type' => 'object', 'required' => array($name), 'additionalProperties' => false, 'properties' => array($name => array('type' => 'string', 'pattern' => '^[a-f0-9]{32}$')));
    }

    private static function idempotent_id_schema($name)
    {
        return array(
            'type' => 'object',
            'required' => array($name, 'idempotency_key'),
            'additionalProperties' => false,
            'properties' => array(
                $name => array('type' => 'string', 'pattern' => '^[a-f0-9]{32}$'),
                'idempotency_key' => array('type' => 'string', 'pattern' => '^[a-f0-9]{32}$'),
            ),
        );
    }

    private static function id_property()
    {
        return array('type' => 'string', 'pattern' => '^[a-f0-9]{32}$');
    }

    private static function resource($uri, $name, $description, $mime = 'application/json')
    {
        return array('uri' => $uri, 'name' => $name, 'description' => $description, 'mimeType' => $mime);
    }

    private static function tool_success($value)
    {
        $encoded = Fsync_Utils::encode($value);

        return array('content' => array(array('type' => 'text', 'text' => is_wp_error($encoded) ? '{}' : $encoded)), 'structuredContent' => $value, 'isError' => false);
    }

    private static function tool_error(WP_Error $error)
    {
        return array(
            'content' => array(array('type' => 'text', 'text' => $error->get_error_message())),
            'structuredContent' => array('code' => $error->get_error_code(), 'message' => $error->get_error_message(), 'data' => $error->get_error_data()),
            'isError' => true,
        );
    }

    private static function rpc_result($id, $result)
    {
        $response = new WP_REST_Response(array('jsonrpc' => '2.0', 'id' => $id, 'result' => $result), 200);
        $response->header('Content-Type', 'application/json; charset=utf-8');
        $response->header('Cache-Control', 'no-store');
        $response->header('MCP-Protocol-Version', self::PROTOCOL_VERSION);

        return $response;
    }

    private static function rpc_error($id, $code, $message)
    {
        $response = new WP_REST_Response(array('jsonrpc' => '2.0', 'id' => $id, 'error' => array('code' => $code, 'message' => $message)), 200);
        $response->header('Content-Type', 'application/json; charset=utf-8');
        $response->header('Cache-Control', 'no-store');
        $response->header('MCP-Protocol-Version', self::PROTOCOL_VERSION);

        return $response;
    }
}
