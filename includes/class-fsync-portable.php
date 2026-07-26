<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Convert WordPress state into site-independent versioned records.
 *
 * Local database ids and environment URLs never participate in identity.
 * Every returned record can therefore be hashed and compared on another site.
 */
final class Fsync_Portable
{
    const FORMAT_VERSION = 1;

    /**
     * @param string $profile content|full
     * @param string $peer_env
     * @param callable|null $consumer Receives ($key, $record) for streaming scans.
     * @return array<string, array>|WP_Error
     */
    public static function scan($profile = 'full', $peer_env = '', $consumer = null)
    {
        $profile = in_array($profile, array('content', 'full'), true) ? $profile : 'full';
        $scope = self::effective_scope(Fsync_Config::scope($peer_env), $profile);
        $items = array();

        foreach ((array) $scope['taxonomies'] as $taxonomy => $rules) {
            $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false));
            if (is_wp_error($terms)) {
                return $terms;
            }
            $primed = Fsync_Identity::prime('term', wp_list_pluck((array) $terms, 'term_id'));
            if (is_wp_error($primed)) {
                return $primed;
            }
            foreach ($terms as $term) {
                $record = self::term($term, (array) $rules);
                if (is_wp_error($record)) {
                    return $record;
                }
                $emitted = self::emit($items, $record, $consumer);
                if (is_wp_error($emitted)) {
                    return $emitted;
                }
            }
        }

        foreach ((array) $scope['post_types'] as $post_type => $rules) {
            $statuses = array_values((array) ($rules['statuses'] ?? array('publish', 'draft', 'pending', 'private', 'future', 'inherit')));
            $ids = get_posts(
                array(
                    'post_type' => $post_type,
                    'post_status' => $statuses,
                    'posts_per_page' => -1,
                    'orderby' => 'ID',
                    'order' => 'ASC',
                    'fields' => 'ids',
                    'no_found_rows' => true,
                    'suppress_filters' => false,
                )
            );
            $primed = Fsync_Identity::prime('post', (array) $ids);
            if (is_wp_error($primed)) {
                return $primed;
            }
            foreach ((array) $ids as $id) {
                $record = self::post((int) $id, (array) $rules, $scope);
                if (is_wp_error($record)) {
                    return $record;
                }
                $emitted = self::emit($items, $record, $consumer);
                if (is_wp_error($emitted)) {
                    return $emitted;
                }
            }
        }

        if (! empty($scope['comments'])) {
            $comments = get_comments(array('number' => 0, 'orderby' => 'comment_ID', 'order' => 'ASC', 'status' => 'all'));
            $primed = Fsync_Identity::prime('comment', wp_list_pluck((array) $comments, 'comment_ID'));
            if (is_wp_error($primed)) {
                return $primed;
            }
            foreach ((array) $comments as $comment) {
                $record = self::comment($comment);
                if (is_wp_error($record)) {
                    return $record;
                }
                $emitted = self::emit($items, $record, $consumer);
                if (is_wp_error($emitted)) {
                    return $emitted;
                }
            }
        }

        if ($profile === 'full' && ! empty($scope['users']['enabled'])) {
            $users = get_users(array('orderby' => 'ID', 'order' => 'ASC'));
            $primed = Fsync_Identity::prime('user', wp_list_pluck((array) $users, 'ID'));
            if (is_wp_error($primed)) {
                return $primed;
            }
            foreach ($users as $user) {
                $record = self::user($user, (array) $scope['users']);
                if (is_wp_error($record)) {
                    return $record;
                }
                $emitted = self::emit($items, $record, $consumer);
                if (is_wp_error($emitted)) {
                    return $emitted;
                }
            }
        }

        foreach ((array) ($scope['options']['allow'] ?? array()) as $pattern) {
            foreach (self::option_names($pattern) as $name) {
                $record = self::option($name);
                if ($record !== null) {
                    $emitted = self::emit($items, $record, $consumer);
                    if (is_wp_error($emitted)) {
                        return $emitted;
                    }
                }
            }
        }

        if ($profile === 'full') {
            foreach ((array) ($scope['tables'] ?? array()) as $table) {
                $records = self::table((array) $table);
                if (is_wp_error($records)) {
                    return $records;
                }
                foreach ($records as $record) {
                    $emitted = self::emit($items, $record, $consumer);
                    if (is_wp_error($emitted)) {
                        return $emitted;
                    }
                }
            }

            $files = self::files($scope);
            if (is_wp_error($files)) {
                return $files;
            }
            foreach ($files as $record) {
                $emitted = self::emit($items, $record, $consumer);
                if (is_wp_error($emitted)) {
                    return $emitted;
                }
            }

            $runtime = self::runtime($scope);
            $emitted = self::emit($items, $runtime, $consumer);
            if (is_wp_error($emitted)) {
                return $emitted;
            }
        } elseif (! empty($scope['files']['uploads'])) {
            $files = self::files(array('files' => array('uploads' => true)));
            if (is_wp_error($files)) {
                return $files;
            }
            foreach ($files as $record) {
                $emitted = self::emit($items, $record, $consumer);
                if (is_wp_error($emitted)) {
                    return $emitted;
                }
            }
        }

        if (is_callable($consumer)) {
            return array();
        }
        ksort($items, SORT_STRING);

        return $items;
    }

    private static function emit(array &$items, array $record, $consumer)
    {
        $key = self::key($record);
        if (is_callable($consumer)) {
            $result = call_user_func($consumer, $key, $record);

            return is_wp_error($result) ? $result : true;
        }
        $items[$key] = $record;

        return true;
    }

    /**
     * @param array $record
     * @return string
     */
    public static function key(array $record)
    {
        return (string) $record['kind'] . ':' . (string) $record['uid'];
    }

    /**
     * Return a hashed, environment-independent natural identity used only to
     * adopt an independently-created target row on the first migration. The
     * protected UID remains authoritative after adoption.
     *
     * @return string Empty when this record has no safe natural identity.
     */
    public static function identity_key(array $record)
    {
        $kind = (string) ($record['kind'] ?? '');
        $data = (array) ($record['data'] ?? array());
        $identity = null;
        if ($kind === 'post' && (string) ($data['slug'] ?? '') !== '') {
            $identity = array('post_type' => (string) ($data['post_type'] ?? ''), 'slug' => (string) $data['slug']);
        } elseif ($kind === 'term' && (string) ($data['slug'] ?? '') !== '') {
            $identity = array('taxonomy' => (string) ($data['taxonomy'] ?? ''), 'slug' => (string) $data['slug']);
        } elseif ($kind === 'user' && (string) ($data['login'] ?? '') !== '') {
            $identity = array('login' => strtolower((string) $data['login']));
        } elseif ($kind === 'option' && (string) ($data['name'] ?? '') !== '') {
            $identity = array('name' => (string) $data['name']);
        } elseif ($kind === 'table' && (string) ($data['table'] ?? '') !== '' && (array) ($data['identity'] ?? array()) !== array()) {
            $identity = array('table' => (string) $data['table'], 'identity' => (array) $data['identity']);
        } elseif ($kind === 'file' && (string) ($data['category'] ?? '') !== '' && (string) ($data['path'] ?? '') !== '') {
            $identity = array('category' => (string) $data['category'], 'path' => (string) $data['path']);
        } elseif ($kind === 'runtime') {
            $identity = array('runtime' => 'wordpress');
        }
        if ($identity === null) {
            return '';
        }
        $hash = Fsync_Utils::canonical_hash(array('kind' => $kind, 'identity' => $identity));

        return is_wp_error($hash) ? '' : $hash;
    }

    /** Whether the record needs the second, post-identity relationship pass. */
    public static function has_relationships(array $record)
    {
        $kind = (string) ($record['kind'] ?? '');
        $data = (array) ($record['data'] ?? array());
        if ($kind === 'term' || $kind === 'comment') {
            return (string) ($data['parent_uid'] ?? '') !== '';
        }
        if ($kind !== 'post') {
            return false;
        }
        if ((string) ($data['parent_uid'] ?? '') !== '') {
            return true;
        }
        if (strpos((string) ($data['content'] ?? ''), '{{FSYNC_REF:post:') !== false) {
            return true;
        }
        return self::contains_reference((array) ($data['meta'] ?? array()));
    }

    private static function contains_reference(array $value)
    {
        if (isset($value['fsync_ref'], $value['uids'])) {
            return true;
        }
        foreach ($value as $child) {
            if (is_array($child) && self::contains_reference($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param int $id
     * @param array $rules
     * @param array $scope
     * @return array|WP_Error
     */
    public static function post($id, array $rules, array $scope)
    {
        $post = get_post((int) $id);
        if (! $post) {
            return new WP_Error('fsync_post_missing', '投稿が存在しません。');
        }

        $uid = Fsync_Identity::uid('post', (int) $post->ID);
        if (is_wp_error($uid)) {
            return $uid;
        }

        $parent_uid = '';
        if ((int) $post->post_parent > 0) {
            $parent_uid = Fsync_Identity::uid('post', (int) $post->post_parent);
            if (is_wp_error($parent_uid)) {
                return $parent_uid;
            }
        }

        $author = get_userdata((int) $post->post_author);
        $taxonomies = array();
        foreach ((array) ($rules['taxonomies'] ?? get_object_taxonomies((string) $post->post_type)) as $taxonomy) {
            $term_ids = wp_get_object_terms((int) $post->ID, (string) $taxonomy, array('fields' => 'ids'));
            if (is_wp_error($term_ids)) {
                return $term_ids;
            }
            $uids = array();
            foreach ((array) $term_ids as $term_id) {
                $term_uid = Fsync_Identity::uid('term', (int) $term_id);
                if (is_wp_error($term_uid)) {
                    return $term_uid;
                }
                $uids[] = $term_uid;
            }
            sort($uids, SORT_STRING);
            $taxonomies[(string) $taxonomy] = $uids;
        }

        $content = self::normalize_content((string) $post->post_content);
        if (is_wp_error($content)) {
            return $content;
        }
        $data = array(
            'post_type' => (string) $post->post_type,
            'status' => (string) $post->post_status,
            'title' => (string) $post->post_title,
            'slug' => (string) $post->post_name,
            'content' => $content,
            'excerpt' => (string) $post->post_excerpt,
            'date_gmt' => (string) $post->post_date_gmt,
            'modified_gmt' => (string) $post->post_modified_gmt,
            'parent_uid' => $parent_uid,
            'menu_order' => (int) $post->menu_order,
            'mime_type' => (string) $post->post_mime_type,
            'password' => (string) $post->post_password,
            'author_login' => $author ? (string) $author->user_login : '',
            'comment_status' => (string) $post->comment_status,
            'ping_status' => (string) $post->ping_status,
            'taxonomies' => $taxonomies,
            'meta' => self::meta('post', (int) $post->ID, (array) ($rules['meta'] ?? array('mode' => 'all')), $scope),
        );

        if ((string) $post->post_type === 'attachment') {
            $relative = (string) get_post_meta((int) $post->ID, '_wp_attached_file', true);
            $data['attachment'] = array(
                'relative_path' => $relative,
                'alt' => (string) get_post_meta((int) $post->ID, '_wp_attachment_image_alt', true),
                'metadata' => wp_get_attachment_metadata((int) $post->ID),
            );
        }

        return self::record('post', $uid, $data);
    }

    /** @return array|WP_Error */
    public static function term($term, array $rules)
    {
        $uid = Fsync_Identity::uid('term', (int) $term->term_id);
        if (is_wp_error($uid)) {
            return $uid;
        }
        $parent_uid = '';
        if ((int) $term->parent > 0) {
            $parent_uid = Fsync_Identity::uid('term', (int) $term->parent);
            if (is_wp_error($parent_uid)) {
                return $parent_uid;
            }
        }

        return self::record(
            'term',
            $uid,
            array(
                'taxonomy' => (string) $term->taxonomy,
                'name' => (string) $term->name,
                'slug' => (string) $term->slug,
                'description' => (string) $term->description,
                'parent_uid' => $parent_uid,
                'meta' => self::meta('term', (int) $term->term_id, (array) ($rules['meta'] ?? array('mode' => 'all')), array()),
            )
        );
    }

    /** @return array|WP_Error */
    public static function comment($comment)
    {
        $uid = Fsync_Identity::uid('comment', (int) $comment->comment_ID);
        if (is_wp_error($uid)) {
            return $uid;
        }
        $post_uid = Fsync_Identity::uid('post', (int) $comment->comment_post_ID);
        if (is_wp_error($post_uid)) {
            return $post_uid;
        }
        $parent_uid = '';
        if ((int) $comment->comment_parent > 0) {
            $parent_uid = Fsync_Identity::uid('comment', (int) $comment->comment_parent);
            if (is_wp_error($parent_uid)) {
                return $parent_uid;
            }
        }
        $user = (int) $comment->user_id > 0 ? get_userdata((int) $comment->user_id) : null;

        return self::record(
            'comment',
            $uid,
            array(
                'post_uid' => $post_uid,
                'parent_uid' => $parent_uid,
                'author_login' => $user ? (string) $user->user_login : '',
                'author_name' => (string) $comment->comment_author,
                'author_email' => (string) $comment->comment_author_email,
                'author_url' => (string) $comment->comment_author_url,
                'author_ip' => (string) $comment->comment_author_IP,
                'date_gmt' => (string) $comment->comment_date_gmt,
                'content' => (string) $comment->comment_content,
                'approved' => (string) $comment->comment_approved,
                'agent' => (string) $comment->comment_agent,
                'type' => (string) $comment->comment_type,
                'meta' => self::meta('comment', (int) $comment->comment_ID, array('mode' => 'all'), array()),
            )
        );
    }

    /** @return array|WP_Error */
    public static function user($user, array $rules)
    {
        $uid = Fsync_Identity::uid('user', (int) $user->ID);
        if (is_wp_error($uid)) {
            return $uid;
        }
        $meta = self::meta('user', (int) $user->ID, array('mode' => 'all'), array());
        foreach (array_keys($meta) as $key) {
            if (preg_match('/(session_tokens|capabilities|user_level)$/', (string) $key)) {
                unset($meta[$key]);
            }
        }

        $data = array(
            'login' => (string) $user->user_login,
            'nicename' => (string) $user->user_nicename,
            'display_name' => (string) $user->display_name,
            'email' => (string) $user->user_email,
            'url' => (string) $user->user_url,
            'registered' => (string) $user->user_registered,
            'roles' => array_values((array) $user->roles),
            'meta' => $meta,
        );
        if (! empty($rules['passwords'])) {
            $data['password_hash'] = (string) $user->user_pass;
        }

        return self::record('user', $uid, $data);
    }

    /** @return array|null */
    public static function option($name)
    {
        $name = (string) $name;
        if ($name === '' || Fsync_Config::is_protected_option($name)) {
            return null;
        }

        return self::record('option', substr(hash('sha256', $name), 0, 32), array('name' => $name, 'value' => get_option($name)));
    }

    /** @return array|WP_Error */
    public static function table(array $config)
    {
        global $wpdb;

        $name = (string) ($config['name'] ?? '');
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1 || in_array($name, Fsync_Config::PROTECTED_TABLES, true)) {
            return new WP_Error('fsync_table_invalid', '独自テーブル名が不正または保護対象です。');
        }
        $uid_column = (string) ($config['uid_column'] ?? '');
        $primary = (string) ($config['primary_key'] ?? '');
        $natural = array_values((array) ($config['natural_key'] ?? array()));
        if ($uid_column === '' && $primary === '' && $natural === array()) {
            return new WP_Error('fsync_table_identity_missing', sprintf('独自テーブル %s に安定キーがありません。', $name));
        }

        $physical = $wpdb->prefix . $name;
        $description = $wpdb->get_results("DESCRIBE `{$physical}`", ARRAY_A);
        if (! is_array($description) || $description === array()) {
            return new WP_Error('fsync_table_read_failed', sprintf('独自テーブル %s の列定義を読み取れません。', $name));
        }
        $columns = array_fill_keys(array_map('strval', wp_list_pluck($description, 'Field')), true);
        $configured_columns = array_merge(
            array_filter(array($uid_column, $primary), 'strlen'),
            $natural,
            array_keys((array) ($config['refs'] ?? array())),
            array_keys((array) ($config['portable'] ?? array()))
        );
        foreach ($configured_columns as $column) {
            if (preg_match('/^[A-Za-z0-9_]+$/', (string) $column) !== 1 || ! isset($columns[$column])) {
                return new WP_Error('fsync_table_column_missing', sprintf('%s.%s が存在しないか列名が不正です。', $name, $column));
            }
        }
        $rows = $wpdb->get_results("SELECT * FROM `{$physical}`", ARRAY_A);
        if ($rows === null) {
            return new WP_Error('fsync_table_read_failed', sprintf('独自テーブル %s を読み取れません。', $name));
        }
        $records = array();
        $seen = array();
        foreach ((array) $rows as $row) {
            $strategy = '';
            $identity_columns = array();
            if ($uid_column !== '' && Fsync_Identity::valid_uid((string) ($row[$uid_column] ?? ''))) {
                $strategy = 'uid';
                $identity_columns = array($uid_column);
            } elseif ($primary !== '' && array_key_exists($primary, $row) && (string) $row[$primary] !== '') {
                $strategy = 'primary';
                $identity_columns = array($primary);
            } elseif ($natural !== array()) {
                $strategy = 'natural';
                $identity_columns = $natural;
            } else {
                return new WP_Error('fsync_table_identity_missing', sprintf('独自テーブル %s に利用できる安定キーがありません。', $name));
            }
            $identity = array();
            foreach ($identity_columns as $column) {
                if (! array_key_exists($column, $row) || $row[$column] === null || (string) $row[$column] === '') {
                    return new WP_Error('fsync_table_identity_missing', sprintf('%s.%s の安定キー値が空です。', $name, $column));
                }
                $identity[$column] = $row[$column];
            }
            $uid_value = $strategy === 'uid' ? (string) $row[$uid_column] : '';
            $uid = Fsync_Identity::valid_uid($uid_value)
                ? $uid_value
                : substr(hash('sha256', $name . "\n" . (string) wp_json_encode(Fsync_Utils::canonicalize($identity), Fsync_Utils::JSON_FLAGS)), 0, 32);
            $identity_hash = hash('sha256', $name . "\n" . (string) wp_json_encode(Fsync_Utils::canonicalize($identity), Fsync_Utils::JSON_FLAGS));
            if (isset($seen[$uid]) || isset($seen[$identity_hash])) {
                return new WP_Error('fsync_table_identity_duplicate', sprintf('独自テーブル %s に重複する安定キーがあります。', $name));
            }
            $seen[$uid] = true;
            $seen[$identity_hash] = true;

            foreach ((array) ($config['refs'] ?? array()) as $column => $rules) {
                $rules = is_string($rules) ? array('kind' => $rules, 'shape' => 'scalar') : (array) $rules;
                $portable = self::reference_out((string) ($rules['kind'] ?? ''), (string) ($rules['shape'] ?? 'scalar'), $row[$column] ?? null);
                if (is_wp_error($portable)) {
                    return $portable;
                }
                $row[$column] = $portable;
            }
            foreach (array_keys((array) ($config['portable'] ?? array())) as $column) {
                $row[$column] = self::normalize_value($row[$column] ?? null);
            }
            // Auto-increment/local primary ids are environmental when a true
            // portable UID column owns identity. Omitting them keeps hashes
            // stable and lets the target retain or allocate its own value.
            if ($strategy === 'uid' && $primary !== '') {
                unset($row[$primary]);
            }
            $records[] = self::record(
                'table',
                $uid,
                array('table' => $name, 'identity_strategy' => $strategy, 'identity' => $identity, 'row' => $row, 'config' => $config),
                array(),
                false
            );
        }

        return $records;
    }

    /** @return array|WP_Error */
    public static function files(array $scope)
    {
        $rules = (array) ($scope['files'] ?? array());
        $roots = array();
        $files = array();
        if (! empty($rules['uploads'])) {
            $uploads = wp_upload_dir(null, false);
            if (! empty($uploads['error'])) {
                return new WP_Error('fsync_uploads_unavailable', (string) $uploads['error']);
            }
            $roots[] = array('category' => 'uploads', 'directory' => (string) $uploads['basedir'], 'prefix' => '', 'excludes' => array());
        }

        if (! empty($rules['theme'])) {
            $root = get_theme_root();
            $themes = $rules['theme'] === true ? array_keys(wp_get_themes()) : (array) $rules['theme'];
            foreach ($themes as $theme) {
                $safe_theme = Fsync_Utils::normalize_relative_path((string) $theme);
                if (is_wp_error($safe_theme) || strpos($safe_theme, '/') !== false) {
                    return new WP_Error('fsync_theme_path_invalid', 'テーマの選択値が不正です。');
                }
                $roots[] = array('category' => 'theme/' . $safe_theme, 'directory' => $root . '/' . $safe_theme, 'prefix' => '', 'excludes' => array());
            }
        }
        if (! empty($rules['plugins'])) {
            if ($rules['plugins'] === true) {
                $roots[] = array('category' => 'plugins', 'directory' => WP_PLUGIN_DIR, 'prefix' => '', 'excludes' => array(FSYNC_SLUG));
            } else {
                if (! function_exists('get_plugins')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $selected = array_fill_keys(array_map('strval', (array) $rules['plugins']), true);
                $directories = array();
                foreach (get_plugins() as $file => $plugin_data) {
                    unset($plugin_data);
                    $directory = dirname((string) $file);
                    $slug = $directory === '.' ? (string) $file : $directory;
                    if (! isset($selected[$slug]) || $directory === FSYNC_SLUG) {
                        continue;
                    }
                    if ($directory === '.') {
                        $files[] = array('category' => 'plugins', 'path' => (string) $file, 'absolute' => WP_PLUGIN_DIR . '/' . $file);
                    } else {
                        $directories[$directory] = true;
                    }
                }
                foreach (array_keys($directories) as $directory) {
                    $safe_plugin = Fsync_Utils::normalize_relative_path($directory);
                    if (is_wp_error($safe_plugin) || strpos($safe_plugin, '/') !== false) {
                        return new WP_Error('fsync_plugin_path_invalid', 'プラグインの選択値が不正です。');
                    }
                    $roots[] = array(
                        'category' => 'plugins',
                        'directory' => WP_PLUGIN_DIR . '/' . $safe_plugin,
                        'prefix' => $safe_plugin . '/',
                        'excludes' => array(),
                    );
                }
            }
        }
        if (! empty($rules['mu_plugins']) && defined('WPMU_PLUGIN_DIR') && is_dir(WPMU_PLUGIN_DIR)) {
            $roots[] = array('category' => 'mu-plugins', 'directory' => WPMU_PLUGIN_DIR, 'prefix' => '', 'excludes' => array('fsync-guard.php'));
        }
        if (($rules['core'] ?? false) === 'sync') {
            $roots[] = array('category' => 'core', 'directory' => ABSPATH, 'prefix' => '', 'excludes' => array('wp-content', 'wp-config.php', '.htaccess', 'web.config'));
        }

        $records = array();
        foreach ($roots as $root) {
            if (! is_dir($root['directory'])) {
                continue;
            }
            $entries = Fsync_Fs::walk($root['directory'], $root['excludes']);
            if (is_wp_error($entries)) {
                return $entries;
            }
            foreach ($entries as $entry) {
                if ($entry['type'] !== 'f') {
                    continue;
                }
                $files[] = array(
                    'category' => $root['category'],
                    'path' => $root['prefix'] . (string) $entry['path'],
                    'absolute' => $root['directory'] . '/' . $entry['path'],
                    'size' => (int) $entry['size'],
                );
            }
        }
        foreach ($files as $file) {
            if (! is_file($file['absolute'])) {
                continue;
            }
            $content_hash = Fsync_Store::import_file($file['absolute']);
            if (is_wp_error($content_hash)) {
                return $content_hash;
            }
            $category = (string) $file['category'];
            $relative = (string) $file['path'];
            $records[] = self::record(
                'file',
                substr(hash('sha256', $category . "\n" . $relative), 0, 32),
                array(
                    'category' => $category,
                    'path' => $relative,
                    'size' => isset($file['size']) ? (int) $file['size'] : (int) filesize($file['absolute']),
                    'content_hash' => $content_hash,
                ),
                array($content_hash)
            );
        }

        return $records;
    }

    /** Environment-independent code activation state, applied after files. */
    public static function runtime(array $scope = array())
    {
        $files = (array) ($scope['files'] ?? array());
        $plugin_rule = $files['plugins'] ?? false;
        $plugin_mode = $plugin_rule === true ? 'all' : (is_array($plugin_rule) && $plugin_rule !== array() ? 'selected' : 'none');
        $selected_plugins = $plugin_mode === 'selected' ? array_values(array_unique(array_map('strval', $plugin_rule))) : array();
        sort($selected_plugins, SORT_STRING);
        $active_plugins = array_values((array) get_option('active_plugins', array()));
        if ($plugin_mode === 'selected') {
            $active_plugins = array_values(array_filter($active_plugins, static function ($plugin) use ($selected_plugins) {
                return in_array(self::plugin_slug((string) $plugin), $selected_plugins, true);
            }));
        } elseif ($plugin_mode === 'none') {
            $active_plugins = array();
        }
        $active_plugins = array_values(array_filter($active_plugins, static function ($plugin) {
            return (string) $plugin !== plugin_basename(FSYNC_FILE);
        }));
        sort($active_plugins, SORT_STRING);

        $theme_rule = $files['theme'] ?? false;
        $theme_mode = $theme_rule === true ? 'all' : (is_array($theme_rule) && $theme_rule !== array() ? 'selected' : 'none');
        $managed_themes = $theme_mode === 'selected' ? array_values(array_unique(array_map('strval', $theme_rule))) : array();
        sort($managed_themes, SORT_STRING);
        $stylesheet = (string) get_option('stylesheet', '');
        if ($theme_mode === 'none' || ($theme_mode === 'selected' && ! in_array($stylesheet, $managed_themes, true))) {
            $stylesheet = '';
        }

        return self::record(
            'runtime',
            substr(hash('sha256', 'wordpress-runtime'), 0, 32),
            array(
                'plugins_mode' => $plugin_mode,
                'managed_plugins' => $selected_plugins,
                'active_plugins' => $active_plugins,
                'theme_mode' => $theme_mode,
                'managed_themes' => $managed_themes,
                'stylesheet' => $stylesheet,
                'template' => $stylesheet === '' ? '' : (string) get_option('template', ''),
                'wp_version' => (string) get_bloginfo('version'),
            )
        );
    }

    private static function plugin_slug($plugin)
    {
        $plugin = (string) $plugin;
        $directory = dirname($plugin);

        return $directory === '.' ? $plugin : $directory;
    }

    /** @return array */
    private static function record($kind, $uid, array $data, array $objects = array(), $normalize = true)
    {
        return array(
            'format_version' => self::FORMAT_VERSION,
            'kind' => (string) $kind,
            'uid' => (string) $uid,
            'data' => Fsync_Utils::canonicalize($normalize ? self::normalize_value($data) : $data),
            'objects' => array_values(array_unique($objects)),
        );
    }

    /**
     * Replace this site's environment URLs with portable tokens. Serialized
     * values are decoded structurally so string lengths remain correct.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function normalize_value($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::normalize_value($item);
            }

            return $value;
        }
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (function_exists('is_serialized') && is_serialized($value)) {
            $decoded = maybe_unserialize($value);
            if ($decoded !== $value) {
                return maybe_serialize(self::normalize_value($decoded));
            }
        }

        $uploads = wp_upload_dir(null, false);
        $pairs = array(
            (string) ($uploads['baseurl'] ?? '') => '{{FSYNC_UPLOADS}}',
            untrailingslashit(site_url('/')) => '{{FSYNC_SITE}}',
            untrailingslashit(home_url('/')) => '{{FSYNC_HOME}}',
        );
        uksort($pairs, static function ($left, $right) {
            return strlen($right) - strlen($left);
        });
        foreach ($pairs as $from => $token) {
            if ($from !== '') {
                $value = str_replace($from, $token, $value);
            }
        }

        return $value;
    }

    /** Restore portable URL tokens for the receiving environment. */
    public static function hydrate_value($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::hydrate_value($item);
            }

            return $value;
        }
        if (! is_string($value) || $value === '') {
            return $value;
        }
        if (function_exists('is_serialized') && is_serialized($value)) {
            $decoded = maybe_unserialize($value);
            if ($decoded !== $value) {
                return maybe_serialize(self::hydrate_value($decoded));
            }
        }

        $uploads = wp_upload_dir(null, false);

        return strtr(
            $value,
            array(
                '{{FSYNC_UPLOADS}}' => untrailingslashit((string) ($uploads['baseurl'] ?? '')),
                '{{FSYNC_SITE}}' => untrailingslashit(site_url('/')),
                '{{FSYNC_HOME}}' => untrailingslashit(home_url('/')),
            )
        );
    }

    /** Replace Gutenberg and common media CSS numeric ids with post UIDs. */
    public static function normalize_content($content)
    {
        $content = (string) $content;
        if ($content === '') {
            return '';
        }
        $error = null;
        $replace_id = static function ($matches) use (&$error) {
            $uid = Fsync_Identity::uid('post', (int) $matches[2]);
            if (is_wp_error($uid)) {
                $error = $uid;

                return $matches[0];
            }

            return $matches[1] . '{{FSYNC_REF:post:' . $uid . '}}';
        };
        $content = preg_replace_callback('/\b(wp-(?:image|attachment)-)([1-9][0-9]*)\b/', $replace_id, $content);
        if (is_wp_error($error)) {
            return $error;
        }

        if (function_exists('parse_blocks') && function_exists('serialize_blocks') && strpos($content, '<!-- wp:') !== false) {
            $blocks = parse_blocks($content);
            $changed = false;
            $blocks = self::normalize_blocks((array) $blocks, $changed, $error);
            if (is_wp_error($error)) {
                return $error;
            }
            if ($changed) {
                $content = serialize_blocks($blocks);
            }
        }

        return $content;
    }

    /** Resolve portable post UID tokens after all base identities exist. */
    public static function hydrate_content($content)
    {
        $missing = '';
        $hydrated = preg_replace_callback(
            '/\{\{FSYNC_REF:post:([a-f0-9-]{36})\}\}/',
            static function ($matches) use (&$missing) {
                $id = Fsync_Identity::local_id('post', (string) $matches[1]);
                if ($id <= 0) {
                    $missing = (string) $matches[1];

                    return $matches[0];
                }

                return (string) $id;
            },
            (string) $content
        );
        if ($missing !== '') {
            return new WP_Error('fsync_reference_unresolved', sprintf('本文中の投稿・メディア参照を解決できません: %s', $missing));
        }

        return (string) $hydrated;
    }

    /** @return array */
    public static function effective_scope(array $scope, $profile)
    {
        $introspect = Fsync_Introspect::report(array('include_options' => false, 'include_users' => false));
        if (empty($scope['post_types'])) {
            foreach ((array) $introspect['post_types'] as $slug => $info) {
                $scope['post_types'][$slug] = array(
                    'statuses' => array('publish', 'draft', 'pending', 'private', 'future', 'inherit'),
                    'meta' => array('mode' => 'all'),
                    'taxonomies' => (array) $info['taxonomies'],
                    'delete' => false,
                );
            }
        }
        if (empty($scope['taxonomies'])) {
            foreach (array_keys((array) $introspect['taxonomies']) as $slug) {
                $scope['taxonomies'][$slug] = array('meta' => array('mode' => 'all'), 'delete' => false);
            }
        }
        if ($profile === 'full') {
            $scope['comments'] = $scope['comments'] ?? true;
            $scope['users'] = array_merge(array('enabled' => true, 'passwords' => false, 'delete' => false), (array) ($scope['users'] ?? array()));
            $scope['files'] = array_merge(
                array('uploads' => true, 'theme' => true, 'plugins' => true, 'mu_plugins' => true, 'core' => 'sync'),
                (array) ($scope['files'] ?? array())
            );
        }

        return $scope;
    }

    private static function normalize_blocks(array $blocks, &$changed, &$error)
    {
        $id_keys = array(
            'core/image' => array('id'),
            'core/gallery' => array('ids'),
            'core/media-text' => array('mediaId'),
            'core/cover' => array('id'),
            'core/video' => array('id', 'poster'),
            'core/audio' => array('id'),
            'core/file' => array('id'),
            'core/navigation' => array('ref'),
            'core/block' => array('ref'),
        );
        foreach ($blocks as &$block) {
            $name = (string) ($block['blockName'] ?? '');
            foreach ((array) ($id_keys[$name] ?? array()) as $attribute) {
                if (! array_key_exists($attribute, (array) ($block['attrs'] ?? array()))) {
                    continue;
                }
                $value = $block['attrs'][$attribute];
                $ids = is_array($value) ? $value : array($value);
                $portable = array();
                foreach ($ids as $id) {
                    if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                        $portable[] = $id;
                        continue;
                    }
                    $uid = Fsync_Identity::uid('post', (int) $id);
                    if (is_wp_error($uid)) {
                        $error = $uid;

                        return $blocks;
                    }
                    $portable[] = '{{FSYNC_REF:post:' . $uid . '}}';
                    $changed = true;
                }
                $block['attrs'][$attribute] = is_array($value) ? $portable : $portable[0];
            }
            if (! empty($block['innerBlocks'])) {
                $block['innerBlocks'] = self::normalize_blocks((array) $block['innerBlocks'], $changed, $error);
                if (is_wp_error($error)) {
                    return $blocks;
                }
            }
        }
        unset($block);

        return $blocks;
    }

    /** @return array */
    private static function meta($kind, $id, array $rules, array $scope)
    {
        if (($rules['mode'] ?? 'all') === 'none') {
            return array();
        }
        if ($kind === 'post') {
            $all = get_post_meta($id);
        } elseif ($kind === 'term') {
            $all = get_term_meta($id);
        } elseif ($kind === 'comment') {
            $all = get_comment_meta($id);
        } else {
            $all = get_user_meta($id);
        }

        $out = array();
        $allow = (array) ($rules['allow'] ?? array());
        $deny = (array) ($rules['deny'] ?? array());
        foreach ((array) $all as $key => $values) {
            if (Fsync_Config::is_protected_meta($key)
                || (($rules['mode'] ?? 'all') === 'allow' && ! in_array($key, $allow, true))
                || in_array($key, $deny, true)) {
                continue;
            }
            $out[$key] = array_map('maybe_unserialize', (array) $values);
        }
        ksort($out, SORT_STRING);

        return self::references_out($out, (array) ($scope['refs'] ?? array()));
    }

    /** @return array */
    private static function references_out(array $meta, array $rules)
    {
        foreach ($rules as $key => $rule) {
            if (! array_key_exists($key, $meta)) {
                continue;
            }
            $rule = is_string($rule) ? array('kind' => $rule, 'shape' => 'scalar') : (array) $rule;
            $kind = (string) ($rule['kind'] ?? 'post');
            $shape = (string) ($rule['shape'] ?? 'scalar');
            foreach ($meta[$key] as $index => $value) {
                $ids = $shape === 'csv' ? explode(',', (string) $value) : ($shape === 'serialized_array' ? (array) $value : array($value));
                $uids = array();
                foreach ($ids as $id) {
                    $uid = Fsync_Identity::uid($kind, (int) $id, false);
                    $uids[] = is_wp_error($uid) ? null : $uid;
                }
                $meta[$key][$index] = array('fsync_ref' => $kind, 'shape' => $shape, 'uids' => $uids);
            }
        }

        return $meta;
    }

    /** Convert a configured custom-table ID field into portable UIDs. */
    private static function reference_out($kind, $shape, $value)
    {
        if (! in_array($kind, array('post', 'term', 'user'), true)
            || ! in_array($shape, array('scalar', 'csv', 'serialized_array'), true)) {
            return new WP_Error('fsync_table_reference_invalid', '独自テーブルの参照設定が不正です。');
        }
        if ($shape === 'csv') {
            $ids = $value === '' || $value === null ? array() : array_map('trim', explode(',', (string) $value));
        } elseif ($shape === 'serialized_array') {
            $decoded = maybe_unserialize($value);
            $ids = is_array($decoded) ? $decoded : array();
        } else {
            $ids = array($value);
        }
        $uids = array();
        foreach ($ids as $id) {
            if ($id === null || (int) $id <= 0) {
                $uids[] = null;
                continue;
            }
            $uid = Fsync_Identity::uid($kind, (int) $id);
            if (is_wp_error($uid)) {
                return $uid;
            }
            $uids[] = $uid;
        }

        return array('fsync_ref' => $kind, 'shape' => $shape, 'uids' => $uids);
    }

    /** @return array */
    private static function option_names($pattern)
    {
        global $wpdb;

        $pattern = (string) $pattern;
        if (strlen($pattern) > 2 && $pattern[0] === '/' && substr($pattern, -1) === '/') {
            $names = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} ORDER BY option_name ASC");

            return array_values(array_filter((array) $names, static function ($name) use ($pattern) {
                return @preg_match($pattern, (string) $name) === 1;
            }));
        }

        return array($pattern);
    }
}
