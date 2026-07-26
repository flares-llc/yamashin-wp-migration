<?php

if (! defined('ABSPATH')) {
    exit;
}

T::group('Fsync_Store content addressing');

$payload = "portable payload\n";
$hash = hash('sha256', $payload);
$imported = Fsync_Store::put($payload);
T::same($hash, $imported, 'import returns the SHA-256 content address');
T::same($payload, Fsync_Store::get($hash), 'stored bytes round-trip');
T::ok(Fsync_Store::has($hash), 'imported object is discoverable');

$stored_path = Fsync_Store::path($hash);
file_put_contents($stored_path, 'corrupt bytes');
T::same($hash, Fsync_Store::put($payload), 'a corrupt existing CAS entry is quarantined and repaired');
T::same($payload, Fsync_Store::get($hash), 'the repaired CAS entry verifies');

$chunk_hash = hash('sha256', 'chunked object');
$chunk_result = Fsync_Store::put_chunk($chunk_hash, 0, 14, base64_encode('chunked '));
T::ok(! is_wp_error($chunk_result) && empty($chunk_result['complete']), 'an incomplete chunk is retained');
$chunk_result = Fsync_Store::put_chunk($chunk_hash, 8, 14, base64_encode('object'));
T::ok(! is_wp_error($chunk_result) && ! empty($chunk_result['complete']), 'the final verified chunk completes the object');
T::same('chunked object', Fsync_Store::get($chunk_hash), 'chunked object round-trips');

T::is_error(
    Fsync_Store::put_chunk(str_repeat('a', 64), 0, 4, base64_encode('evil')),
    'fsync_object_hash_mismatch',
    'a forged final object is rejected'
);

T::is_error(Fsync_Store::get('../secrets'), 'fsync_object_hash_invalid', 'path traversal is rejected before filesystem access');
