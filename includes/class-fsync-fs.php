<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Filesystem access for private storage, atomic writes and directory walking.
 *
 * Every path that arrives from a peer passes through resolve() so that a
 * crafted relative path cannot reach outside the directory it belongs to.
 */
final class Fsync_Fs
{
    /** Directory name under wp-content that holds all plugin state. */
    const PRIVATE_DIR = '.flares-sync';

    /**
     * Directories and files never included in a walk, regardless of config.
     *
     * The plugin's own storage is first and is deliberately not configurable:
     * including it would make a backup contain every previous backup.
     */
    const HARD_EXCLUDES = array(
        self::PRIVATE_DIR,
        '.git',
        '.svn',
        '.hg',
        'node_modules',
        '.DS_Store',
        'Thumbs.db',
    );

    /**
     * Absolute path to private storage.
     *
     * @param string $sub Optional sub path, validated as relative.
     * @return string
     */
    public static function private_dir($sub = '')
    {
        $base = untrailingslashit(str_replace('\\', '/', WP_CONTENT_DIR)) . '/' . self::PRIVATE_DIR;

        if ($sub === '') {
            return $base;
        }

        $relative = Fsync_Utils::normalize_relative_path($sub);
        if (is_wp_error($relative)) {
            return $base;
        }

        return $base . '/' . $relative;
    }

    /**
     * Create private storage and make it unreachable over HTTP.
     *
     * Both guards are written because neither is universally effective: the
     * .htaccess rule does nothing on nginx, and the index.php only prevents
     * directory listing. Hosts that serve dotfiles and run nginx need the
     * storage moved outside the webroot, which the health panel reports.
     *
     * @return true|WP_Error
     */
    public static function ensure_private_storage()
    {
        $dir = self::private_dir();

        if (! wp_mkdir_p($dir)) {
            return new WP_Error(
                'fsync_storage_failed',
                sprintf('保存領域を作成できません: %s', $dir)
            );
        }

        $htaccess = $dir . '/.htaccess';
        if (! file_exists($htaccess)) {
            self::write_atomic($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }

        $index = $dir . '/index.php';
        if (! file_exists($index)) {
            self::write_atomic($index, "<?php\n// Silence is golden.\n");
        }

        foreach (array('objects', 'releases', 'backups', 'jobs', 'tmp', 'snapshots') as $child) {
            wp_mkdir_p($dir . '/' . $child);
        }

        return true;
    }

    /**
     * Whether private storage is reachable from the web.
     *
     * Reported rather than enforced: a false positive here would block the
     * plugin on hosts where the request simply fails for unrelated reasons.
     *
     * @return bool|null Null when the check could not be performed.
     */
    public static function private_storage_is_exposed()
    {
        $url = content_url(self::PRIVATE_DIR . '/index.php');

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 5,
                'redirection' => 0,
                'sslverify' => false,
            )
        );

        if (is_wp_error($response)) {
            return null;
        }

        return (int) wp_remote_retrieve_response_code($response) === 200;
    }

    /**
     * Resolve a relative path against a base directory, refusing escapes.
     *
     * @param string $base Absolute directory.
     * @param string $relative
     * @return string|WP_Error Absolute path.
     */
    public static function resolve($base, $relative)
    {
        $normalized = Fsync_Utils::normalize_relative_path($relative);
        if (is_wp_error($normalized)) {
            return $normalized;
        }

        $base = untrailingslashit(str_replace('\\', '/', $base));
        $candidate = $base . '/' . $normalized;

        // normalize_relative_path already rejects traversal, but a symlink in
        // the middle of the path could still redirect the result, so the real
        // location is verified whenever it already exists.
        $existing = file_exists($candidate) ? realpath($candidate) : null;
        if ($existing !== null && $existing !== false) {
            $real_base = realpath($base);
            $existing = str_replace('\\', '/', $existing);
            $real_base = $real_base === false ? $base : str_replace('\\', '/', $real_base);

            if ($existing !== $real_base && strpos($existing, $real_base . '/') !== 0) {
                return new WP_Error('fsync_path_escape', 'ファイルパスが対象ディレクトリの外を指しています。');
            }
        }

        return $candidate;
    }

    /**
     * Write a file atomically: a reader never observes a partial file, and a
     * crash mid-write leaves the previous version intact.
     *
     * @param string $path
     * @param string $contents
     * @return true|WP_Error
     */
    public static function write_atomic($path, $contents)
    {
        $dir = dirname($path);
        if (! wp_mkdir_p($dir)) {
            return new WP_Error('fsync_mkdir_failed', sprintf('ディレクトリを作成できません: %s', $dir));
        }

        $tmp = $path . '.' . wp_generate_password(8, false, false) . '.tmp';

        $handle = @fopen($tmp, 'wb');
        if ($handle === false) {
            return new WP_Error('fsync_write_failed', sprintf('ファイルを書き込めません: %s', $path));
        }

        $written = fwrite($handle, $contents);
        if ($written === false || $written < strlen($contents)) {
            fclose($handle);
            @unlink($tmp);

            return new WP_Error('fsync_write_incomplete', sprintf('ファイルを最後まで書き込めません: %s', $path));
        }

        fflush($handle);
        fclose($handle);

        if (! @rename($tmp, $path)) {
            @unlink($tmp);

            return new WP_Error('fsync_rename_failed', sprintf('ファイルを確定できません: %s', $path));
        }

        return true;
    }

    /**
     * @param string $path
     * @param mixed $value
     * @return true|WP_Error
     */
    public static function write_json($path, $value)
    {
        $encoded = Fsync_Utils::encode($value);
        if (is_wp_error($encoded)) {
            return $encoded;
        }

        return self::write_atomic($path, $encoded);
    }

    /**
     * @param string $path
     * @return mixed|WP_Error
     */
    public static function read_json($path)
    {
        if (! is_readable($path)) {
            return new WP_Error('fsync_read_failed', sprintf('ファイルを読み取れません: %s', $path));
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return new WP_Error('fsync_read_failed', sprintf('ファイルを読み取れません: %s', $path));
        }

        return Fsync_Utils::decode($raw);
    }

    /**
     * Walk a directory tree, yielding relative paths.
     *
     * Symlinks are reported but never followed: following them has been the
     * cause of backups that tried to archive the whole filesystem.
     *
     * @param string $dir Absolute directory.
     * @param array $excludes Additional relative path fragments to skip.
     * @return array<int, array{path: string, size: int, mtime: int, type: string}>|WP_Error
     */
    public static function walk($dir, $excludes = array())
    {
        $dir = untrailingslashit(str_replace('\\', '/', $dir));
        if (! is_dir($dir)) {
            return new WP_Error('fsync_dir_missing', sprintf('ディレクトリがありません: %s', $dir));
        }

        $patterns = self::exclude_patterns($excludes);
        $entries = array();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $relative = ltrim(
                str_replace('\\', '/', substr($file->getPathname(), strlen($dir))),
                '/'
            );

            if ($relative === '' || self::is_excluded($relative, $patterns)) {
                continue;
            }

            if ($file->isLink()) {
                $entries[] = array(
                    'path' => $relative,
                    'size' => 0,
                    'mtime' => 0,
                    'type' => 'l',
                );
                continue;
            }

            if (! $file->isFile()) {
                continue;
            }

            $entries[] = array(
                'path' => $relative,
                'size' => (int) $file->getSize(),
                'mtime' => (int) $file->getMTime(),
                'type' => 'f',
            );
        }

        usort(
            $entries,
            static function ($left, $right) {
                return strcmp($left['path'], $right['path']);
            }
        );

        return $entries;
    }

    /**
     * @param array $excludes
     * @return array<int, string>
     */
    private static function exclude_patterns($excludes)
    {
        $patterns = self::HARD_EXCLUDES;

        foreach ((array) $excludes as $exclude) {
            $exclude = trim((string) $exclude);
            if ($exclude !== '') {
                $patterns[] = $exclude;
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * @param string $relative
     * @param array<int, string> $patterns
     * @return bool
     */
    private static function is_excluded($relative, $patterns)
    {
        $segments = explode('/', $relative);

        foreach ($patterns as $pattern) {
            if (strpos($pattern, '/') !== false) {
                if ($relative === $pattern || strpos($relative, rtrim($pattern, '/') . '/') === 0) {
                    return true;
                }
                continue;
            }

            if (in_array($pattern, $segments, true)) {
                return true;
            }
        }

        return str_ends_with($relative, '~');
    }

    /**
     * Recursively delete a directory that lives inside private storage.
     *
     * The containment check is not defensive programming for its own sake: this
     * function is reachable from cleanup paths that take an identifier from a
     * request, and an unconstrained recursive delete is the worst possible bug
     * to ship in a plugin that also has write access to wp-content.
     *
     * @param string $path
     * @return bool
     */
    public static function delete_private_tree($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        $base = self::private_dir();

        if ($path !== $base && strpos($path, $base . '/') !== 0) {
            return false;
        }

        if (is_link($path)) {
            return (bool) @unlink($path);
        }

        if (is_file($path)) {
            return (bool) @unlink($path);
        }

        if (! is_dir($path)) {
            return true;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
                continue;
            }

            @rmdir($item->getPathname());
        }

        return (bool) @rmdir($path);
    }

    /**
     * Verify there is room before starting work that writes a lot of data.
     *
     * Filling the volume on shared hosting takes the whole site down, which is
     * a far worse outcome than refusing to start a backup.
     *
     * @param int $required_bytes
     * @param float $headroom Fraction of free space to leave untouched.
     * @return true|WP_Error
     */
    public static function ensure_space($required_bytes, $headroom = 0.2)
    {
        $free = Fsync_Env::free_disk_bytes();
        if ($free <= 0) {
            // The host forbids the check; proceed rather than block everything.
            return true;
        }

        $usable = (int) ($free * (1 - $headroom));
        if ($required_bytes > $usable) {
            return new WP_Error(
                'fsync_insufficient_space',
                sprintf(
                    'ディスク空き容量が不足しています。必要: %s / 利用可能: %s',
                    size_format($required_bytes),
                    size_format($usable)
                )
            );
        }

        return true;
    }
}
