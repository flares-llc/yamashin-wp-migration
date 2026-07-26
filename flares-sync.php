<?php
/**
 * Plugin Name: Yamashin WP Migration
 * Plugin URI: https://github.com/flares-llc/yamashin-wp-migration
 * Description: WordPress 環境間でサイト全体の差分検知、ドライラン、適用、検証、ロールバックを安全に行います。
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: 山真研究室
 * Author URI: https://shinroh.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: flares-sync
 */

if (! defined('ABSPATH')) {
    exit;
}

define('FSYNC_VERSION', '1.0.0');
define('FSYNC_SLUG', 'flares-sync');
define('FSYNC_PREFIX', 'fsync');
define('FSYNC_FILE', __FILE__);
define('FSYNC_DIR', plugin_dir_path(__FILE__));
define('FSYNC_URL', plugin_dir_url(__FILE__));
define('FSYNC_REST_NAMESPACE', 'flares-sync/v1');

/**
 * Schema version for the plugin's own tables. Bumping this triggers dbDelta on
 * the next admin request.
 */
define('FSYNC_SCHEMA_VERSION', 5);

/**
 * Version of the entity hashing algorithm. Peers refuse to talk to each other
 * when these differ, because their hashes would not be comparable.
 */
define('FSYNC_HASH_ALGO_VERSION', 1);

/**
 * Wire protocol version. Prefixes the canonical string that requests are
 * signed over, so a change here invalidates old signatures by construction.
 */
define('FSYNC_PROTOCOL', 'FSYNC1');

require_once FSYNC_DIR . 'includes/class-fsync-env.php';
require_once FSYNC_DIR . 'includes/class-fsync-budget.php';
require_once FSYNC_DIR . 'includes/class-fsync-utils.php';
require_once FSYNC_DIR . 'includes/class-fsync-fs.php';
require_once FSYNC_DIR . 'includes/class-fsync-schema.php';
require_once FSYNC_DIR . 'includes/class-fsync-log.php';
require_once FSYNC_DIR . 'includes/class-fsync-crypto.php';
require_once FSYNC_DIR . 'includes/class-fsync-credentials.php';
require_once FSYNC_DIR . 'includes/class-fsync-signer.php';
require_once FSYNC_DIR . 'includes/class-fsync-signer-hmac.php';
require_once FSYNC_DIR . 'includes/class-fsync-keys.php';
require_once FSYNC_DIR . 'includes/class-fsync-nonce-store.php';
require_once FSYNC_DIR . 'includes/class-fsync-peer.php';
require_once FSYNC_DIR . 'includes/class-fsync-pairing.php';
require_once FSYNC_DIR . 'includes/class-fsync-auth.php';
require_once FSYNC_DIR . 'includes/class-fsync-config-io.php';
require_once FSYNC_DIR . 'includes/class-fsync-config.php';
require_once FSYNC_DIR . 'includes/class-fsync-introspect.php';
require_once FSYNC_DIR . 'includes/class-fsync-config-schema.php';
require_once FSYNC_DIR . 'includes/class-fsync-config-validate.php';
require_once FSYNC_DIR . 'includes/class-fsync-client.php';
require_once FSYNC_DIR . 'includes/class-fsync-store.php';
require_once FSYNC_DIR . 'includes/class-fsync-identity.php';
require_once FSYNC_DIR . 'includes/class-fsync-portable.php';
require_once FSYNC_DIR . 'includes/class-fsync-manifest.php';
require_once FSYNC_DIR . 'includes/class-fsync-diff.php';
require_once FSYNC_DIR . 'includes/class-fsync-snapshot.php';
require_once FSYNC_DIR . 'includes/class-fsync-apply.php';
require_once FSYNC_DIR . 'includes/class-fsync-release.php';
require_once FSYNC_DIR . 'includes/class-fsync-job.php';
require_once FSYNC_DIR . 'includes/class-fsync-mcp-token.php';
require_once FSYNC_DIR . 'includes/class-fsync-rest.php';
require_once FSYNC_DIR . 'includes/class-fsync-rest-status.php';
require_once FSYNC_DIR . 'includes/class-fsync-rest-config.php';
require_once FSYNC_DIR . 'includes/class-fsync-rest-keys.php';
require_once FSYNC_DIR . 'includes/class-fsync-rest-migration.php';
require_once FSYNC_DIR . 'includes/class-fsync-mcp.php';

/**
 * Boot plugin services.
 *
 * @return void
 */
function fsync_boot()
{
    // The directory may disappear after activation through a deploy, volume
    // replacement or manual cleanup. Re-checking is cheap and makes the next
    // request restore both the private tree and its web-access guards.
    Fsync_Fs::ensure_private_storage();

    Fsync_Schema::register_hooks();
    Fsync_Apply::recover_if_needed();
    Fsync_Rest::register_hooks();
    Fsync_Job::register_hooks();

    if (is_admin()) {
        require_once FSYNC_DIR . 'includes/class-fsync-admin.php';
        require_once FSYNC_DIR . 'includes/class-fsync-admin-connection.php';
        require_once FSYNC_DIR . 'includes/class-fsync-admin-config.php';
        require_once FSYNC_DIR . 'includes/class-fsync-admin-health.php';
        require_once FSYNC_DIR . 'includes/class-fsync-admin-migration.php';

        Fsync_Admin::register_hooks();
    }
}
add_action('plugins_loaded', 'fsync_boot');

/**
 * Create tables and private storage on activation.
 *
 * @return void
 */
function fsync_activate()
{
    Fsync_Fs::ensure_private_storage();
    Fsync_Schema::install();
}
register_activation_hook(__FILE__, 'fsync_activate');

/**
 * Clear scheduled work on deactivation. Data is deliberately left in place;
 * removal happens in uninstall.php only.
 *
 * @return void
 */
function fsync_deactivate()
{
    wp_clear_scheduled_hook('fsync_tick');
    wp_clear_scheduled_hook('fsync_run_now');
}
register_deactivation_hook(__FILE__, 'fsync_deactivate');
