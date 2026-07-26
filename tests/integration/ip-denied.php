<?php
/**
 * Prove that a pairing confirmation obeys its key's source-IP allowlist.
 *
 *   wp eval-file .../ip-denied.php <blob> <env_name>
 */

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

$blob = $args[0] ?? '';
$env_name = $args[1] ?? 'ip-denied';

$result = Fsync_Pairing::connect($blob, $env_name);
if (! is_wp_error($result) || $result->get_error_code() !== 'fsync_ip_denied') {
    $actual = is_wp_error($result) ? $result->get_error_code() : 'success';
    WP_CLI::error(sprintf('expected fsync_ip_denied, got %s', $actual));
}

if (Fsync_Peer::by_env($env_name) !== null || Fsync_Credentials::has('peer-' . $env_name)) {
    WP_CLI::error('a denied pairing left local peer or credential state behind');
}

WP_CLI::success('pairing IP allowlist denied the real HTTP request and local import was rolled back');
