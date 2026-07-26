<?php

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

$environment = (string) ($args[0] ?? 'staging');
$profile = (string) ($args[1] ?? 'content');
$peer = Fsync_Peer::by_env($environment);
if ($peer === null) {
    WP_CLI::error('Peer not found: ' . $environment);
}

$idempotency_key = Fsync_Utils::random_hex(16);
if (is_wp_error($idempotency_key)) {
    WP_CLI::error($idempotency_key->get_error_message());
}
$job = Fsync_Job::create_pull($peer['peer_id'], $profile, $idempotency_key);
if (is_wp_error($job)) {
    WP_CLI::error($job->get_error_code() . ': ' . $job->get_error_message());
}
$duplicate = Fsync_Job::create_pull($peer['peer_id'], $profile, $idempotency_key);
if (is_wp_error($duplicate) || $duplicate['job_id'] !== $job['job_id']) {
    WP_CLI::error('Repeated pull creation was not idempotent.');
}
$other_profile = $profile === 'content' ? 'full' : 'content';
$conflict = Fsync_Job::create_pull($peer['peer_id'], $other_profile, $idempotency_key);
if (! is_wp_error($conflict) || $conflict->get_error_code() !== 'fsync_idempotency_conflict') {
    WP_CLI::error('Reusing a pull idempotency key for another profile was not rejected.');
}

for ($turn = 0; $turn < 10000 && ! in_array($job['status'], array('awaiting_confirmation', 'complete', 'failed', 'cancelled'), true); $turn++) {
    $job = Fsync_Job::run($job['job_id']);
    if (is_wp_error($job)) {
        WP_CLI::error($job->get_error_code() . ': ' . $job->get_error_message());
    }
}
if ($job['status'] !== Fsync_Job::STATUS_AWAITING_CONFIRMATION) {
    WP_CLI::error('Unexpected pull review status: ' . $job['status'] . ' / ' . $job['error']);
}

$resolutions = array();
$has_deletes = false;
foreach ((array) ($job['result']['items'] ?? array()) as $item) {
    if (($item['action'] ?? '') === Fsync_Diff::ACTION_CONFLICT) {
        $resolutions[(string) $item['item_key']] = 'source';
    }
    if (($item['action'] ?? '') === Fsync_Diff::ACTION_DELETE) {
        $has_deletes = true;
    }
}
if ($resolutions !== array()) {
    $job = Fsync_Job::resolve_remote($job['job_id'], $job['result']['plan_hash'], $resolutions);
    if (is_wp_error($job)) {
        WP_CLI::error($job->get_error_code() . ': ' . $job->get_error_message());
    }
}
if ($has_deletes) {
    $job = Fsync_Job::confirm_remote_deletes($job['job_id'], $job['result']['plan_hash']);
    if (is_wp_error($job)) {
        WP_CLI::error($job->get_error_code() . ': ' . $job->get_error_message());
    }
}

$job = Fsync_Job::confirm_remote_apply($job['job_id'], $job['result']['plan_hash']);
if (is_wp_error($job)) {
    WP_CLI::error($job->get_error_code() . ': ' . $job->get_error_message());
}
for ($turn = 0; $turn < 10000 && in_array($job['status'], array('queued', 'running'), true); $turn++) {
    $job = Fsync_Job::run($job['job_id']);
    if (is_wp_error($job)) {
        WP_CLI::error($job->get_error_code() . ': ' . $job->get_error_message());
    }
}
if ($job['status'] !== Fsync_Job::STATUS_COMPLETE) {
    WP_CLI::error('Unexpected pull final status: ' . $job['status'] . ' / ' . $job['error']);
}

WP_CLI::line(
    wp_json_encode(
        array(
            'environment' => $environment,
            'operation' => $job['operation'],
            'source_job_id' => (string) ($job['payload']['remote_job_id'] ?? ''),
            'proxy_job_id' => $job['job_id'],
            'status' => $job['status'],
            'receipt_id' => (string) ($job['result']['receipt_id'] ?? ''),
        )
    )
);
