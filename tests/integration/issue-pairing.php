<?php
/**
 * Issue a pairing blob on the receiving site.
 *
 *   wp eval-file .../issue-pairing.php <env_name> <connect_url> <preset> [ip_allowlist]
 *
 * Prints the blob on the last line so a shell driver can capture it.
 */

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

$env_name = $args[0] ?? 'local';
$connect_url = $args[1] ?? '';
$preset = $args[2] ?? 'deploy';
$ip_allowlist = array_values(
    array_filter(array_map('trim', explode(',', (string) ($args[3] ?? ''))))
);

// Receiving has to be switched on deliberately; the harness does that during
// setup, but assert it here so a failure names the real cause.
if (! Fsync_Auth::receiver_enabled()) {
    WP_CLI::error('receiver is not enabled on this site');
}

$result = Fsync_Pairing::create(
    array(
        'env_name' => $env_name,
        'label' => $env_name,
        'capabilities' => Fsync_Keys::PRESETS[$preset] ?? Fsync_Keys::PRESETS['readonly'],
        'ip_allowlist' => $ip_allowlist,
        'connect_url' => $connect_url,
    )
);

if (is_wp_error($result)) {
    WP_CLI::error($result->get_error_message());
}

WP_CLI::log(sprintf('key_id=%s expires_at=%d', $result['key_id'], $result['expires_at']));
WP_CLI::print_value($result['blob']);
