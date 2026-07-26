<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Probes the runtime so that every other component can adapt to it instead of
 * assuming a generous host.
 *
 * The values here drive chunk size negotiation, batch budgets and the health
 * panel. They are deliberately computed rather than configured: the plugin is
 * meant to run on shared hosting whose limits the operator often does not know.
 */
final class Fsync_Env
{
    /**
     * Hosts that report no execution limit are still usually killed by a proxy
     * or FPM timeout, so an unlimited value is treated as this instead.
     */
    const ASSUMED_EXECUTION_TIME = 30;

    /**
     * Never claim more than this even when the host reports a large limit;
     * long-running requests are fragile regardless of what PHP allows.
     */
    const MAX_ASSUMED_EXECUTION_TIME = 120;

    /** Cache an advisory-lock capability probe across requests. */
    const TRANSIENT_GET_LOCK = 'fsync_supports_get_lock';
    const GET_LOCK_CACHE_TTL = HOUR_IN_SECONDS;

    /** @var array|null */
    private static $cache = null;

    /**
     * Full environment report.
     *
     * @return array
     */
    public static function report()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        global $wpdb;

        self::$cache = array(
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'plugin_version' => FSYNC_VERSION,
            'schema_version' => FSYNC_SCHEMA_VERSION,
            'hash_algo_version' => FSYNC_HASH_ALGO_VERSION,
            'is_multisite' => is_multisite(),
            'limits' => array(
                'max_execution_time' => self::execution_time(),
                'memory_limit' => self::memory_limit(),
                'upload_max_filesize' => self::bytes_from_ini('upload_max_filesize'),
                'post_max_size' => self::bytes_from_ini('post_max_size'),
                'suggested_chunk_bytes' => self::suggested_chunk_bytes(),
            ),
            'caps' => array(
                'openssl' => function_exists('openssl_encrypt') && function_exists('openssl_sign'),
                'sodium' => function_exists('sodium_crypto_sign_detached'),
                'zlib' => function_exists('gzdeflate'),
                'ziparchive' => class_exists('ZipArchive'),
                'curl' => function_exists('curl_version'),
                'random_bytes' => function_exists('random_bytes'),
                'hash_hkdf' => function_exists('hash_hkdf'),
                'get_lock' => self::supports_get_lock(),
                'object_cache' => wp_using_ext_object_cache(),
                'disable_wp_cron' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
                'alternate_wp_cron' => defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON,
            ),
            'db' => array(
                'server_version' => $wpdb->db_version(),
                'charset' => $wpdb->charset,
                'collate' => $wpdb->collate,
                'prefix' => $wpdb->prefix,
                'supports_utf8mb4' => self::supports_utf8mb4(),
            ),
            'site' => array(
                'home_url' => home_url('/'),
                'site_url' => site_url('/'),
                'uploads_baseurl' => self::uploads('baseurl'),
                'uploads_basedir' => self::uploads('basedir'),
                'abspath' => untrailingslashit(str_replace('\\', '/', ABSPATH)),
                'content_dir' => untrailingslashit(str_replace('\\', '/', WP_CONTENT_DIR)),
                'timezone' => wp_timezone_string(),
                'server_time_gmt' => Fsync_Utils::now(),
                'locale' => get_locale(),
            ),
        );

        return self::$cache;
    }

    /**
     * Reset the memoized report. Only useful in tests and long-running CLI.
     *
     * @return void
     */
    public static function flush()
    {
        self::$cache = null;
    }

    /**
     * Execution time we are willing to assume, in seconds.
     *
     * @return int
     */
    public static function execution_time()
    {
        $configured = (int) ini_get('max_execution_time');

        // 0 means "no limit" in PHP, which is almost never true end to end.
        if ($configured <= 0) {
            $configured = self::ASSUMED_EXECUTION_TIME;
        }

        return min($configured, self::MAX_ASSUMED_EXECUTION_TIME);
    }

    /**
     * Memory limit in bytes. PHP_INT_MAX when unlimited.
     *
     * @return int
     */
    public static function memory_limit()
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return PHP_INT_MAX;
        }

        return self::parse_size($raw);
    }

    /**
     * Largest request body we can reasonably send to this site.
     *
     * Both limits apply to an upload, and hosts are inconsistent about which
     * one bites first, so the smaller is used with headroom for headers. The
     * result is rounded down to a 256 KiB boundary because Google's resumable
     * upload APIs reject intermediate chunks that are not a multiple of it,
     * and reusing one chunk size everywhere keeps the transfer code uniform.
     *
     * @return int
     */
    public static function suggested_chunk_bytes()
    {
        $upload = self::bytes_from_ini('upload_max_filesize');
        $post = self::bytes_from_ini('post_max_size');

        $candidates = array_filter(array($upload, $post));
        $limit = $candidates === array() ? 2097152 : min($candidates);

        $usable = (int) floor($limit * 0.8);
        $usable = min($usable, 4194304);

        $quantum = 262144;
        $chunk = (int) (floor($usable / $quantum) * $quantum);

        return max($quantum, $chunk);
    }

    /**
     * @param string $key
     * @return int Bytes, or 0 when unset/unlimited.
     */
    public static function bytes_from_ini($key)
    {
        $raw = trim((string) ini_get($key));
        if ($raw === '' || $raw === '-1' || $raw === '0') {
            return 0;
        }

        return self::parse_size($raw);
    }

    /**
     * Parse a PHP shorthand byte value such as "256M".
     *
     * @param string $value
     * @return int
     */
    public static function parse_size($value)
    {
        $value = trim((string) $value);
        $number = (float) $value;
        $suffix = strtolower(substr($value, -1));

        switch ($suffix) {
            case 'g':
                $number *= 1024;
                // no break
            case 'm':
                $number *= 1024;
                // no break
            case 'k':
                $number *= 1024;
        }

        return (int) $number;
    }

    /**
     * Free space on the volume holding private storage.
     *
     * @return int Bytes, or 0 when the host forbids the check.
     */
    public static function free_disk_bytes()
    {
        if (! function_exists('disk_free_space')) {
            return 0;
        }

        $free = @disk_free_space(WP_CONTENT_DIR);

        return is_float($free) || is_int($free) ? (int) $free : 0;
    }

    /**
     * Whether MySQL advisory locks are usable.
     *
     * GET_LOCK() is connection scoped, which is exactly the semantics we want
     * for job locking: a process that dies releases its lock automatically.
     * Connection poolers break that assumption, so acquisition is verified from
     * the same connection with IS_USED_LOCK before it is trusted.
     *
     * @return bool
     */
    public static function supports_get_lock()
    {
        global $wpdb;

        $cached = get_transient(self::TRANSIENT_GET_LOCK);
        if ($cached === 'yes') {
            return true;
        }
        if ($cached === 'no') {
            return false;
        }

        $name = 'fsync_probe_' . substr(md5((string) $wpdb->prefix), 0, 8);

        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
        if ((int) $acquired !== 1) {
            set_transient(self::TRANSIENT_GET_LOCK, 'no', self::GET_LOCK_CACHE_TTL);

            return false;
        }

        $held = $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', $name));
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));

        $supported = $held !== null;
        set_transient(self::TRANSIENT_GET_LOCK, $supported ? 'yes' : 'no', self::GET_LOCK_CACHE_TTL);

        return $supported;
    }

    /**
     * Whether the posts table can actually store 4-byte characters. A site
     * still on utf8 silently truncates emoji and some kanji, which shows up as
     * a post-write hash mismatch rather than an obvious error.
     *
     * @return bool
     */
    public static function supports_utf8mb4()
    {
        global $wpdb;

        return strpos((string) $wpdb->charset, 'utf8mb4') !== false;
    }

    /**
     * @param string $key
     * @return string
     */
    private static function uploads($key)
    {
        $uploads = wp_upload_dir(null, false);
        if (! empty($uploads['error'])) {
            return '';
        }

        return untrailingslashit(str_replace('\\', '/', (string) ($uploads[$key] ?? '')));
    }

    /**
     * Blocking problems that should stop a run before it starts.
     *
     * @return array<int, array{code: string, message: string}>
     */
    public static function blockers()
    {
        $report = self::report();
        $blockers = array();

        if (! $report['caps']['openssl']) {
            $blockers[] = array(
                'code' => 'openssl_missing',
                'message' => 'OpenSSL拡張が利用できないため、認証情報を暗号化して保存できません。',
            );
        }

        if (! $report['caps']['random_bytes']) {
            $blockers[] = array(
                'code' => 'random_missing',
                'message' => '安全な乱数を生成できないため、鍵とnonceを発行できません。',
            );
        }

        if ($report['is_multisite']) {
            $blockers[] = array(
                'code' => 'multisite_unsupported',
                'message' => 'マルチサイトには対応していません。',
            );
        }

        return $blockers;
    }

    /**
     * Non-blocking problems worth showing on the health panel.
     *
     * @return array<int, array{code: string, message: string}>
     */
    public static function warnings()
    {
        $report = self::report();
        $warnings = array();

        if (! $report['db']['supports_utf8mb4']) {
            $warnings[] = array(
                'code' => 'charset_utf8',
                'message' => 'データベースがutf8mb4ではないため、絵文字などの4バイト文字が保存時に欠落する可能性があります。',
            );
        }

        if ($report['caps']['alternate_wp_cron']) {
            $warnings[] = array(
                'code' => 'alternate_wp_cron',
                'message' => 'ALTERNATE_WP_CRONが有効です。ページ読み込みにリダイレクトを挟む方式のため、確実なスケジュール実行と両立しません。',
            );
        }

        if (! $report['caps']['get_lock']) {
            $warnings[] = array(
                'code' => 'get_lock_unavailable',
                'message' => 'MySQLのアドバイザリロックを利用できません。テーブルによるロックにフォールバックします。',
            );
        }

        if (! $report['caps']['zlib']) {
            $warnings[] = array(
                'code' => 'zlib_missing',
                'message' => 'zlibが無いため、スナップショットとバックアップが非圧縮になります。',
            );
        }

        return $warnings;
    }
}
