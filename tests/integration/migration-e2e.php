<?php

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

$environment = (string) ($args[0] ?? 'staging');
$mode = (string) ($args[1] ?? 'apply');
$profile = (string) ($args[2] ?? 'content');
$peer = Fsync_Peer::by_env($environment);
if ($peer === null) {
    WP_CLI::error('Peer not found: ' . $environment);
}

$created = Fsync_Release::create($peer['peer_id'], $profile, 'push');
if (is_wp_error($created)) {
    WP_CLI::error($created->get_error_code() . ': ' . $created->get_error_message());
}
$job = Fsync_Job::create('push_release', array('peer_id' => $peer['peer_id']), $created['release']['release_id']);
if (is_wp_error($job)) {
    WP_CLI::error($job->get_error_code() . ': ' . $job->get_error_message());
}

for ($turn = 0; $turn < 10000 && ! in_array($job['status'], array('awaiting_confirmation', 'complete', 'failed', 'cancelled'), true); $turn++) {
    $job = Fsync_Job::run($job['job_id']);
    if (is_wp_error($job)) {
        WP_CLI::error($job->get_error_code() . ': ' . $job->get_error_message());
    }
}
if ($job['status'] !== 'awaiting_confirmation') {
    WP_CLI::error('Unexpected review status: ' . $job['status'] . ' / ' . $job['error']);
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
if (in_array($mode, array('cancel', 'pause'), true)) {
    $job = Fsync_Job::run($job['job_id']);
    if (is_wp_error($job)) {
        WP_CLI::error($job->get_error_code() . ': ' . $job->get_error_message());
    }
    $remote_job_id = (string) ($job['payload']['remote_job_id'] ?? '');
    if ($mode === 'pause') {
        WP_CLI::line(wp_json_encode(array('environment' => $environment, 'status' => $job['status'], 'source_job_id' => $job['job_id'], 'remote_job_id' => $remote_job_id)));

        return;
    }
    $job = Fsync_Job::cancel($job['job_id']);
    if (is_wp_error($job)) {
        WP_CLI::error($job->get_error_code() . ': ' . $job->get_error_message());
    }
    WP_CLI::line(wp_json_encode(array('environment' => $environment, 'status' => $job['status'], 'remote_job_id' => $remote_job_id)));

    return;
}
for ($turn = 0; $turn < 1000 && in_array($job['status'], array('queued', 'running'), true); $turn++) {
    $job = Fsync_Job::run($job['job_id']);
    if (is_wp_error($job)) {
        WP_CLI::error($job->get_error_code() . ': ' . $job->get_error_message());
    }
}
if ($job['status'] !== 'complete') {
    WP_CLI::error('Unexpected final status: ' . $job['status'] . ' / ' . $job['error']);
}

WP_CLI::line(
    wp_json_encode(
        array(
            'environment' => $environment,
            'source_job_id' => $job['job_id'],
            'release_id' => $job['release_id'],
            'status' => $job['status'],
            'remote_status' => (string) ($job['result']['status'] ?? ''),
            'receipt_id' => (string) ($job['result']['receipt_id'] ?? ''),
            'manifest_root' => (string) ($job['result']['manifest_root'] ?? ''),
        )
    )
);
