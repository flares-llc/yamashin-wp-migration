<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * JSON Schema for the configuration document.
 *
 * The schema is generated per site rather than shipped as a fixed file. A
 * generic schema can only say "post_types is an object"; a generated one says
 * "its keys must be one of these fourteen post types that actually exist here".
 * That difference is what stops an agent from confidently writing a
 * configuration for a post type this site has never registered.
 *
 * A static copy is shipped at schema/config.schema.json for editors that follow
 * the $schema reference in a repository, but the generated version is the one
 * validation and authoring should use.
 */
final class Fsync_Config_Schema
{
    /**
     * @param array|null $introspect Reuse a report if one was already built.
     * @return array
     */
    public static function generate($introspect = null)
    {
        $introspect = $introspect === null
            ? Fsync_Introspect::report(array('include_meta_keys' => false, 'include_users' => false))
            : $introspect;

        $post_types = array_keys((array) ($introspect['post_types'] ?? array()));
        $taxonomies = array_keys((array) ($introspect['taxonomies'] ?? array()));
        $themes = array_column((array) ($introspect['themes'] ?? array()), 'slug');
        $plugins = array_column((array) ($introspect['plugins'] ?? array()), 'slug');
        $options = array_column((array) ($introspect['options'] ?? array()), 'name');
        $tables = array_column((array) ($introspect['tables'] ?? array()), 'table');

        return array(
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            '$id' => rest_url(FSYNC_REST_NAMESPACE . '/config/schema'),
            'title' => 'Flares Sync 設定',
            'description' => sprintf(
                '%s 用に生成されたスキーマです。投稿タイプなどの候補はこのサイトの実際の登録内容から生成されています。',
                home_url('/')
            ),
            'type' => 'object',
            'required' => array('config_version'),
            'additionalProperties' => false,
            'properties' => array(
                '$schema' => array('type' => 'string'),
                'config_version' => array('type' => 'integer', 'const' => 1),
                'site_role' => array(
                    'type' => 'string',
                    'description' => 'この設定ファイルを配置したサイトが既定でどの環境として振る舞うか。',
                ),
                'sync' => self::sync_schema($post_types, $taxonomies, $themes, $plugins, $options, $tables),
                'environments' => self::environments_schema(),
                'environment_overrides' => array(
                    'type' => 'object',
                    'description' => '環境名ごとに、この文書全体へ適用する上書き。接続先・保存先・スケジュールなど環境固有の値に使います。',
                    'additionalProperties' => array('type' => 'object'),
                ),
                'backup' => self::backup_schema(),
                'storage' => self::storage_schema(),
                'schedules' => self::schedules_schema(),
                'notify' => self::notify_schema(),
            ),
        );
    }

    /**
     * @param array $post_types
     * @param array $taxonomies
     * @param array $themes
     * @param array $plugins
     * @param array $options
     * @param array $tables
     * @return array
     */
    private static function sync_schema($post_types, $taxonomies, $themes, $plugins, $options, $tables)
    {
        $meta_rules = array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'mode' => array(
                    'type' => 'string',
                    'enum' => array('all', 'allow', 'none'),
                    'description' => 'all は拒否リスト方式、allow は許可リスト方式。',
                ),
                'allow' => array('type' => 'array', 'items' => array('type' => 'string')),
                'deny' => array('type' => 'array', 'items' => array('type' => 'string')),
            ),
        );

        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'scope' => array(
                    'type' => 'object',
                    'description' => '両サイトで一致している必要がある部分。scope_fingerprint はここだけから計算されます。',
                    'additionalProperties' => false,
                    'properties' => array(
                        'post_types' => array(
                            'type' => 'object',
                            'propertyNames' => self::names_of($post_types),
                            'additionalProperties' => array(
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => array(
                                    'statuses' => array(
                                        'type' => 'array',
                                        'items' => array(
                                            'type' => 'string',
                                            'enum' => array(
                                                'publish', 'draft', 'pending', 'private', 'future', 'inherit',
                                            ),
                                        ),
                                    ),
                                    'meta' => $meta_rules,
                                    'taxonomies' => array(
                                        'type' => 'array',
                                        'items' => self::names_of($taxonomies),
                                    ),
                                    'delete' => array('type' => 'boolean'),
                                ),
                            ),
                        ),
                        'taxonomies' => array(
                            'type' => 'object',
                            'propertyNames' => self::names_of($taxonomies),
                            'additionalProperties' => array(
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => array(
                                    'meta' => $meta_rules,
                                    'delete' => array('type' => 'boolean'),
                                ),
                            ),
                        ),
                        'options' => array(
                            'type' => 'object',
                            'additionalProperties' => false,
                            'description' => 'オプションは許可リスト方式のみです。/^…/ 形式で正規表現も書けます。',
                            'properties' => array(
                                'allow' => array(
                                    'type' => 'array',
                                    'items' => array(
                                        'anyOf' => array(
                                            self::names_of($options),
                                            array('type' => 'string', 'pattern' => '^/.*/$'),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                        'tables' => array(
                            'type' => 'array',
                            'items' => array(
                                'type' => 'object',
                                'required' => array('name', 'primary_key'),
                                'properties' => array(
                                    'name' => self::names_of($tables),
                                    'primary_key' => array('type' => 'string'),
                                    'uid_column' => array('type' => 'string'),
                                    'natural_key' => array('type' => 'array', 'items' => array('type' => 'string')),
                                    'refs' => array('type' => 'object'),
                                    'portable' => array('type' => 'object'),
                                    'delete' => array('type' => 'boolean'),
                                ),
                            ),
                        ),
                        'files' => array(
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => array(
                                'uploads' => array('type' => 'boolean'),
                                'theme' => array(
                                    'type' => array('array', 'boolean'),
                                    'items' => self::names_of($themes),
                                ),
                                'plugins' => array(
                                    'type' => array('array', 'boolean'),
                                    'items' => self::names_of($plugins),
                                    'description' => '有効化状態(active_plugins)は同期されません。',
                                ),
                                'core' => array(
                                    'anyOf' => array(
                                        array('type' => 'boolean', 'const' => false),
                                        array('type' => 'string', 'enum' => array('checksum-only', 'sync')),
                                    ),
                                    'description' => 'checksum-only は公式チェックサムとの差分を検出して報告するだけです。',
                                ),
                            ),
                        ),
                        'refs' => array(
                            'type' => 'object',
                            'description' => 'ID を保持するメタキーと、その参照先の種別。',
                            'additionalProperties' => array(
                                'anyOf' => array(
                                    array('type' => 'string', 'enum' => array('post', 'term', 'user')),
                                    array(
                                        'type' => 'object',
                                        'properties' => array(
                                            'kind' => array('type' => 'string', 'enum' => array('post', 'term', 'user')),
                                            'shape' => array(
                                                'type' => 'string',
                                                'enum' => array('scalar', 'csv', 'serialized_array'),
                                            ),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                        'authors' => array(
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => array(
                                'map' => array(
                                    'type' => 'object',
                                    'additionalProperties' => array('type' => 'string'),
                                ),
                                'fallback' => array('type' => 'string'),
                            ),
                        ),
                    ),
                ),
                'scope_overrides' => array(
                    'type' => 'object',
                    'description' => '環境ごとのスコープ差分。scope_fingerprint は相手環境ごとに別々に計算されます。',
                    'additionalProperties' => array('type' => 'object'),
                ),
                'policy' => array(
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => array(
                        'direction_default' => array('type' => 'string', 'enum' => array('push', 'pull')),
                        'conflict' => array(
                            'type' => 'string',
                            'enum' => array('manual', 'push_wins', 'pull_wins', 'newest_wins'),
                            'description' => 'manual は競合を停止して確認を求めます（推奨）。',
                        ),
                        'allow_delete' => array('type' => 'boolean'),
                        'protected_extra' => array('type' => 'array', 'items' => array('type' => 'string')),
                    ),
                ),
            ),
        );
    }

    /**
     * @return array
     */
    private static function environments_schema()
    {
        return array(
            'type' => 'object',
            'propertyNames' => array('pattern' => '^[a-z0-9][a-z0-9_-]{0,63}$'),
            'additionalProperties' => array(
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => array(
                    'role' => array(
                        'type' => 'string',
                        'enum' => array('source', 'target'),
                        'description' => 'source はリリースの作成元。URLと認証情報は不要です。',
                    ),
                    'url' => array('type' => 'string', 'format' => 'uri'),
                    'credential' => array(
                        'type' => 'string',
                        'description' => '認証情報のID。値ではありません。ペアリング時に自動設定されます。',
                    ),
                    'transport' => array(
                        'type' => 'array',
                        'items' => array('type' => 'string', 'enum' => array('https', 'ssh')),
                    ),
                    'promotes_to' => array(
                        'type' => 'array',
                        'items' => array('type' => 'string'),
                        'description' => 'この環境で検証したリリースを昇格できる先。',
                    ),
                    'requires_verified_on' => array(
                        'type' => 'array',
                        'items' => array('type' => 'string'),
                        'description' => 'この環境が受け入れる前に、検証済みであることを要求する環境。受信側で強制されます。',
                    ),
                    'ip_allowlist' => array('type' => 'array', 'items' => array('type' => 'string')),
                ),
            ),
        );
    }

    /**
     * @return array
     */
    private static function backup_schema()
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'categories' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'string',
                        'enum' => array(
                            'db.posts', 'db.postmeta', 'db.terms', 'db.options', 'db.comments',
                            'db.users', 'db.custom',
                            'files.uploads', 'files.theme', 'files.plugins', 'files.mu-plugins', 'files.core',
                        ),
                    ),
                ),
                'retention' => array(
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => array(
                        'keep' => array('type' => 'integer', 'minimum' => 1),
                        'safety_backup_days' => array('type' => 'integer', 'minimum' => 1),
                    ),
                ),
                'destinations' => array('type' => 'array', 'items' => array('type' => 'string')),
            ),
        );
    }

    /**
     * @return array
     */
    private static function storage_schema()
    {
        return array(
            'type' => 'object',
            'additionalProperties' => array(
                'type' => 'object',
                'required' => array('type'),
                'properties' => array(
                    'type' => array('type' => 'string', 'enum' => array('local', 'gcs', 'gdrive')),
                    'bucket' => array('type' => 'string'),
                    'prefix' => array('type' => 'string'),
                    'shared_drive_id' => array(
                        'type' => 'string',
                        'description' => 'Google Drive は共有ドライブが必須です。サービスアカウントには個人ドライブの容量がありません。',
                    ),
                    'folder' => array('type' => 'string'),
                    'oauth' => array('type' => 'boolean'),
                    'credential' => array('type' => 'string'),
                ),
            ),
        );
    }

    /**
     * @return array
     */
    private static function schedules_schema()
    {
        return array(
            'type' => 'array',
            'items' => array(
                'type' => 'object',
                'required' => array('name', 'job', 'interval'),
                'additionalProperties' => false,
                'properties' => array(
                    'name' => array('type' => 'string'),
                    'job' => array(
                        'type' => 'string',
                        'enum' => array(
                            'backup', 'backup_cloud', 'sync_push', 'sync_pull', 'drift_check', 'verify', 'gc',
                        ),
                    ),
                    'env' => array('type' => 'string'),
                    'interval' => array(
                        'type' => 'string',
                        'enum' => array(
                            'fsync_5min', 'fsync_15min', 'fsync_hourly',
                            'fsync_6h', 'fsync_daily', 'fsync_weekly',
                        ),
                    ),
                    'auto_apply' => array(
                        'type' => 'boolean',
                        'description' => '無人での適用。既定は false で、ドライランに成功したプロファイルでのみ有効化できます。',
                    ),
                    'destinations' => array('type' => 'array', 'items' => array('type' => 'string')),
                    'categories' => array('type' => 'array', 'items' => array('type' => 'string')),
                    'notify' => array('type' => 'array', 'items' => array('type' => 'string')),
                ),
            ),
        );
    }

    /**
     * @return array
     */
    private static function notify_schema()
    {
        return array(
            'type' => 'object',
            'additionalProperties' => array(
                'type' => 'object',
                'required' => array('type'),
                'properties' => array(
                    'type' => array('type' => 'string', 'enum' => array('email', 'slack', 'webhook')),
                    'to' => array('type' => 'string'),
                    'credential' => array('type' => 'string'),
                    'url' => array('type' => 'string'),
                    'events' => array(
                        'type' => 'array',
                        'items' => array(
                            'type' => 'string',
                            'enum' => array('failed', 'stalled', 'complete', 'drift', 'conflict'),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Constrain a string to a known set when one is available.
     *
     * On a site with nothing registered the enum would be empty, which would
     * reject every value; falling back to a plain string keeps the schema
     * usable during initial setup.
     *
     * @param array<int, string> $names
     * @return array
     */
    private static function names_of(array $names)
    {
        $names = array_values(array_filter(array_unique($names)));

        if ($names === array()) {
            return array('type' => 'string');
        }

        return array('type' => 'string', 'enum' => $names);
    }
}
