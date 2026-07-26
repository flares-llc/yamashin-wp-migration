<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Where the configuration document comes from, and how it is merged.
 *
 * The document is the single source of truth and is expected to be authored by
 * an agent and committed to the project repository. When a file is present it
 * wins over the database copy, and the admin builder becomes read-only, so that
 * a screen cannot silently diverge from what is in version control.
 *
 * JSONC is accepted because a configuration an agent writes benefits enormously
 * from being able to explain itself in comments.
 */
final class Fsync_Config_Io
{
    const OPTION_DOCUMENT = 'fsync_config';
    const OPTION_ACTIVE_ENV = 'fsync_active_env';

    const SOURCE_FILE = 'file';
    const SOURCE_DB = 'db';
    const SOURCE_DEFAULT = 'default';

    /**
     * Empty PHP arrays at these locations are JSON objects, not JSON lists.
     * PHP loses that distinction after json_decode(..., true), so restore it
     * when presenting an editable document that is expected to match the schema.
     */
    const JSON_OBJECT_PATHS = array(
        '/',
        '/sync',
        '/sync/scope',
        '/sync/scope/post_types',
        '/sync/scope/taxonomies',
        '/sync/scope/options',
        '/sync/scope/files',
        '/sync/scope/refs',
        '/sync/scope/authors',
        '/sync/scope/authors/map',
        '/sync/scope_overrides',
        '/sync/policy',
        '/environments',
        '/environment_overrides',
        '/backup',
        '/backup/retention',
        '/storage',
        '/notify',
    );

    /** Dynamic map entries whose empty values must also remain JSON objects. */
    const JSON_OBJECT_PATH_PATTERNS = array(
        '#^/sync/scope/post_types/[^/]+(?:/meta)?$#',
        '#^/sync/scope/taxonomies/[^/]+(?:/meta)?$#',
        '#^/sync/scope/refs/[^/]+$#',
        '#^/sync/scope/tables/[0-9]+(?:/(?:refs|portable))?$#',
        '#^/sync/scope_overrides/[^/]+(?:/(?:post_types|taxonomies|options|files|refs|authors))?$#',
        '#^/sync/scope_overrides/[^/]+/(?:post_types|taxonomies)/[^/]+(?:/meta)?$#',
        '#^/environments/[^/]+$#',
        '#^/environment_overrides/[^/]+$#',
        '#^/storage/[^/]+$#',
        '#^/schedules/[0-9]+$#',
        '#^/notify/[^/]+$#',
    );

    /** Base filename looked for in each candidate directory. */
    const FILENAMES = array(
        'flares-sync.config.jsonc',
        'flares-sync.config.json',
    );

    /** @var array|null */
    private static $cache = null;

    /**
     * Resolve the effective document.
     *
     * @return array{document: array, source: string, path: string, error: WP_Error|null}
     */
    public static function load()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $path = self::locate();

        if ($path !== '') {
            $parsed = self::read_file($path);

            if (is_wp_error($parsed)) {
                // A broken config file must not silently fall back to the
                // database copy: the operator would be editing one document and
                // running another.
                self::$cache = array(
                    'document' => array(),
                    'source' => self::SOURCE_FILE,
                    'path' => $path,
                    'error' => $parsed,
                );

                return self::$cache;
            }

            self::$cache = array(
                'document' => $parsed,
                'source' => self::SOURCE_FILE,
                'path' => $path,
                'error' => null,
            );

            return self::$cache;
        }

        $stored = get_option(self::OPTION_DOCUMENT, null);
        if (is_array($stored) && $stored !== array()) {
            self::$cache = array(
                'document' => $stored,
                'source' => self::SOURCE_DB,
                'path' => '',
                'error' => null,
            );

            return self::$cache;
        }

        self::$cache = array(
            'document' => array(),
            'source' => self::SOURCE_DEFAULT,
            'path' => '',
            'error' => null,
        );

        return self::$cache;
    }

    /**
     * @return void
     */
    public static function flush()
    {
        self::$cache = null;
    }

    /**
     * Whether the effective document comes from a file, making the builder
     * read-only.
     *
     * @return bool
     */
    public static function is_file_backed()
    {
        return self::load()['source'] === self::SOURCE_FILE;
    }

    /**
     * Find the configuration file, if any.
     *
     * @return string Absolute path, or an empty string.
     */
    public static function locate()
    {
        if (defined('FSYNC_CONFIG_FILE')) {
            // An explicitly configured path is authoritative even when it is
            // missing or unreadable. Returning it lets load() fail closed and
            // tell the operator which file is wrong instead of silently using
            // an unrelated database copy.
            return trim((string) constant('FSYNC_CONFIG_FILE'));
        }

        $directories = array(
            untrailingslashit(str_replace('\\', '/', WP_CONTENT_DIR)),
            untrailingslashit(str_replace('\\', '/', ABSPATH)),
            dirname(untrailingslashit(str_replace('\\', '/', ABSPATH))),
        );

        foreach ($directories as $directory) {
            foreach (self::FILENAMES as $filename) {
                $candidate = $directory . '/' . $filename;
                if (file_exists($candidate)) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    /**
     * @param string $path
     * @return array|WP_Error
     */
    public static function read_file($path)
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return new WP_Error(
                'fsync_config_unreadable',
                sprintf('設定ファイルを読み取れません: %s', $path)
            );
        }

        return self::parse($raw);
    }

    /**
     * Parse JSON or JSONC into an array.
     *
     * @param string $raw
     * @return array|WP_Error
     */
    public static function parse($raw)
    {
        $json = self::strip_jsonc((string) $raw);

        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error(
                'fsync_config_parse_failed',
                sprintf('設定を解析できません: %s', json_last_error_msg())
            );
        }

        // json_decode(..., true) maps both JSON objects and arrays to PHP
        // arrays. Inspect the JSON itself so a top-level list cannot masquerade
        // as the configuration object the rest of the code expects.
        $trimmed = ltrim($json);
        if (! is_array($decoded) || $trimmed === '' || $trimmed[0] !== '{') {
            return new WP_Error('fsync_config_not_object', '設定はJSONオブジェクトである必要があります。');
        }

        return $decoded;
    }

    /**
     * Encode a configuration for the editor without turning empty maps into [].
     *
     * @param array $document
     * @return string|WP_Error
     */
    public static function pretty(array $document)
    {
        $encoded = json_encode(
            self::restore_json_shapes($document, '/'),
            JSON_PRETTY_PRINT | Fsync_Utils::JSON_FLAGS
        );

        if ($encoded === false) {
            return new WP_Error(
                'fsync_json_encode_failed',
                sprintf('データをJSONに変換できません: %s', json_last_error_msg())
            );
        }

        return $encoded;
    }

    /**
     * @param mixed $node
     * @param string $path
     * @return mixed
     */
    private static function restore_json_shapes($node, $path)
    {
        if (! is_array($node)) {
            return $node;
        }

        if ($node === array() && self::is_json_object_path($path)) {
            return (object) array();
        }

        foreach ($node as $key => $value) {
            $child = $path === '/'
                ? '/' . (string) $key
                : $path . '/' . (string) $key;
            $node[$key] = self::restore_json_shapes($value, $child);
        }

        return $node;
    }

    /**
     * @param string $path
     * @return bool
     */
    private static function is_json_object_path($path)
    {
        if (in_array($path, self::JSON_OBJECT_PATHS, true)) {
            return true;
        }

        foreach (self::JSON_OBJECT_PATH_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove comments and trailing commas.
     *
     * Written as a scanner rather than a regular expression on purpose. Every
     * naive implementation of this mangles the "//" inside a URL, and this
     * configuration is full of URLs.
     *
     * @param string $raw
     * @return string
     */
    public static function strip_jsonc($raw)
    {
        $length = strlen($raw);
        $out = '';
        $in_string = false;
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];

            if ($in_string) {
                $out .= $char;

                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $in_string = false;
                }

                continue;
            }

            if ($char === '"') {
                $in_string = true;
                $out .= $char;

                continue;
            }

            $next = $i + 1 < $length ? $raw[$i + 1] : '';

            if ($char === '/' && $next === '/') {
                while ($i < $length && $raw[$i] !== "\n") {
                    $i++;
                }
                // Keep the newline so that line numbers survive, which matters
                // for the JSON Pointer positions reported by validation.
                $out .= "\n";

                continue;
            }

            if ($char === '/' && $next === '*') {
                $i += 2;
                while ($i + 1 < $length && ! ($raw[$i] === '*' && $raw[$i + 1] === '/')) {
                    if ($raw[$i] === "\n") {
                        $out .= "\n";
                    }
                    $i++;
                }

                if ($i + 1 >= $length) {
                    // Leave an invalid token behind. Silently discarding an
                    // unterminated comment could turn a truncated file into a
                    // valid-looking document and apply it.
                    $out .= '/*';
                    break;
                }

                $i++;

                continue;
            }

            $out .= $char;
        }

        return self::strip_trailing_commas($out);
    }

    /**
     * @param string $json
     * @return string
     */
    private static function strip_trailing_commas($json)
    {
        $length = strlen($json);
        $out = '';
        $in_string = false;
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($in_string) {
                $out .= $char;

                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $in_string = false;
                }

                continue;
            }

            if ($char === '"') {
                $in_string = true;
                $out .= $char;

                continue;
            }

            if ($char === ',') {
                // Look ahead past whitespace for a closing bracket.
                $j = $i + 1;
                while ($j < $length && ctype_space($json[$j])) {
                    $j++;
                }

                if ($j < $length && ($json[$j] === '}' || $json[$j] === ']')) {
                    continue;
                }
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * Persist a document to the database.
     *
     * @param array $document
     * @param string $note
     * @return true|WP_Error
     */
    public static function save(array $document, $note = '')
    {
        if (self::is_file_backed()) {
            return new WP_Error(
                'fsync_config_file_backed',
                '設定ファイルが存在するため、管理画面からは保存できません。ファイルを編集してください。'
            );
        }

        $loaded = self::load();
        $previous = $loaded['document'];

        // WordPress reports false both for failures and for no-op updates. A
        // no-op needs neither a misleading history entry nor an error.
        if ($previous === $document) {
            return true;
        }

        $history_id = self::record_history($previous, $loaded['source'], $note);
        if (is_wp_error($history_id)) {
            return $history_id;
        }

        if (! update_option(self::OPTION_DOCUMENT, $document, false)) {
            global $wpdb;

            // The apply did not happen, so its rollback checkpoint must not be
            // shown as a real configuration change.
            $wpdb->delete(Fsync_Schema::table('config_history'), array('id' => $history_id));

            return new WP_Error('fsync_config_write_failed', '設定をデータベースへ保存できませんでした。');
        }

        self::flush();

        return true;
    }

    /**
     * @param array $document
     * @param string $source
     * @param string $note
     * @return int|WP_Error Inserted history id.
     */
    public static function record_history(array $document, $source, $note = '')
    {
        global $wpdb;

        $encoded = Fsync_Utils::encode($document);
        if (is_wp_error($encoded)) {
            return $encoded;
        }

        $hash = Fsync_Utils::canonical_hash($document);
        if (is_wp_error($hash)) {
            return $hash;
        }

        $inserted = $wpdb->insert(
            Fsync_Schema::table('config_history'),
            array(
                'ts' => Fsync_Utils::now(),
                'source' => (string) $source,
                'config_hash' => $hash,
                'document' => $encoded,
                'note' => substr((string) $note, 0, 255),
                'user_id' => (int) get_current_user_id(),
            )
        );

        if ($inserted === false) {
            return new WP_Error('fsync_config_history_failed', '設定の変更履歴を保存できませんでした。');
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * @param int $limit
     * @return array<int, array>
     */
    public static function history($limit = 20)
    {
        global $wpdb;

        $table = Fsync_Schema::table('config_history');
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, ts, source, config_hash, note, user_id FROM {$table} ORDER BY id DESC LIMIT %d",
                max(1, min(200, (int) $limit))
            ),
            ARRAY_A
        );

        return $rows;
    }

    /**
     * @param int $id
     * @return array|WP_Error
     */
    public static function history_document($id)
    {
        global $wpdb;

        $table = Fsync_Schema::table('config_history');
        $document = $wpdb->get_var(
            $wpdb->prepare("SELECT document FROM {$table} WHERE id = %d", (int) $id)
        );

        if ($document === null) {
            return new WP_Error('fsync_config_history_missing', '指定した設定履歴が見つかりません。');
        }

        return Fsync_Utils::decode((string) $document);
    }

    /**
     * Deep-merge an overlay into a base document.
     *
     * Maps merge key by key; lists replace wholesale. Replacing lists is the
     * behaviour an author expects from an override -- appending would make it
     * impossible to shorten an allowlist in a specific environment.
     *
     * @param array $base
     * @param array $overlay
     * @return array
     */
    public static function merge(array $base, array $overlay)
    {
        foreach ($overlay as $key => $value) {
            if (
                is_array($value)
                && ! Fsync_Utils::is_list($value)
                && isset($base[$key])
                && is_array($base[$key])
                && ! Fsync_Utils::is_list($base[$key])
            ) {
                $base[$key] = self::merge($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * The environment name this site considers itself to be.
     *
     * @return string
     */
    public static function active_env()
    {
        $document = self::load()['document'];

        $explicit = (string) get_option(self::OPTION_ACTIVE_ENV, '');
        if ($explicit !== '') {
            return $explicit;
        }

        return (string) ($document['site_role'] ?? '');
    }

    /**
     * @param string $env_name
     * @return void
     */
    public static function set_active_env($env_name)
    {
        update_option(self::OPTION_ACTIVE_ENV, (string) $env_name, false);
        self::flush();
    }
}
