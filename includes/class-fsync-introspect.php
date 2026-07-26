<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Describes the site so that a configuration can be written against what is
 * actually there rather than what someone assumed.
 *
 * This is the input an agent reads before authoring the config document, and
 * the same data backs the manual builder's dropdowns. Sharing one source means
 * the two authoring paths cannot disagree about what exists.
 */
final class Fsync_Introspect
{
    /** Cap on the meta-key survey, which is the only expensive query here. */
    const META_KEY_LIMIT = 300;

    /** Options above this size are worth flagging when autoloaded. */
    const AUTOLOAD_WARN_BYTES = 20480;

    /**
     * @param array $args include_meta_keys, include_options, include_users
     * @return array
     */
    public static function report(array $args = array())
    {
        $args = array_merge(
            array(
                'include_meta_keys' => true,
                'include_options' => true,
                'include_users' => true,
            ),
            $args
        );

        $report = array(
            'generated_at' => Fsync_Utils::now_iso(),
            'site' => Fsync_Env::report()['site'],
            'post_types' => self::post_types(),
            'taxonomies' => self::taxonomies(),
            'themes' => self::themes(),
            'plugins' => self::plugins(),
            'tables' => self::tables(),
        );

        if ($args['include_meta_keys']) {
            $report['meta_keys'] = self::meta_keys();
        }

        if ($args['include_options']) {
            $report['options'] = self::options();
        }

        if ($args['include_users']) {
            $report['users'] = self::users();
        }

        return $report;
    }

    /**
     * @return array<string, array>
     */
    public static function post_types()
    {
        global $wpdb;

        $counts = array();
        $rows = (array) $wpdb->get_results(
            "SELECT post_type, post_status, COUNT(*) AS total
             FROM {$wpdb->posts}
             GROUP BY post_type, post_status",
            ARRAY_A
        );

        foreach ($rows as $row) {
            $type = (string) $row['post_type'];
            $counts[$type]['total'] = ($counts[$type]['total'] ?? 0) + (int) $row['total'];
            $counts[$type]['by_status'][(string) $row['post_status']] = (int) $row['total'];
        }

        $out = array();

        foreach (get_post_types(array(), 'objects') as $slug => $object) {
            // Revisions and auto-drafts are never synchronised; listing them
            // would invite a configuration that tries.
            if (in_array($slug, array('revision', 'nav_menu_item', 'custom_css', 'oembed_cache'), true)) {
                continue;
            }

            $out[$slug] = array(
                'label' => (string) ($object->labels->singular_name ?? $slug),
                'public' => (bool) $object->public,
                'hierarchical' => (bool) $object->hierarchical,
                'has_archive' => (bool) $object->has_archive,
                'builtin' => (bool) $object->_builtin,
                'taxonomies' => array_values(get_object_taxonomies($slug)),
                'supports_thumbnail' => post_type_supports($slug, 'thumbnail'),
                'count' => (int) ($counts[$slug]['total'] ?? 0),
                'by_status' => (array) ($counts[$slug]['by_status'] ?? array()),
            );
        }

        return $out;
    }

    /**
     * @return array<string, array>
     */
    public static function taxonomies()
    {
        global $wpdb;

        $counts = (array) $wpdb->get_results(
            "SELECT taxonomy, COUNT(*) AS total FROM {$wpdb->term_taxonomy} GROUP BY taxonomy",
            ARRAY_A
        );

        $by_taxonomy = array();
        foreach ($counts as $row) {
            $by_taxonomy[(string) $row['taxonomy']] = (int) $row['total'];
        }

        $out = array();

        foreach (get_taxonomies(array(), 'objects') as $slug => $object) {
            if (in_array($slug, array('nav_menu', 'link_category', 'post_format'), true)) {
                continue;
            }

            $out[$slug] = array(
                'label' => (string) ($object->labels->singular_name ?? $slug),
                'public' => (bool) $object->public,
                'hierarchical' => (bool) $object->hierarchical,
                'builtin' => (bool) $object->_builtin,
                'object_types' => array_values((array) $object->object_type),
                'count' => (int) ($by_taxonomy[$slug] ?? 0),
            );
        }

        return $out;
    }

    /**
     * Meta keys in use, with the statistics needed to configure them safely.
     *
     * serialized_ratio matters because a key holding serialized data is where
     * URL rewriting has to be structure-aware, and max_bytes matters because
     * page-builder payloads need the file transport rather than the JSON body.
     *
     * @return array<int, array>
     */
    public static function meta_keys()
    {
        global $wpdb;

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key,
                        COUNT(*) AS uses,
                        MAX(LENGTH(meta_value)) AS max_bytes,
                        SUM(CASE WHEN meta_value LIKE 'a:%%' OR meta_value LIKE 'O:%%' THEN 1 ELSE 0 END) AS serialized
                 FROM {$wpdb->postmeta}
                 GROUP BY meta_key
                 ORDER BY uses DESC
                 LIMIT %d",
                self::META_KEY_LIMIT
            ),
            ARRAY_A
        );

        $out = array();

        foreach ($rows as $row) {
            $uses = max(1, (int) $row['uses']);

            $out[] = array(
                'key' => (string) $row['meta_key'],
                'uses' => (int) $row['uses'],
                'max_bytes' => (int) $row['max_bytes'],
                'serialized_ratio' => round((int) $row['serialized'] / $uses, 3),
                'protected' => Fsync_Config::is_protected_meta((string) $row['meta_key']),
            );
        }

        return $out;
    }

    /**
     * Options, with the two facts that determine whether syncing one is safe.
     *
     * @return array<int, array>
     */
    public static function options()
    {
        global $wpdb;

        $rows = (array) $wpdb->get_results(
            "SELECT option_name, autoload, LENGTH(option_value) AS bytes
             FROM {$wpdb->options}
             ORDER BY option_name ASC",
            ARRAY_A
        );

        $out = array();

        foreach ($rows as $row) {
            $name = (string) $row['option_name'];

            if (Fsync_Config::is_protected_option($name)) {
                continue;
            }

            $bytes = (int) $row['bytes'];
            $autoload = in_array((string) $row['autoload'], array('yes', 'on', 'auto', 'auto-on'), true);

            $out[] = array(
                'name' => $name,
                'autoload' => $autoload,
                'bytes' => $bytes,
                'warn' => Fsync_Config::is_warned_option($name),
                'heavy' => $autoload && $bytes > self::AUTOLOAD_WARN_BYTES,
            );
        }

        return $out;
    }

    /**
     * Non-core tables, so that unregistered plugin data is visible rather than
     * quietly absent from every plan.
     *
     * @return array<int, array>
     */
    public static function tables()
    {
        global $wpdb;

        $core = array(
            'posts', 'postmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships',
            'options', 'comments', 'commentmeta', 'users', 'usermeta', 'links',
        );

        $rows = (array) $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
        $prefix = $wpdb->prefix;
        $out = array();

        foreach ($rows as $row) {
            $name = (string) ($row['Name'] ?? '');

            if ($prefix !== '' && strpos($name, $prefix) !== 0) {
                continue;
            }

            $logical = substr($name, strlen($prefix));

            if (in_array($logical, $core, true) || strpos($logical, 'fsync_') === 0) {
                continue;
            }

            $out[] = array(
                'table' => $logical,
                'rows' => (int) ($row['Rows'] ?? 0),
                'bytes' => (int) ($row['Data_length'] ?? 0) + (int) ($row['Index_length'] ?? 0),
                'registered' => false,
            );
        }

        return $out;
    }

    /**
     * @return array<int, array>
     */
    public static function themes()
    {
        $out = array();

        foreach (wp_get_themes() as $slug => $theme) {
            $out[] = array(
                'slug' => (string) $slug,
                'name' => (string) $theme->get('Name'),
                'version' => (string) $theme->get('Version'),
                'active' => get_stylesheet() === $slug,
                'parent' => (string) ($theme->get('Template') === $slug ? '' : $theme->get('Template')),
            );
        }

        return $out;
    }

    /**
     * @return array<int, array>
     */
    public static function plugins()
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active = (array) get_option('active_plugins', array());
        $out = array();

        foreach (get_plugins() as $file => $data) {
            $out[] = array(
                'file' => (string) $file,
                'slug' => (string) dirname((string) $file),
                'name' => (string) ($data['Name'] ?? ''),
                'version' => (string) ($data['Version'] ?? ''),
                'active' => in_array($file, $active, true),
            );
        }

        return $out;
    }

    /**
     * Logins and display names only.
     *
     * Enough to build an author map, and deliberately no email addresses: this
     * response is read by an agent and may end up in a transcript.
     *
     * @return array<int, array>
     */
    public static function users()
    {
        $out = array();

        foreach (get_users(array('fields' => array('user_login', 'display_name'), 'number' => 200)) as $user) {
            $out[] = array(
                'login' => (string) $user->user_login,
                'display_name' => (string) $user->display_name,
            );
        }

        return $out;
    }
}
