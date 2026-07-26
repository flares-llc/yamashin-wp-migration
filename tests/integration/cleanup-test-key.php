<?php
/**
 * Retire and remove a key created solely by the integration harness.
 *
 *   wp eval-file .../cleanup-test-key.php <key_id>
 */

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

$key_id = (string) ($args[0] ?? '');
$retired = Fsync_Keys::retire($key_id);
if (is_wp_error($retired)) {
    WP_CLI::error($retired->get_error_message());
}

global $wpdb;
$deleted = $wpdb->delete(
    Fsync_Schema::table('keys'),
    array('key_id' => $key_id, 'status' => Fsync_Keys::STATUS_RETIRED)
);

if ($deleted !== 1) {
    WP_CLI::error('the retired integration key could not be removed');
}

WP_CLI::success('the integration-only key was retired and removed');
