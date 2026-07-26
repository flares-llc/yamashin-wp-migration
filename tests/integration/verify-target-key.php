<?php
/**
 * Verify receiver-side key state after a successful re-pairing.
 *
 *   wp eval-file .../verify-target-key.php <new_key_id>
 */

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

$key_id = $args[0] ?? '';
$key = Fsync_Keys::find($key_id);
if ($key === null || $key['status'] !== Fsync_Keys::STATUS_ACTIVE || $key['peer_id'] === '') {
    WP_CLI::error('the newly paired receiver key is not active or has no peer');
}

$active = Fsync_Keys::all(
    array(
        'status' => Fsync_Keys::STATUS_ACTIVE,
        'direction' => Fsync_Keys::DIRECTION_INBOUND,
        'peer_id' => $key['peer_id'],
    )
);

if (count($active) !== 1 || ($active[0]['key_id'] ?? '') !== $key_id) {
    WP_CLI::error('re-pairing left an older inbound key active for the same peer');
}

WP_CLI::success('the newly paired key is the peer\'s only active inbound key');
