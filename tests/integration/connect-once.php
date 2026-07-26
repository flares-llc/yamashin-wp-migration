<?php

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

$blob = (string) ($args[0] ?? '');
$environment = (string) ($args[1] ?? 'local');
$result = Fsync_Pairing::connect($blob, $environment);
if (is_wp_error($result)) {
    WP_CLI::error($result->get_error_code() . ': ' . $result->get_error_message());
}

WP_CLI::line(
    wp_json_encode(
        array(
            'peer_id' => (string) ($result['peer_id'] ?? ''),
            'environment' => (string) ($result['env_name'] ?? ''),
            'capabilities' => array_values((array) ($result['capabilities'] ?? array())),
        )
    )
);
