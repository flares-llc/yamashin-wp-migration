<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The effective configuration, and the things configuration is not allowed to
 * change.
 *
 * The protected lists below are deliberately not exposed in the UI or the JSON
 * schema. They are not defaults to be overridden; they are options where being
 * synced between environments breaks the site, de-indexes it from search
 * engines, or hands one environment's credentials to another.
 */
final class Fsync_Config
{
    /**
     * Options that are never synchronised, in either direction.
     *
     * Entries wrapped in slashes are regular expressions; everything else is an
     * exact option name. {prefix} is replaced with the live table prefix,
     * because those option names differ per install and would otherwise slip
     * through on a site whose prefix is not wp_.
     */
    const PROTECTED_OPTIONS = array(
        // Addresses. Syncing these points one site at the other.
        'siteurl',
        'home',

        // Activation is transported only by the authenticated runtime record;
        // it must never be copied as an ordinary allow-listed option.
        'active_plugins',
        'stylesheet',
        'template',
        'current_theme',
        'recently_activated',
        'auto_update_plugins',
        'auto_update_themes',

        // Roles and account settings. {prefix}user_roles is prefix-dependent.
        '{prefix}user_roles',
        'admin_email',
        'new_admin_email',
        'default_role',
        'users_can_register',

        // Search engine visibility. Copying this either de-indexes production
        // or indexes staging; both are damaging and neither is obvious.
        'blog_public',

        // Infrastructure state.
        'cron',
        'rewrite_rules',
        'db_version',
        'initial_db_version',
        'upload_path',
        'upload_url_path',
        'WPLANG',
        'mailserver_url',
        'mailserver_login',
        'mailserver_pass',
        'mailserver_port',

        // Ephemeral data. Without this, transients dominate every diff.
        '/^_?(site_)?transient(_timeout)?_/',

        // The plugin's own state.
        '/^fsync_/',
    );

    /**
     * Options that can be synced but should make the operator stop and think.
     */
    const WARN_OPTIONS = array(
        'permalink_structure',
        'timezone_string',
        'blogname',
        'blogdescription',
        'page_on_front',
        'show_on_front',
    );

    /**
     * Post meta never synchronised: editor bookkeeping, caches, and our own
     * identity marker.
     */
    const PROTECTED_META = array(
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_old_date',
        '/^_wp_trash_meta_/',
        '/^_oembed_/',
        '_encloseme',
        '_pingme',
        '/^_fsync_/',
    );

    /**
     * Tables that must never be synchronised even if registered by hand.
     */
    const PROTECTED_TABLES = array(
        'users',
        'usermeta',
    );

    /** @var array|null */
    private static $cache = null;

    /**
     * The merged document: defaults, then the authored document, then the
     * overlay for this site's environment.
     *
     * @return array
     */
    public static function document()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $loaded = Fsync_Config_Io::load();
        $document = Fsync_Config_Io::merge(self::defaults(), $loaded['document']);

        $env = Fsync_Config_Io::active_env();
        if ($env !== '' && isset($document['environment_overrides'][$env])) {
            $document = Fsync_Config_Io::merge(
                $document,
                (array) $document['environment_overrides'][$env]
            );
        }

        self::$cache = $document;

        return self::$cache;
    }

    /**
     * @return void
     */
    public static function flush()
    {
        self::$cache = null;
        Fsync_Config_Io::flush();
    }

    /**
     * @return array
     */
    public static function defaults()
    {
        return array(
            'config_version' => 1,
            'site_role' => '',
            'sync' => array(
                'scope' => array(
                    'post_types' => array(),
                    'taxonomies' => array(),
                    'comments' => true,
                    'comments_delete' => false,
                    'users' => array(
                        'enabled' => true,
                        'passwords' => false,
                        'delete' => false,
                    ),
                    'options' => array('allow' => array(), 'delete' => false),
                    'tables' => array(),
                    'files' => array(
                        'uploads' => true,
                        'theme' => array(),
                        'plugins' => false,
                        'mu_plugins' => false,
                        'core' => 'checksum-only',
                        'delete' => false,
                    ),
                    'refs' => array(),
                    'authors' => array('map' => array(), 'fallback' => ''),
                ),
                'scope_overrides' => array(),
                'policy' => array(
                    'direction_default' => 'push',
                    'conflict' => 'manual',
                    'allow_delete' => false,
                    'protected_extra' => array(),
                ),
            ),
            'environments' => array(),
            'backup' => array(
                'categories' => array(
                    'db.posts',
                    'db.postmeta',
                    'db.terms',
                    'db.options',
                    'files.uploads',
                    'files.theme',
                ),
                'retention' => array('keep' => 10, 'safety_backup_days' => 7),
                'destinations' => array('local'),
            ),
            'storage' => array(),
            'schedules' => array(),
            'notify' => array(),
        );
    }

    /**
     * The synchronisation scope to use with one peer environment.
     *
     * @param string $env_name
     * @return array
     */
    public static function scope($env_name = '')
    {
        $document = self::document();
        $scope = (array) ($document['sync']['scope'] ?? array());

        if ($env_name !== '') {
            $override = $document['sync']['scope_overrides'][$env_name] ?? null;
            if (is_array($override)) {
                $scope = Fsync_Config_Io::merge($scope, $override);
            }
        }

        return $scope;
    }

    /**
     * Fingerprint of the scope agreed with one peer.
     *
     * Computed PER PEER, not globally. Once per-environment overrides exist, a
     * single global fingerprint can never match on both sides, and the
     * "refuse to run on mismatch" safety check would permanently block every
     * sync. Only the scope is included: connection details, storage targets and
     * schedules legitimately differ between environments.
     *
     * @param string $env_name
     * @return string|WP_Error
     */
    public static function scope_fingerprint($env_name = '')
    {
        return Fsync_Utils::canonical_hash(
            array(
                'hash_algo_version' => FSYNC_HASH_ALGO_VERSION,
                'scope' => self::scope($env_name),
            )
        );
    }

    /**
     * @return array<string, array>
     */
    public static function environments()
    {
        return (array) (self::document()['environments'] ?? array());
    }

    /**
     * @param string $env_name
     * @return array|null
     */
    public static function environment($env_name)
    {
        $environments = self::environments();

        return isset($environments[$env_name]) ? (array) $environments[$env_name] : null;
    }

    /**
     * Whether an option is barred from synchronisation.
     *
     * @param string $name
     * @return bool
     */
    public static function is_protected_option($name)
    {
        $extra = (array) (self::document()['sync']['policy']['protected_extra'] ?? array());

        return self::matches_any((string) $name, array_merge(self::PROTECTED_OPTIONS, $extra));
    }

    /**
     * @param string $name
     * @return bool
     */
    public static function is_protected_meta($name)
    {
        return self::matches_any((string) $name, self::PROTECTED_META);
    }

    /**
     * @param string $name
     * @return bool
     */
    public static function is_warned_option($name)
    {
        return in_array((string) $name, self::WARN_OPTIONS, true);
    }

    /**
     * Match a name against a list of exact names and regular expressions.
     *
     * @param string $name
     * @param array<int, string> $patterns
     * @return bool
     */
    public static function matches_any($name, array $patterns)
    {
        global $wpdb;

        $prefix = isset($wpdb) ? $wpdb->prefix : 'wp_';

        foreach ($patterns as $pattern) {
            $pattern = (string) $pattern;

            if ($pattern === '') {
                continue;
            }

            if (strpos($pattern, '{prefix}') !== false) {
                $pattern = str_replace('{prefix}', $prefix, $pattern);
            }

            if (strlen($pattern) > 2 && $pattern[0] === '/' && substr($pattern, -1) === '/') {
                if (@preg_match($pattern, $name) === 1) {
                    return true;
                }

                continue;
            }

            if ($pattern === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every credential id the document refers to.
     *
     * Used to verify that a config which mentions "gcs-backup" has a matching
     * stored secret, and to prove the document itself contains no secret values.
     *
     * @param array|null $document
     * @return array<int, string>
     */
    public static function credential_references($document = null)
    {
        $document = $document === null ? self::document() : $document;
        $found = array();

        self::walk_credentials($document, $found);

        return array_values(array_unique($found));
    }

    /**
     * @param mixed $node
     * @param array $found
     * @return void
     */
    private static function walk_credentials($node, array &$found)
    {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if ($key === 'credential' && is_string($value) && $value !== '') {
                $found[] = $value;

                continue;
            }

            self::walk_credentials($value, $found);
        }
    }
}
