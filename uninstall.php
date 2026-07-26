<?php
/**
 * Uninstall routine.
 *
 * Deleting a plugin should not silently destroy backups. Tables and options are
 * removed because they are plugin state, but the private storage directory --
 * which holds snapshots, releases and every local backup -- is left in place
 * unless the operator has explicitly asked for it to go.
 *
 * Keys are retired first so that a peer which still holds a paired secret is
 * refused rather than finding an unguarded site after a reinstall.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/includes/class-fsync-utils.php';
require_once __DIR__ . '/includes/class-fsync-fs.php';
require_once __DIR__ . '/includes/class-fsync-schema.php';

global $wpdb;

// Retire every key before dropping the table, so the audit trail on the peer
// side reflects that this end was deliberately torn down.
$keys_table = Fsync_Schema::table('keys');
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $keys_table)) === $keys_table) {
    $wpdb->query("UPDATE {$keys_table} SET status = 'retired'");
}

Fsync_Schema::drop();

foreach (
    array(
        'fsync_config',
        'fsync_active_env',
        'fsync_site_role',
        'fsync_receiver_enabled',
        'fsync_trusted_proxies',
        'fsync_crypto_canary',
        'fsync_schema_version',
    ) as $option
) {
    delete_option($option);
}

// Remove fixed and per-user transients created by the admin and environment
// probes. Expired transients are not guaranteed to have been garbage-collected
// before uninstall, so leaving them would violate the promise that plugin
// state is removed.
delete_transient('fsync_supports_get_lock');

foreach (array('fsync_pairing_blob_', 'fsync_notice_', 'fsync_config_draft_', 'fsync_config_result_') as $prefix) {
    $value_like = $wpdb->esc_like('_transient_' . $prefix) . '%';
    $timeout_like = $wpdb->esc_like('_transient_timeout_' . $prefix) . '%';
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $value_like,
            $timeout_like
        )
    );
}

// Scheduled work.
wp_clear_scheduled_hook('fsync_tick');
wp_clear_scheduled_hook('fsync_run_now');

// The mu-plugin guard is ours, so it goes; backups and releases are the user's
// data, so they stay.
$guard = WP_CONTENT_DIR . '/mu-plugins/fsync-guard.php';
if (file_exists($guard)) {
    @unlink($guard);
}

if (defined('FSYNC_UNINSTALL_REMOVE_DATA') && FSYNC_UNINSTALL_REMOVE_DATA) {
    Fsync_Fs::delete_private_tree(Fsync_Fs::private_dir());
}
