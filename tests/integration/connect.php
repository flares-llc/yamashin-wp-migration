<?php
/**
 * Consume a pairing blob and exercise the authenticated path end to end.
 *
 *   wp eval-file .../connect.php <blob> <env_name>
 *
 * This is the part the standalone suite cannot cover: two real WordPress
 * installations, a real HTTP hop, and a real database on both ends.
 */

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

$blob = $args[0] ?? '';
$env_name = $args[1] ?? 'staging';

if ($blob === '') {
    WP_CLI::error('usage: connect.php <blob> [env_name]');
}

/**
 * State lives on a class, not in top-level variables.
 *
 * wp eval-file includes this file inside a method, so a top-level $passed is a
 * local of that method while `global $passed` inside a helper binds to a
 * different variable entirely. That mismatch made the summary report zero
 * checks -- and, far worse, made the failure list always look empty, so the
 * script would have exited successfully no matter what failed.
 */
final class Fsync_Checks
{
    /** @var int */
    public static $passed = 0;

    /** @var array<int, string> */
    public static $failed = array();
}

/**
 * @param bool $condition
 * @param string $label
 * @return void
 */
function fsync_check($condition, $label)
{
    if ($condition) {
        Fsync_Checks::$passed++;
        WP_CLI::log('  ok   ' . $label);

        return;
    }

    Fsync_Checks::$failed[] = $label;
    WP_CLI::log('  FAIL ' . $label);
}

WP_CLI::log('--- pairing ---');

$result = Fsync_Pairing::connect($blob, $env_name);

if (is_wp_error($result)) {
    WP_CLI::error(sprintf('pairing failed: [%s] %s', $result->get_error_code(), $result->get_error_message()));
}

fsync_check(is_array($result), 'connect() succeeded');
fsync_check(($result['env_name'] ?? '') === $env_name, 'peer recorded under the expected environment name');
fsync_check(in_array('read', (array) ($result['capabilities'] ?? array()), true), 'granted capabilities came back');

$peer = Fsync_Peer::by_env($env_name);
fsync_check($peer !== null, 'peer row exists locally');
fsync_check($peer !== null && $peer['outbound_key_id'] !== '', 'peer carries the outbound key id');
fsync_check(Fsync_Credentials::has('peer-' . $env_name), 'shared secret stored as a credential');

// The credential store must never hand a value back through a listing path.
$meta = Fsync_Credentials::meta('peer-' . $env_name);
fsync_check(is_array($meta) && ! isset($meta['value']), 'credential metadata carries no value');
fsync_check(is_array($meta) && strlen((string) $meta['fingerprint']) === 8, 'credential exposes only a fingerprint');

WP_CLI::log('--- blob is single use ---');

// A blob that could be replayed would let anyone who saw it pair themselves.
$again = Fsync_Pairing::connect($blob, $env_name . '2');
fsync_check(is_wp_error($again), 'reusing the same blob is refused');
if (is_wp_error($again)) {
    WP_CLI::log('       -> ' . $again->get_error_code() . ': ' . $again->get_error_message());
}

WP_CLI::log('--- handshake ---');

$client = Fsync_Client::for_peer($peer);
if (is_wp_error($client)) {
    WP_CLI::error($client->get_error_message());
}

$handshake = $client->get('handshake');

if (is_wp_error($handshake)) {
    WP_CLI::error(sprintf('handshake failed: [%s] %s', $handshake->get_error_code(), $handshake->get_error_message()));
}

fsync_check(! empty($handshake['ok']), 'handshake succeeded');
fsync_check(($handshake['protocol'] ?? '') === FSYNC_PROTOCOL, 'protocol version matches');
fsync_check((int) ($handshake['hash_algo_version'] ?? 0) === FSYNC_HASH_ALGO_VERSION, 'hash algorithm version matches');
fsync_check(! empty($handshake['scope_fingerprint']), 'peer reported a scope fingerprint');
fsync_check(isset($handshake['limits']['suggested_chunk_bytes']), 'peer reported a negotiated chunk size');

$chunk = (int) ($handshake['limits']['suggested_chunk_bytes'] ?? 0);
fsync_check($chunk > 0 && $chunk % 262144 === 0, 'chunk size is a multiple of 256 KiB');

WP_CLI::log(
    sprintf(
        '       -> env=%s php_limit=%ss chunk=%s upload_max=%s',
        (string) ($handshake['env_name'] ?? '?'),
        (string) ($handshake['limits']['max_execution_time'] ?? '?'),
        size_format($chunk),
        size_format(max(1, (int) ($handshake['limits']['upload_max_filesize'] ?? 0)))
    )
);

WP_CLI::log('--- header diagnostics ---');

$echo = $client->echo_test();
fsync_check(! is_wp_error($echo), 'unauthenticated echo endpoint reachable');
fsync_check(is_array($echo) && empty($echo['missing_headers']), 'all signature headers survived the trip');

if (is_array($echo) && ! empty($echo['missing_headers'])) {
    WP_CLI::log('       -> missing: ' . implode(', ', (array) $echo['missing_headers']));
}

WP_CLI::log('--- replay protection ---');

// Reuse of a nonce must be refused. Reaching into the signer directly is the
// only way to send the same nonce twice, which is exactly what an attacker
// replaying a captured request would do.
$secret = Fsync_Credentials::get('peer-' . $env_name);
$nonce = Fsync_Utils::random_hex(16);

$parts = array(
    'method' => 'GET',
    'route' => '/' . FSYNC_REST_NAMESPACE . '/handshake',
    'query' => array(),
    'body' => '',
    'timestamp' => Fsync_Utils::now(),
    'nonce' => $nonce,
    'key_id' => $peer['outbound_key_id'],
);

$canonical = Fsync_Signer::canonical($parts);
$signature = Fsync_Signer::sign(Fsync_Signer_Hmac::ALGORITHM, $secret, $canonical);
$url = add_query_arg('rest_route', $parts['route'], $peer['url']);

$send = static function () use ($url, $parts, $signature) {
    return wp_remote_get(
        $url,
        array(
            'timeout' => 20,
            'redirection' => 0,
            'sslverify' => false,
            'headers' => Fsync_Signer::headers($parts, $signature),
        )
    );
};

$first = $send();
$second = $send();

fsync_check(
    ! is_wp_error($first) && (int) wp_remote_retrieve_response_code($first) === 200,
    'a freshly signed request is accepted'
);

$second_code = is_wp_error($second) ? 0 : (int) wp_remote_retrieve_response_code($second);
fsync_check($second_code === 401, 'replaying the identical request is rejected with 401');

$body = is_wp_error($second) ? array() : json_decode((string) wp_remote_retrieve_body($second), true);
fsync_check(
    is_array($body) && ($body['code'] ?? '') === 'fsync_nonce_replayed',
    'the rejection names nonce replay specifically'
);

WP_CLI::log('--- tampering ---');

// Flipping one byte of the signature must fail. If this ever passes, the
// comparison has stopped being a real check.
$bad_signature = $signature;
$bad_signature[0] = $bad_signature[0] === 'a' ? 'b' : 'a';

$tampered = wp_remote_get(
    $url,
    array(
        'timeout' => 20,
        'redirection' => 0,
        'sslverify' => false,
        'headers' => Fsync_Signer::headers(
            array_merge($parts, array('nonce' => Fsync_Utils::random_hex(16))),
            $bad_signature
        ),
    )
);

fsync_check(
    ! is_wp_error($tampered) && (int) wp_remote_retrieve_response_code($tampered) === 401,
    'a tampered signature is rejected'
);

// A stale timestamp must be refused even with a valid signature over it.
$old_parts = array_merge(
    $parts,
    array('timestamp' => Fsync_Utils::now() - 3600, 'nonce' => Fsync_Utils::random_hex(16))
);
$old_signature = Fsync_Signer::sign(
    Fsync_Signer_Hmac::ALGORITHM,
    $secret,
    Fsync_Signer::canonical($old_parts)
);

$stale = wp_remote_get(
    $url,
    array(
        'timeout' => 20,
        'redirection' => 0,
        'sslverify' => false,
        'headers' => Fsync_Signer::headers($old_parts, $old_signature),
    )
);

$stale_body = is_wp_error($stale) ? array() : json_decode((string) wp_remote_retrieve_body($stale), true);
fsync_check(
    is_array($stale_body) && ($stale_body['code'] ?? '') === 'fsync_clock_skew',
    'an hour-old but correctly signed request is rejected for clock skew'
);

WP_CLI::log('--- config API ---');

$introspect = $client->get('config/introspect', array('include_meta_keys' => 'false'));
fsync_check(! is_wp_error($introspect), 'introspect endpoint reachable with a deploy key');

if (! is_wp_error($introspect)) {
    fsync_check(isset($introspect['post_types']['page']), 'introspect reports the page post type');
    fsync_check(isset($introspect['options']), 'introspect reports options');

    $protected = array_filter(
        (array) $introspect['options'],
        static function ($option) {
            return in_array($option['name'], array('siteurl', 'home', 'cron'), true);
        }
    );
    fsync_check($protected === array(), 'introspect omits protected options entirely');
}

WP_CLI::log('');

if (Fsync_Checks::$failed !== array()) {
    WP_CLI::error(
        sprintf(
            '%d passed, %d FAILED: %s',
            Fsync_Checks::$passed,
            count(Fsync_Checks::$failed),
            implode(' | ', Fsync_Checks::$failed)
        )
    );
}

WP_CLI::success(sprintf('%d checks passed', Fsync_Checks::$passed));
