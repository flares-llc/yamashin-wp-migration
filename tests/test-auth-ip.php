<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * IP allowlisting is a security control, and CIDR maths is easy to get subtly
 * wrong in ways that fail open. Every boundary here is pinned deliberately.
 */

T::group('Fsync_Auth::ip_matches literals');

T::ok(Fsync_Auth::ip_matches('192.168.1.5', '192.168.1.5'), 'identical IPv4 matches');
T::ok(! Fsync_Auth::ip_matches('192.168.1.5', '192.168.1.6'), 'different IPv4 does not match');
T::ok(Fsync_Auth::ip_matches('::1', '::1'), 'identical IPv6 matches');
T::ok(
    Fsync_Auth::ip_matches('2001:db8::1', '2001:0db8:0:0:0:0:0:1'),
    'equivalent IPv6 literal spellings match'
);
T::ok(! Fsync_Auth::ip_matches('', '192.168.1.5'), 'empty address never matches');
T::ok(! Fsync_Auth::ip_matches('192.168.1.5', ''), 'empty pattern never matches');

T::group('Fsync_Auth::ip_matches CIDR');

T::ok(Fsync_Auth::ip_matches('192.168.1.5', '192.168.1.0/24'), 'address inside /24 matches');
T::ok(! Fsync_Auth::ip_matches('192.168.2.5', '192.168.1.0/24'), 'address outside /24 does not match');
T::ok(Fsync_Auth::ip_matches('10.1.2.3', '10.0.0.0/8'), 'address inside /8 matches');
T::ok(! Fsync_Auth::ip_matches('11.1.2.3', '10.0.0.0/8'), 'address outside /8 does not match');
T::ok(Fsync_Auth::ip_matches('172.16.0.1', '172.16.0.0/12'), 'lower bound of /12 matches');
T::ok(Fsync_Auth::ip_matches('172.31.255.254', '172.16.0.0/12'), 'upper bound of /12 matches');
T::ok(! Fsync_Auth::ip_matches('172.32.0.1', '172.16.0.0/12'), 'just past /12 does not match');

// Non-byte-aligned prefixes are where hand-rolled masks usually break.
T::ok(Fsync_Auth::ip_matches('192.168.1.100', '192.168.1.64/26'), 'inside a /26 matches');
T::ok(! Fsync_Auth::ip_matches('192.168.1.128', '192.168.1.64/26'), 'outside a /26 does not match');
T::ok(Fsync_Auth::ip_matches('192.168.1.1', '192.168.1.0/31'), 'inside a /31 matches');
T::ok(! Fsync_Auth::ip_matches('192.168.1.2', '192.168.1.0/31'), 'outside a /31 does not match');
T::ok(Fsync_Auth::ip_matches('192.168.1.7', '192.168.1.7/32'), 'a /32 matches only itself');
T::ok(! Fsync_Auth::ip_matches('192.168.1.8', '192.168.1.7/32'), 'a /32 excludes its neighbour');
T::ok(Fsync_Auth::ip_matches('8.8.8.8', '0.0.0.0/0'), 'a /0 matches everything');

T::group('Fsync_Auth::ip_matches mixed families');

// Comparing a 4-byte and a 16-byte packed address would otherwise compare
// unrelated bytes and could match by accident.
T::ok(! Fsync_Auth::ip_matches('::1', '192.168.1.0/24'), 'IPv6 address against IPv4 range does not match');
T::ok(! Fsync_Auth::ip_matches('192.168.1.5', '2001:db8::/32'), 'IPv4 address against IPv6 range does not match');
T::ok(Fsync_Auth::ip_matches('2001:db8::1', '2001:db8::/32'), 'IPv6 inside its range matches');
T::ok(! Fsync_Auth::ip_matches('2001:db9::1', '2001:db8::/32'), 'IPv6 outside its range does not match');

T::group('Fsync_Auth::ip_matches malformed input');

T::ok(! Fsync_Auth::ip_matches('not-an-ip', '192.168.1.0/24'), 'non-address input does not match');
T::ok(! Fsync_Auth::ip_matches('192.168.1.5', 'garbage/24'), 'malformed subnet does not match');
T::ok(! Fsync_Auth::ip_matches('192.168.1.5', '192.168.1.0/33'), 'out-of-range prefix does not match');
T::ok(! Fsync_Auth::ip_matches('192.168.1.5', '192.168.1.0/-1'), 'negative prefix does not match');
T::ok(! Fsync_Auth::ip_matches('192.168.1.5', '192.168.1.0/24junk'), 'a partially numeric prefix does not match');

T::group('Fsync_Auth::check_ip allowlist');

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
update_option(Fsync_Auth::OPTION_TRUSTED_PROXIES, array());
T::same(true, Fsync_Auth::check_ip(array('ip_allowlist' => array())), 'an empty allowlist permits a request');
T::same(
    true,
    Fsync_Auth::check_ip(array('ip_allowlist' => array('203.0.113.0/24'))),
    'an address inside the key allowlist is permitted'
);
$denied = Fsync_Auth::check_ip(array('ip_allowlist' => array('198.51.100.0/24')));
T::is_error($denied, 'fsync_ip_denied', 'an address outside the key allowlist is denied');

T::group('Fsync_Keys IP allowlist validation');

foreach (array('203.0.113.4', '10.0.0.0/8', '2001:db8::/32', '::1') as $pattern) {
    T::ok(Fsync_Keys::valid_ip_pattern($pattern), sprintf('valid allowlist entry accepted: %s', $pattern));
}
foreach (array('not-an-ip', '192.168.1.0/33', '2001:db8::/129', '10.0.0.0/8junk', '10.0.0.0/8/1') as $pattern) {
    T::ok(! Fsync_Keys::valid_ip_pattern($pattern), sprintf('invalid allowlist entry rejected: %s', $pattern));
}

T::group('Fsync_Auth::client_ip trusted proxies');

$saved_server = $_SERVER;
$saved_options = $GLOBALS['fsync_test_options'];

$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.66';
update_option(Fsync_Auth::OPTION_TRUSTED_PROXIES, array());
T::same('198.51.100.20', Fsync_Auth::client_ip(), 'an untrusted sender cannot spoof X-Forwarded-For');

$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20';
update_option(Fsync_Auth::OPTION_TRUSTED_PROXIES, array('10.0.0.0/8'));
T::same('198.51.100.20', Fsync_Auth::client_ip(), 'a trusted proxy exposes its client');

$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20, 10.0.0.3';
T::same('198.51.100.20', Fsync_Auth::client_ip(), 'a chain of trusted proxies is traversed from the server side');

// A proxy commonly appends the address it observed to a pre-existing header.
// Reading the left-most value would trust the value supplied by the attacker.
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.66, 198.51.100.20';
T::same('198.51.100.20', Fsync_Auth::client_ip(), 'a spoofed left-most forwarded address is ignored');

$_SERVER = $saved_server;
$GLOBALS['fsync_test_options'] = $saved_options;
