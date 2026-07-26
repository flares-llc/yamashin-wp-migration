<?php

if (! defined('ABSPATH')) {
    exit;
}

T::group('Fsync_Utils::canonical_hash');

// Key order must not affect the hash: two sites that built the same logical
// entity in a different order have to agree, otherwise every entity looks
// changed forever.
T::same(
    Fsync_Utils::canonical_hash(array('b' => 1, 'a' => 2)),
    Fsync_Utils::canonical_hash(array('a' => 2, 'b' => 1)),
    'associative key order is irrelevant'
);

// List order must matter: ACF repeaters and gallery fields are ordered data.
T::ok(
    Fsync_Utils::canonical_hash(array(1, 2)) !== Fsync_Utils::canonical_hash(array(2, 1)),
    'list order is significant'
);

T::same(
    Fsync_Utils::canonical_hash(array('a' => array('c' => 1, 'b' => 2))),
    Fsync_Utils::canonical_hash(array('a' => array('b' => 2, 'c' => 1))),
    'nested maps are sorted too'
);

T::ok(
    Fsync_Utils::canonical_hash('1') !== Fsync_Utils::canonical_hash(1),
    'string and int are distinguished'
);

T::same(64, strlen((string) Fsync_Utils::canonical_hash(array('x' => 'y'))), 'hash is 64 hex chars');

// Invalid UTF-8 must surface as an error rather than hashing the string
// "false" -- this is the failure mode that silently drops rows from a dump.
T::is_error(
    Fsync_Utils::canonical_hash(array('bad' => "\xB1\x31")),
    'fsync_json_encode_failed',
    'invalid UTF-8 is rejected, not silently swallowed'
);

T::group('Fsync_Utils::encode');

T::is_error(Fsync_Utils::encode("\xB1\x31"), 'fsync_json_encode_failed', 'encode rejects invalid UTF-8');
T::same('{"a":1}', Fsync_Utils::encode(array('a' => 1)), 'encode produces compact JSON');
T::same('"/a/b"', Fsync_Utils::encode('/a/b'), 'slashes are not escaped');
T::same('"日本語"', Fsync_Utils::encode('日本語'), 'unicode is not escaped');

T::group('Fsync_Utils::decode');

T::same(array('a' => 1), Fsync_Utils::decode('{"a":1}'), 'decode returns associative arrays');
T::is_error(Fsync_Utils::decode('{oops'), 'fsync_json_decode_failed', 'malformed JSON is an error');

T::group('Fsync_Utils::is_list');

T::ok(Fsync_Utils::is_list(array()), 'empty array counts as a list');
T::ok(Fsync_Utils::is_list(array('a', 'b')), 'zero-indexed array is a list');
T::ok(! Fsync_Utils::is_list(array(1 => 'a')), 'offset array is not a list');
T::ok(! Fsync_Utils::is_list(array('k' => 'v')), 'string keys are not a list');

T::group('Fsync_Utils::normalize_relative_path');

T::same('2026/07/hero.jpg', Fsync_Utils::normalize_relative_path('2026/07/hero.jpg'), 'plain path passes');
T::same('a/b.txt', Fsync_Utils::normalize_relative_path('/a//b.txt'), 'leading and duplicate slashes collapse');
T::same('a/b.txt', Fsync_Utils::normalize_relative_path('a\\b.txt'), 'backslashes normalize');

T::is_error(Fsync_Utils::normalize_relative_path('../etc/passwd'), 'fsync_path_traversal', 'parent traversal rejected');
T::is_error(Fsync_Utils::normalize_relative_path('a/../../b'), 'fsync_path_traversal', 'embedded traversal rejected');
T::is_error(Fsync_Utils::normalize_relative_path('a/./b'), 'fsync_path_traversal', 'single dot segment rejected');
T::is_error(Fsync_Utils::normalize_relative_path("a\0b"), 'fsync_path_invalid', 'null byte rejected');
T::is_error(Fsync_Utils::normalize_relative_path('C:/Windows'), 'fsync_path_absolute', 'drive letter rejected');
T::is_error(Fsync_Utils::normalize_relative_path(''), 'fsync_path_empty', 'empty path rejected');
T::is_error(Fsync_Utils::normalize_relative_path('   '), 'fsync_path_empty', 'whitespace-only path rejected');

T::group('Fsync_Utils base64url');

$raw = random_bytes(32);
T::same($raw, Fsync_Utils::base64url_decode(Fsync_Utils::base64url_encode($raw)), 'base64url round-trips');
T::ok(strpos(Fsync_Utils::base64url_encode($raw), '=') === false, 'no padding in output');

T::group('Fsync_Utils::fingerprint');

T::same(8, strlen(Fsync_Utils::fingerprint('secret')), 'fingerprint is 8 chars');
T::same('', Fsync_Utils::fingerprint(''), 'empty input yields empty fingerprint');
T::ok(
    Fsync_Utils::fingerprint('a') !== Fsync_Utils::fingerprint('b'),
    'different secrets fingerprint differently'
);

T::group('Fsync_Fs private storage');

$private_ready = Fsync_Fs::ensure_private_storage();
T::same(true, $private_ready, 'private storage can be initialized');
T::ok(is_file(Fsync_Fs::private_dir('.htaccess')), 'private storage includes an Apache deny rule');
T::ok(is_file(Fsync_Fs::private_dir('index.php')), 'private storage includes an index guard');
foreach (array('objects', 'releases', 'backups', 'jobs', 'tmp', 'snapshots') as $directory) {
    T::ok(is_dir(Fsync_Fs::private_dir($directory)), sprintf('private storage includes %s', $directory));
}
T::ok(Fsync_Fs::delete_private_tree(Fsync_Fs::private_dir()), 'the standalone private-storage fixture is cleaned up');
