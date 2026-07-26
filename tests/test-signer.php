<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The canonical string is a wire protocol shared by two independently deployed
 * copies of the plugin. These tests pin its exact shape, so that a refactor
 * that changes it fails here rather than in production as an unexplained 401.
 */

$parts = array(
    'method' => 'post',
    'route' => 'flares-sync/v1/apply',
    'query' => array('session' => 'abc', 'rest_route' => '/flares-sync/v1/apply'),
    'body' => '{"a":1}',
    'timestamp' => 1769000000,
    'nonce' => 'deadbeefdeadbeef',
    'key_id' => 'k123',
);

T::group('Fsync_Signer::canonical');

$canonical = Fsync_Signer::canonical($parts);
T::ok(is_string($canonical), 'canonical returns a string');

$lines = explode("\n", (string) $canonical);
T::same(8, count($lines), 'canonical string has exactly 8 lines');
T::same('FSYNC1', $lines[0], 'line 1 is the protocol version');
T::same('POST', $lines[1], 'line 2 is the uppercased method');
T::same('/flares-sync/v1/apply', $lines[2], 'line 3 is the normalized route');
T::same('session=abc', $lines[3], 'line 4 is the signed query, without rest_route');
T::same(hash('sha256', '{"a":1}'), $lines[4], 'line 5 is the body hash');
T::same('1769000000', $lines[5], 'line 6 is the timestamp');
T::same('deadbeefdeadbeef', $lines[6], 'line 7 is the nonce');
T::same('k123', $lines[7], 'line 8 is the key id');

T::group('Fsync_Signer::canonical required fields');

foreach (array('method', 'route', 'timestamp', 'nonce', 'key_id') as $field) {
    $incomplete = $parts;
    unset($incomplete[$field]);
    T::is_error(
        Fsync_Signer::canonical($incomplete),
        'fsync_canonical_incomplete',
        sprintf('missing %s is rejected', $field)
    );
}

T::group('Fsync_Signer::normalize_route');

T::same('/a/b', Fsync_Signer::normalize_route('a/b'), 'leading slash added');
T::same('/a/b', Fsync_Signer::normalize_route('/a/b/'), 'trailing slash removed');
T::same('/a/b', Fsync_Signer::normalize_route('//a//b'), 'duplicate slashes collapsed');
T::same('/a/b', Fsync_Signer::normalize_route('\\a\\b'), 'backslashes normalized');

// The whole point of route-based signing: the same logical call signs the same
// way whether or not the site has pretty permalinks.
$pretty = Fsync_Signer::canonical(array_merge($parts, array('query' => array('session' => 'abc'))));
$ugly = Fsync_Signer::canonical($parts);
T::same($pretty, $ugly, 'permalink style does not change the signature');

T::group('Fsync_Signer::normalize_query');

T::same('', Fsync_Signer::normalize_query(array()), 'empty query is an empty string');
T::same(
    Fsync_Signer::normalize_query(array('b' => '2', 'a' => '1')),
    Fsync_Signer::normalize_query(array('a' => '1', 'b' => '2')),
    'parameter order does not matter'
);
T::same('a=1&b=2', Fsync_Signer::normalize_query(array('b' => '2', 'a' => '1')), 'pairs are sorted');
T::same('a=1&a=2', Fsync_Signer::normalize_query(array('a' => array('1', '2'))), 'repeated keys are kept');
T::same('a%20b=c%2Fd', Fsync_Signer::normalize_query(array('a b' => 'c/d')), 'keys and values are encoded');
T::same('flag=true', Fsync_Signer::normalize_query(array('flag' => true)), 'booleans render consistently');
T::same('', Fsync_Signer::normalize_query(array('rest_route' => '/x')), 'rest_route is excluded');

T::group('Fsync_Signer::body_hash');

T::same(hash('sha256', ''), Fsync_Signer::body_hash(''), 'empty body hashes to sha256 of empty string');
T::same(64, strlen(Fsync_Signer::body_hash('x')), 'body hash is 64 hex chars');

T::group('Fsync_Signer_Hmac');

$secret = random_bytes(32);
$signature = Fsync_Signer_Hmac::sign($secret, (string) $canonical);

T::ok(is_string($signature), 'sign returns a string');
T::same(64, strlen((string) $signature), 'signature is 64 hex chars');
T::ok(Fsync_Signer_Hmac::verify($secret, (string) $canonical, (string) $signature), 'valid signature verifies');
T::ok(! Fsync_Signer_Hmac::verify($secret, (string) $canonical . 'x', (string) $signature), 'modified canonical fails');
T::ok(! Fsync_Signer_Hmac::verify(random_bytes(32), (string) $canonical, (string) $signature), 'wrong secret fails');
T::ok(! Fsync_Signer_Hmac::verify($secret, (string) $canonical, ''), 'empty signature fails');
T::is_error(Fsync_Signer_Hmac::sign('', 'x'), 'fsync_secret_missing', 'empty secret cannot sign');

// Every signed component must actually affect the signature, or it is not
// really covered and could be tampered with in transit.
foreach (
    array(
        'method' => 'get',
        'route' => 'flares-sync/v1/other',
        'body' => '{"a":2}',
        'timestamp' => 1769000001,
        'nonce' => 'cafebabecafebabe',
        'key_id' => 'k999',
    ) as $field => $changed
) {
    $mutated = Fsync_Signer::canonical(array_merge($parts, array($field => $changed)));
    T::ok(
        ! Fsync_Signer_Hmac::verify($secret, (string) $mutated, (string) $signature),
        sprintf('changing %s invalidates the signature', $field)
    );
}

$mutated_query = Fsync_Signer::canonical(array_merge($parts, array('query' => array('session' => 'zzz'))));
T::ok(
    ! Fsync_Signer_Hmac::verify($secret, (string) $mutated_query, (string) $signature),
    'changing a query parameter invalidates the signature'
);

T::group('Fsync_Signer dispatch');

T::same('Fsync_Signer_Hmac', Fsync_Signer::implementation(''), 'empty algorithm defaults to HMAC');
T::same('Fsync_Signer_Hmac', Fsync_Signer::implementation('hmac-sha256'), 'HMAC resolves');
T::same('Fsync_Signer_Hmac', Fsync_Signer::implementation('HMAC-SHA256'), 'algorithm match is case-insensitive');
T::is_error(Fsync_Signer::implementation('rot13'), 'fsync_algorithm_unsupported', 'unknown algorithm is rejected');
T::ok(! Fsync_Signer::verify('rot13', $secret, (string) $canonical, (string) $signature), 'unknown algorithm never verifies');
T::ok(Fsync_Signer::verify('hmac-sha256', $secret, (string) $canonical, (string) $signature), 'dispatch verifies correctly');

T::group('Fsync_Signer::check_timestamp');

$now = 1769000000;
T::same(true, Fsync_Signer::check_timestamp($now, $now), 'exact time accepted');
T::same(true, Fsync_Signer::check_timestamp($now + 299, $now), 'within tolerance accepted (future)');
T::same(true, Fsync_Signer::check_timestamp($now - 299, $now), 'within tolerance accepted (past)');

$skewed = Fsync_Signer::check_timestamp($now + 428, $now);
T::is_error($skewed, 'fsync_clock_skew', 'beyond tolerance rejected');
T::ok(
    is_wp_error($skewed) && strpos($skewed->get_error_message(), '428') !== false,
    'the error reports the measured skew in seconds'
);
$data = is_wp_error($skewed) ? $skewed->get_error_data() : array();
T::same(428, $data['skew'] ?? null, 'skew is machine-readable in the error data');
T::same(401, $data['status'] ?? null, 'clock skew maps to HTTP 401');

T::is_error(Fsync_Signer::check_timestamp($now - 3600, $now), 'fsync_clock_skew', 'an hour of skew is rejected');
