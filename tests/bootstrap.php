<?php
/**
 * Standalone bootstrap.
 *
 * The pure-logic parts of this plugin -- hashing, path safety, encryption,
 * config validation -- have no business requiring a database and a WordPress
 * install to test. This file stubs just enough of WordPress to load those
 * classes, so the fast tests stay fast.
 *
 * Anything that genuinely needs WordPress (REST routes, entity extraction,
 * apply) is covered by the Docker harness instead.
 */

// This file defines ABSPATH itself, so it would happily execute if it were
// ever requested over HTTP from a deployed plugin directory. Refuse anything
// that is not a command line invocation.
if (PHP_SAPI !== 'cli') {
    exit;
}

define('ABSPATH', __DIR__ . '/');
define('WP_CONTENT_DIR', sys_get_temp_dir() . '/fsync-tests/wp-content');
define('DAY_IN_SECONDS', 86400);

if (! is_dir(WP_CONTENT_DIR)) {
    mkdir(WP_CONTENT_DIR, 0777, true);
}

// ---------------------------------------------------------------------------
// Minimal WordPress surface
// ---------------------------------------------------------------------------

class WP_Error
{
    /** @var array<string, array<int, string>> */
    private $errors = array();

    /** @var array<string, mixed> */
    private $error_data = array();

    public function __construct($code = '', $message = '', $data = '')
    {
        if ($code !== '') {
            $this->errors[$code][] = $message;
            if ($data !== '') {
                $this->error_data[$code] = $data;
            }
        }
    }

    public function get_error_code()
    {
        $codes = array_keys($this->errors);

        return $codes === array() ? '' : $codes[0];
    }

    public function get_error_message($code = '')
    {
        $code = $code === '' ? $this->get_error_code() : $code;

        return $this->errors[$code][0] ?? '';
    }

    public function get_error_data($code = '')
    {
        $code = $code === '' ? $this->get_error_code() : $code;

        return $this->error_data[$code] ?? null;
    }
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

$GLOBALS['fsync_test_options'] = array();

function get_option($name, $default = false)
{
    return $GLOBALS['fsync_test_options'][$name] ?? $default;
}

function update_option($name, $value, $autoload = null)
{
    $GLOBALS['fsync_test_options'][$name] = $value;

    return true;
}

function delete_option($name)
{
    unset($GLOBALS['fsync_test_options'][$name]);

    return true;
}

function get_current_user_id()
{
    return 0;
}

function wp_mkdir_p($dir)
{
    return is_dir($dir) || mkdir($dir, 0777, true);
}

function untrailingslashit($string)
{
    return rtrim((string) $string, '/\\');
}

function trailingslashit($string)
{
    return untrailingslashit($string) . '/';
}

function wp_generate_password($length = 12, $special = true, $extra = false)
{
    return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
}

function size_format($bytes, $decimals = 0)
{
    return number_format((float) $bytes / 1048576, $decimals) . ' MB';
}

function content_url($path = '')
{
    return 'http://example.test/wp-content/' . ltrim((string) $path, '/');
}

function home_url($path = '')
{
    return 'http://example.test/' . ltrim((string) $path, '/');
}

function esc_url_raw($url)
{
    $url = trim((string) $url);

    return preg_match('#^https?://[^\s]+$#i', $url) === 1 ? $url : '';
}

function wp_parse_url($url, $component = -1)
{
    return $component === -1 ? parse_url((string) $url) : parse_url((string) $url, $component);
}

function wp_rand($min = 0, $max = 0)
{
    return random_int((int) $min, (int) $max);
}

/**
 * Just enough of wpdb for the code paths that only read $wpdb->prefix.
 * Anything that issues a query belongs in the Docker integration harness.
 */
final class Fsync_Test_Wpdb
{
    /** @var string */
    public $prefix = 'wp_';

    /** @var string */
    public $posts = 'wp_posts';

    /** @var string */
    public $postmeta = 'wp_postmeta';

    /** @var string */
    public $options = 'wp_options';
}

$GLOBALS['wpdb'] = new Fsync_Test_Wpdb();

function wp_remote_get($url, $args = array())
{
    return new WP_Error('unsupported', 'HTTP is not available in standalone tests.');
}

function wp_remote_retrieve_response_code($response)
{
    return 0;
}

// ---------------------------------------------------------------------------
// Plugin constants and classes
// ---------------------------------------------------------------------------

define('FSYNC_VERSION', '0.1.0-test');
define('FSYNC_SLUG', 'flares-sync');
define('FSYNC_SCHEMA_VERSION', 1);
define('FSYNC_HASH_ALGO_VERSION', 1);
define('FSYNC_PROTOCOL', 'FSYNC1');

require_once __DIR__ . '/../includes/class-fsync-utils.php';
require_once __DIR__ . '/../includes/class-fsync-fs.php';
require_once __DIR__ . '/../includes/class-fsync-crypto.php';
require_once __DIR__ . '/../includes/class-fsync-signer.php';
require_once __DIR__ . '/../includes/class-fsync-signer-hmac.php';
require_once __DIR__ . '/../includes/class-fsync-keys.php';
require_once __DIR__ . '/../includes/class-fsync-auth.php';
require_once __DIR__ . '/../includes/class-fsync-peer.php';
require_once __DIR__ . '/../includes/class-fsync-pairing.php';
require_once __DIR__ . '/../includes/class-fsync-config-io.php';
require_once __DIR__ . '/../includes/class-fsync-config.php';
require_once __DIR__ . '/../includes/class-fsync-config-validate.php';

// ---------------------------------------------------------------------------
// Test harness
// ---------------------------------------------------------------------------

final class T
{
    /** @var int */
    public static $passed = 0;

    /** @var array<int, string> */
    public static $failures = array();

    /** @var string */
    public static $group = '';

    public static function group($name)
    {
        self::$group = $name;
    }

    public static function ok($condition, $label)
    {
        if ($condition) {
            self::$passed++;

            return;
        }

        self::$failures[] = self::$group . ' / ' . $label;
    }

    public static function same($expected, $actual, $label)
    {
        if ($expected === $actual) {
            self::$passed++;

            return;
        }

        self::$failures[] = sprintf(
            "%s / %s\n      expected: %s\n      actual:   %s",
            self::$group,
            $label,
            var_export($expected, true),
            var_export($actual, true)
        );
    }

    public static function is_error($thing, $code, $label)
    {
        if (! is_wp_error($thing)) {
            self::$failures[] = sprintf(
                '%s / %s (expected WP_Error %s, got %s)',
                self::$group,
                $label,
                $code,
                gettype($thing)
            );

            return;
        }

        self::same($code, $thing->get_error_code(), $label);
    }

    public static function summary()
    {
        $failed = count(self::$failures);

        if ($failed === 0) {
            printf("\nOK: %d assertions passed\n", self::$passed);

            return 0;
        }

        printf("\nFAILED: %d passed, %d failed\n\n", self::$passed, $failed);
        foreach (self::$failures as $failure) {
            printf("  - %s\n", $failure);
        }
        print "\n";

        return 1;
    }
}
