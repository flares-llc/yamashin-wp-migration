<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The encryption layer is the one place where a silent regression costs the
 * user every stored credential at once, so the failure modes are pinned here.
 */

T::group('Fsync_Crypto round trip');

// A key must exist before anything can be encrypted. The constant path is what
// we recommend in production, so it is what the tests exercise.
if (! defined('FSYNC_ENCRYPTION_KEY')) {
    define('FSYNC_ENCRYPTION_KEY', base64_encode(str_repeat("\x11", 32)));
}
Fsync_Crypto::flush();

$secret = 'p@ssw0rd-とても秘密-' . str_repeat('x', 100);

$envelope = Fsync_Crypto::encrypt($secret, 'credential', 'peer-prod');
T::ok(! is_wp_error($envelope), 'encrypt succeeds');
T::ok(is_string($envelope) && $envelope !== '', 'envelope is a non-empty string');
T::ok(strpos((string) $envelope, $secret) === false, 'plaintext does not appear in the envelope');

T::same($secret, Fsync_Crypto::decrypt($envelope, 'credential', 'peer-prod'), 'decrypt returns the plaintext');

T::same('', Fsync_Crypto::encrypt('', 'credential', 'x'), 'empty input encrypts to empty');
T::same('', Fsync_Crypto::decrypt('', 'credential', 'x'), 'empty input decrypts to empty');

T::group('Fsync_Crypto nondeterminism');

$a = Fsync_Crypto::encrypt($secret, 'credential', 'peer-prod');
$b = Fsync_Crypto::encrypt($secret, 'credential', 'peer-prod');
T::ok($a !== $b, 'same plaintext encrypts differently each time');
T::same($secret, Fsync_Crypto::decrypt($b, 'credential', 'peer-prod'), 'second envelope also decrypts');

T::group('Fsync_Crypto AAD binding');

// A ciphertext lifted out of one row must not authenticate as another row's
// value. Without AAD binding, swapping rows would silently succeed.
T::is_error(
    Fsync_Crypto::decrypt($envelope, 'credential', 'peer-staging'),
    'fsync_decrypt_failed',
    'ciphertext bound to a different record id fails'
);

T::is_error(
    Fsync_Crypto::decrypt($envelope, 'key', 'peer-prod'),
    'fsync_decrypt_failed',
    'ciphertext bound to a different purpose fails'
);

T::group('Fsync_Crypto tamper detection');

$decoded = json_decode(base64_decode($envelope, true), true);
$ciphertext = base64_decode($decoded['d'], true);
$ciphertext[0] = $ciphertext[0] === "\x00" ? "\x01" : "\x00";
$decoded['d'] = base64_encode($ciphertext);
$tampered = base64_encode(json_encode($decoded));

T::is_error(
    Fsync_Crypto::decrypt($tampered, 'credential', 'peer-prod'),
    'fsync_decrypt_failed',
    'flipping a ciphertext byte is detected by the GCM tag'
);

T::is_error(
    Fsync_Crypto::decrypt('not-base64-at-all!!', 'credential', 'peer-prod'),
    'fsync_envelope_invalid',
    'garbage input is rejected cleanly'
);

T::group('Fsync_Crypto key change detection');

// This is the scenario that motivated the whole key chain: the operator
// regenerates the WordPress salts, or edits the constant, and every credential
// stops decrypting. The error must name the cause.
$rotated = new ReflectionProperty('Fsync_Crypto', 'master');
if (PHP_VERSION_ID < 80100) {
    $rotated->setAccessible(true);
}
$rotated->setValue(null, array(
    'key' => str_repeat("\x22", 32),
    'ref' => substr(hash('sha256', 'fsync-key-ref|' . str_repeat("\x22", 32)), 0, 16),
    'source' => 'test-rotated',
));

$result = Fsync_Crypto::decrypt($envelope, 'credential', 'peer-prod');
T::is_error($result, 'fsync_key_changed', 'a changed master key is reported as such, not as generic failure');
T::ok(
    is_wp_error($result) && strpos($result->get_error_message(), 'ソルト') !== false,
    'the message points at salt regeneration as a likely cause'
);

Fsync_Crypto::flush();

T::group('Fsync_Crypto canary');

$GLOBALS['fsync_test_options'] = array();

$first = Fsync_Crypto::check();
T::ok($first['ok'], 'first check initializes the canary');
T::same('initialized', $first['code'], 'first check reports initialization');
T::same('constant', $first['source'], 'key source is the constant');

$second = Fsync_Crypto::check();
T::ok($second['ok'], 'second check verifies the canary');
T::same('ok', $second['code'], 'second check reports ok');

// Simulate the key changing underneath a stored canary.
$rotated->setValue(null, array(
    'key' => str_repeat("\x33", 32),
    'ref' => substr(hash('sha256', 'fsync-key-ref|' . str_repeat("\x33", 32)), 0, 16),
    'source' => 'test-rotated',
));

$third = Fsync_Crypto::check();
T::ok(! $third['ok'], 'canary check fails after the key changes');
T::same('fsync_key_changed', $third['code'], 'canary failure names the key change');

Fsync_Crypto::flush();

T::group('Fsync_Crypto key parsing');

$generated = Fsync_Crypto::generate_key();
T::ok(is_string($generated), 'generate_key returns a string');
T::same(32, strlen((string) base64_decode((string) $generated, true)), 'generated key is 32 bytes');
