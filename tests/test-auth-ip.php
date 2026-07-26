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
