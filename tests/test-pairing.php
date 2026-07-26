<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pairing blob parsing. The blob is the one moment a shared secret travels, so
 * every rejection path matters more than the happy path.
 */

/**
 * @param array $overrides
 * @return string
 */
function fsync_test_blob(array $overrides = array())
{
    $payload = array_merge(
        array(
            'v' => 1,
            'site' => 'https://stg.example.jp/',
            'role' => 'staging',
            'env' => 'staging',
            'key_id' => 'abcdef0123456789',
            'secret' => base64_encode(str_repeat("\x42", 32)),
            'caps' => array('status', 'read', 'write'),
            'alg' => 'hmac-sha256',
            'protocol' => FSYNC_PROTOCOL,
            'hash_algo_version' => FSYNC_HASH_ALGO_VERSION,
            'plugin_version' => '0.1.0',
            'expires_at' => time() + 600,
        ),
        $overrides
    );

    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($payload[$key]);
        }
    }

    return Fsync_Utils::base64url_encode((string) json_encode($payload));
}

T::group('Fsync_Pairing::parse happy path');

$parsed = Fsync_Pairing::parse(fsync_test_blob());
T::ok(! is_wp_error($parsed), 'a well-formed blob parses');
T::same('https://stg.example.jp/', $parsed['site'] ?? '', 'site URL survives');
T::same('staging', $parsed['env'] ?? '', 'environment name survives');
T::same('abcdef0123456789', $parsed['key_id'] ?? '', 'key id survives');
T::same(array('status', 'read', 'write'), $parsed['caps'] ?? array(), 'capabilities survive');

$max_env = str_repeat('a', 59);
T::same(
    $max_env,
    Fsync_Pairing::parse(fsync_test_blob(array('env' => $max_env)))['env'] ?? '',
    'the longest environment name that fits its peer credential id is accepted'
);
T::is_error(
    Fsync_Pairing::parse(fsync_test_blob(array('env' => str_repeat('a', 60)))),
    'fsync_env_name_invalid',
    'an environment name that cannot fit its peer credential id is rejected'
);

$canonical_url = Fsync_Pairing::parse(
    fsync_test_blob(array('site' => 'HTTPS://STG.EXAMPLE.JP:443/api'))
);
T::same(
    'https://stg.example.jp/api/',
    is_wp_error($canonical_url) ? '' : $canonical_url['site'],
    'scheme, hostname and default port are canonicalized'
);

// Copying through a chat client or email frequently inserts line breaks.
$wrapped = wordwrap(fsync_test_blob(), 40, "\n", true);
T::ok(! is_wp_error(Fsync_Pairing::parse($wrapped)), 'whitespace and line breaks are tolerated');
T::ok(! is_wp_error(Fsync_Pairing::parse('  ' . fsync_test_blob() . "\n")), 'surrounding whitespace is tolerated');

T::group('Fsync_Pairing::parse rejections');

T::is_error(Fsync_Pairing::parse(''), 'fsync_pairing_empty', 'empty input rejected');
T::is_error(Fsync_Pairing::parse('%%%not base64%%%'), 'fsync_pairing_malformed', 'undecodable input rejected');
T::is_error(
    Fsync_Pairing::parse(Fsync_Utils::base64url_encode('this is not json')),
    'fsync_pairing_malformed',
    'non-JSON payload rejected'
);
T::is_error(Fsync_Pairing::parse(fsync_test_blob(array('v' => 99))), 'fsync_pairing_version', 'unknown blob version rejected');

foreach (array('site', 'env', 'key_id', 'secret') as $field) {
    T::is_error(
        Fsync_Pairing::parse(fsync_test_blob(array($field => null))),
        'fsync_pairing_incomplete',
        sprintf('missing %s rejected', $field)
    );
}

T::is_error(
    Fsync_Pairing::parse(fsync_test_blob(array('expires_at' => time() - 1))),
    'fsync_pairing_expired',
    'expired blob rejected'
);

// A protocol or hash-algorithm mismatch must fail at pairing time with a clear
// message, rather than later as an unexplained signature or diff failure.
T::is_error(
    Fsync_Pairing::parse(fsync_test_blob(array('protocol' => 'FSYNC0'))),
    'fsync_pairing_protocol',
    'protocol mismatch rejected'
);
T::is_error(
    Fsync_Pairing::parse(fsync_test_blob(array('hash_algo_version' => 99))),
    'fsync_pairing_hash_algo',
    'hash algorithm mismatch rejected'
);

T::is_error(
    Fsync_Pairing::parse(fsync_test_blob(array('site' => 'not a url'))),
    'fsync_pairing_url',
    'malformed site URL rejected'
);

// Plain HTTP is refused for real hosts, because the payloads themselves are
// not encrypted even though the signature protects integrity.
T::is_error(
    Fsync_Pairing::parse(fsync_test_blob(array('site' => 'http://prod.example.jp/'))),
    'fsync_pairing_insecure',
    'plain HTTP to a public host rejected'
);

T::group('Fsync_Pairing local hosts');

// Development environments run over HTTP by necessity, so those are allowed.
foreach (
    array(
        'http://localhost:8091/',
        'http://127.0.0.1:8093/',
        // A single-label hostname: a docker-compose service, a LAN machine or
        // an /etc/hosts entry. It cannot be a public DNS name, because every
        // internet-reachable host has at least one dot.
        'http://fsync_stg/',
        'http://intranet:8080/',
        'http://example.test/',
        'http://site.local/',
        'http://192.168.1.10/',
        'http://172.18.0.4/',
    ) as $url
) {
    T::ok(
        Fsync_Pairing::is_local_url($url),
        sprintf('%s is treated as a development or internal host', $url)
    );
}

foreach (
    array(
        'https://example.jp/',
        'http://example.jp/',
        'http://sub.example.co.jp/',
        'http://8.8.8.8/',
    ) as $url
) {
    T::ok(
        ! Fsync_Pairing::is_local_url($url),
        sprintf('%s is treated as a public host', $url)
    );
}

T::ok(! Fsync_Pairing::is_local_url('not-a-url'), 'a malformed URL is not local');

T::ok(
    ! is_wp_error(Fsync_Pairing::parse(fsync_test_blob(array('site' => 'http://localhost:8082/')))),
    'plain HTTP to localhost is accepted'
);
T::ok(
    ! is_wp_error(Fsync_Pairing::parse(fsync_test_blob(array('site' => 'http://[::1]:8082/')))),
    'plain HTTP to IPv6 localhost is accepted'
);

foreach (
    array(
        'https://user:secret@example.jp/',
        'https://example.jp/?token=secret',
        'https://example.jp/#fragment',
    ) as $url
) {
    T::is_error(
        Fsync_Pairing::parse(fsync_test_blob(array('site' => $url))),
        'fsync_pairing_url',
        sprintf('unsafe URL components are rejected: %s', $url)
    );
}
