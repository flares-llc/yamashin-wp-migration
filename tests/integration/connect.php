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
fsync_check(! empty($peer['handshake']), 'pairing response remains recorded on the peer');

// The credential store must never hand a value back through a listing path.
$meta = Fsync_Credentials::meta('peer-' . $env_name);
fsync_check(is_array($meta) && ! isset($meta['value']), 'credential metadata carries no value');
fsync_check(is_array($meta) && strlen((string) $meta['fingerprint']) === 8, 'credential exposes only a fingerprint');

WP_CLI::log('--- blob is single use ---');

// A blob that could be replayed would let anyone who saw it pair themselves.
$replay_env = substr($env_name . '-replay-' . $peer['outbound_key_id'], 0, 64);
$again = Fsync_Pairing::connect($blob, $replay_env);
fsync_check(is_wp_error($again), 'reusing the same blob is refused');
if (is_wp_error($again)) {
    WP_CLI::log('       -> ' . $again->get_error_code() . ': ' . $again->get_error_message());
}

// A terminal confirmation error must roll back the import. Keeping it is only
// useful for retryable network failures; for a consumed blob it creates a peer
// and credential that can never work.
$replay_peer = Fsync_Peer::by_env($replay_env);
$replay_credential = 'peer-' . $replay_env;
fsync_check($replay_peer === null, 'terminal pairing failure leaves no peer row');
fsync_check(! Fsync_Credentials::has($replay_credential), 'terminal pairing failure leaves no credential');

// Keep the harness idempotent even when this assertion catches a regression.
if ($replay_peer !== null) {
    Fsync_Peer::forget($replay_peer['peer_id']);
}
if (Fsync_Credentials::has($replay_credential)) {
    Fsync_Credentials::clear($replay_credential);
}

WP_CLI::log('--- environment identity ---');

$decoded_blob = Fsync_Utils::base64url_decode((string) preg_replace('/\s+/', '', $blob));
$conflicting_payload = is_string($decoded_blob) ? json_decode($decoded_blob, true) : null;
if (is_array($conflicting_payload)) {
    $conflicting_payload['site'] = 'http://different-' . $env_name . '/';
    $conflicting_blob = Fsync_Utils::base64url_encode((string) wp_json_encode($conflicting_payload));
    $conflict = Fsync_Pairing::import($conflicting_blob, $env_name);

    fsync_check(
        is_wp_error($conflict) && $conflict->get_error_code() === 'fsync_pairing_env_conflict',
        'the same environment name cannot replace a different site'
    );

    $after_conflict = Fsync_Peer::by_env($env_name);
    fsync_check(
        $after_conflict !== null && $after_conflict['url'] === $peer['url'],
        'a rejected environment collision leaves the original peer unchanged'
    );

    // Restore state when running against an older implementation where the
    // collision was incorrectly accepted.
    if ($after_conflict !== null && $after_conflict['url'] !== $peer['url']) {
        Fsync_Pairing::import($blob, $env_name);
    }
} else {
    fsync_check(false, 'the pairing blob can be decoded for collision testing');
    fsync_check(false, 'a rejected environment collision leaves the original peer unchanged');
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

// A database write failure is operational, not evidence of a replay. The
// deliberately oversized key id reaches the table's strict length check.
$store_failure = Fsync_Nonce_Store::remember(str_repeat('f', 33), Fsync_Utils::random_hex(16));
fsync_check(
    is_wp_error($store_failure) && $store_failure->get_error_code() === 'fsync_nonce_store_failed',
    'a non-duplicate nonce insert failure is not mislabeled as replay'
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

$default_introspect = $client->get(
    'config/introspect',
    array('include_options' => 'false', 'include_users' => 'false')
);
fsync_check(! is_wp_error($default_introspect), 'default introspect request succeeds');
fsync_check(
    is_array($default_introspect) && ! array_key_exists('meta_keys', $default_introspect),
    'expensive meta-key aggregation is opt-in'
);

$invalid_remote_document = Fsync_Config::defaults();
$invalid_remote_document['config_version'] = 999;
$signed_apply = $client->post('config/apply', array('document' => $invalid_remote_document));
fsync_check(
    is_wp_error($signed_apply)
        && $signed_apply->get_error_code() === 'fsync_config_invalid'
        && (int) (($signed_apply->get_error_data()['status'] ?? 0)) === 422,
    'a signed deploy key reaches config apply validation through its write capability'
);

WP_CLI::log('--- environment capability cache ---');

delete_transient(Fsync_Env::TRANSIENT_GET_LOCK);
Fsync_Env::flush();
$supports_get_lock = Fsync_Env::supports_get_lock();
$cached_get_lock = get_transient(Fsync_Env::TRANSIENT_GET_LOCK);
fsync_check(
    $cached_get_lock === ($supports_get_lock ? 'yes' : 'no'),
    'GET_LOCK capability probe is cached across requests'
);
fsync_check(
    Fsync_Env::supports_get_lock() === $supports_get_lock,
    'cached GET_LOCK capability keeps the same result'
);

WP_CLI::log('--- key lifecycle ---');

$issued_rotation = Fsync_Keys::issue(
    array(
        'label' => 'integration-rotation',
        'capabilities' => Fsync_Keys::PRESETS['readonly'],
        'direction' => Fsync_Keys::DIRECTION_INBOUND,
        'status' => Fsync_Keys::STATUS_ACTIVE,
    )
);
fsync_check(! is_wp_error($issued_rotation), 'an active key can be issued for rotation testing');

$rotated = is_wp_error($issued_rotation)
    ? new WP_Error('fsync_test_skipped', 'initial key issue failed')
    : Fsync_Keys::rotate($issued_rotation['key_id']);
fsync_check(! is_wp_error($rotated), 'an active key can be rotated');

if (! is_wp_error($issued_rotation) && ! is_wp_error($rotated)) {
    $old_key = Fsync_Keys::find($issued_rotation['key_id']);
    $new_key = Fsync_Keys::find($rotated['key_id']);
    fsync_check($old_key !== null && $old_key['grace_until'] > Fsync_Utils::now(), 'the old key receives a grace period');
    fsync_check($old_key !== null && Fsync_Keys::usable($old_key) === true, 'the old key remains usable during grace');
    fsync_check(
        $new_key !== null
            && is_wp_error(Fsync_Keys::usable($new_key))
            && Fsync_Keys::usable($new_key)->get_error_code() === 'fsync_key_pending',
        'the replacement stays pending until possession is confirmed'
    );
    fsync_check(
        is_wp_error(Fsync_Keys::rotate($rotated['key_id'])),
        'a pending replacement cannot be rotated again'
    );
    $duplicate_rotation = Fsync_Keys::rotate($issued_rotation['key_id']);
    fsync_check(
        is_wp_error($duplicate_rotation)
            && $duplicate_rotation->get_error_code() === 'fsync_key_rotation_pending',
        'an old key cannot create a second pending replacement'
    );

    $activated_rotation = Fsync_Keys::activate($rotated['key_id'], $peer['peer_id']);
    $new_key = Fsync_Keys::find($rotated['key_id']);
    fsync_check(
        $activated_rotation === true && $new_key !== null && Fsync_Keys::usable($new_key) === true,
        'the replacement becomes usable after activation'
    );

    global $wpdb;
    $wpdb->update(
        Fsync_Schema::table('keys'),
        array('grace_until' => Fsync_Utils::now() - 1),
        array('key_id' => $issued_rotation['key_id'])
    );
    $expired_old = Fsync_Keys::find($issued_rotation['key_id']);
    $expired_result = $expired_old === null ? null : Fsync_Keys::usable($expired_old);
    fsync_check(
        is_wp_error($expired_result) && $expired_result->get_error_code() === 'fsync_key_superseded',
        'the superseded key is rejected after grace expires'
    );

    fsync_check(Fsync_Keys::retire($issued_rotation['key_id']) === true, 'the old key can be retired');
    fsync_check(Fsync_Keys::retire($rotated['key_id']) === true, 'the replacement key can be retired');
}

// Rotation rows are test-only; unlike real retired keys they do not need to
// remain in the audit view after the harness completes.
if (! is_wp_error($issued_rotation)) {
    global $wpdb;
    $wpdb->delete(Fsync_Schema::table('keys'), array('key_id' => $issued_rotation['key_id']));
}
if (! is_wp_error($rotated)) {
    global $wpdb;
    $wpdb->delete(Fsync_Schema::table('keys'), array('key_id' => $rotated['key_id']));
}

$invalid_ip_key = Fsync_Keys::issue(array('ip_allowlist' => array('not-an-ip')));
fsync_check(
    is_wp_error($invalid_ip_key) && $invalid_ip_key->get_error_code() === 'fsync_ip_allowlist_invalid',
    'a malformed key IP allowlist is rejected before persistence'
);

WP_CLI::log('--- file-backed configuration ---');

$config_path = untrailingslashit(WP_CONTENT_DIR) . '/flares-sync.config.jsonc';
$config_existed = file_exists($config_path);
$config_backup = $config_existed ? file_get_contents($config_path) : false;
$stored_before_file_test = get_option(Fsync_Config_Io::OPTION_DOCUMENT, null);
$file_document = Fsync_Config::defaults();
$file_document['site_role'] = 'file-integration';
$file_json = Fsync_Config_Io::pretty($file_document);
$file_contents = is_wp_error($file_json)
    ? ''
    : "// integration file route\n" . preg_replace('/\n}$/', ",\n}\n", $file_json);
$file_written = Fsync_Fs::write_atomic($config_path, $file_contents);
Fsync_Config_Io::flush();
Fsync_Config::flush();
$file_loaded = Fsync_Config_Io::load();
fsync_check($file_written === true, 'a configuration file can be written to the discovered location');
fsync_check(
    $file_loaded['source'] === Fsync_Config_Io::SOURCE_FILE
        && $file_loaded['path'] === $config_path
        && ($file_loaded['document']['site_role'] ?? '') === 'file-integration',
    'locate and load prefer a valid JSONC file'
);
$file_save = Fsync_Config_Io::save(Fsync_Config::defaults(), 'must be refused');
fsync_check(
    is_wp_error($file_save) && $file_save->get_error_code() === 'fsync_config_file_backed',
    'database saves are refused while a configuration file is active'
);
fsync_check(
    get_option(Fsync_Config_Io::OPTION_DOCUMENT, null) === $stored_before_file_test,
    'a refused file-backed save leaves the database copy unchanged'
);

Fsync_Fs::write_atomic($config_path, '{ invalid JSONC');
Fsync_Config_Io::flush();
Fsync_Config::flush();
$broken_file = Fsync_Config_Io::load();
fsync_check(
    $broken_file['source'] === Fsync_Config_Io::SOURCE_FILE && is_wp_error($broken_file['error']),
    'a broken configuration file fails closed without using the database copy'
);

if ($config_existed && is_string($config_backup)) {
    Fsync_Fs::write_atomic($config_path, $config_backup);
} else {
    @unlink($config_path);
}
Fsync_Config_Io::flush();
Fsync_Config::flush();

WP_CLI::log('--- REST configuration apply ---');

global $wpdb;
$config_history_table = Fsync_Schema::table('config_history');
$history_checkpoint = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$config_history_table}");
$had_stored_config = get_option(Fsync_Config_Io::OPTION_DOCUMENT, null) !== null;
$stored_config_checkpoint = get_option(Fsync_Config_Io::OPTION_DOCUMENT, null);

// Start from the genuine default source so the history entry can prove which
// document it checkpoints. Restore the operator's previous DB copy afterwards.
delete_option(Fsync_Config_Io::OPTION_DOCUMENT);
Fsync_Config_Io::flush();
Fsync_Config::flush();

wp_set_current_user(1);
$rest_document = Fsync_Config::defaults();
$rest_document['site_role'] = 'rest-integration';
$apply_request = new WP_REST_Request('POST', '/' . FSYNC_REST_NAMESPACE . '/config/apply');
$apply_request->set_header('content-type', 'application/json');
$apply_request->set_body(
    (string) wp_json_encode(array('document' => $rest_document, 'note' => 'integration REST apply'))
);
$apply_response = rest_do_request($apply_request);
$apply_data = $apply_response->get_data();
fsync_check($apply_response->get_status() === 200 && ! empty($apply_data['ok']), 'REST config apply succeeds for an administrator');
fsync_check(in_array('site_role', (array) ($apply_data['changes'] ?? array()), true), 'REST apply reports the changed top-level section');
fsync_check(
    (get_option(Fsync_Config_Io::OPTION_DOCUMENT, array())['site_role'] ?? '') === 'rest-integration',
    'REST apply persists the validated document'
);
$latest_history = $wpdb->get_row(
    "SELECT source, note FROM {$config_history_table} ORDER BY id DESC LIMIT 1",
    ARRAY_A
);
fsync_check(
    is_array($latest_history)
        && ($latest_history['source'] ?? '') === Fsync_Config_Io::SOURCE_DEFAULT
        && ($latest_history['note'] ?? '') === 'integration REST apply',
    'REST apply records an accurate rollback checkpoint'
);

$history_after_apply = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$config_history_table}");
$noop_response = rest_do_request($apply_request);
$history_after_noop = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$config_history_table}");
fsync_check(
    $noop_response->get_status() === 200 && $history_after_noop === $history_after_apply,
    'reapplying an identical document does not create a false history entry'
);

$invalid_request = new WP_REST_Request('POST', '/' . FSYNC_REST_NAMESPACE . '/config/apply');
$invalid_request->set_header('content-type', 'application/json');
$invalid_document = $rest_document;
$invalid_document['config_version'] = 999;
$invalid_request->set_body((string) wp_json_encode(array('document' => $invalid_document)));
$invalid_response = rest_do_request($invalid_request);
fsync_check($invalid_response->get_status() === 422, 'REST apply rejects an invalid document with 422');
fsync_check(
    (get_option(Fsync_Config_Io::OPTION_DOCUMENT, array())['config_version'] ?? 0) === 1,
    'a rejected REST apply leaves the active document unchanged'
);

if ($had_stored_config) {
    update_option(Fsync_Config_Io::OPTION_DOCUMENT, $stored_config_checkpoint, false);
} else {
    delete_option(Fsync_Config_Io::OPTION_DOCUMENT);
}
$wpdb->query($wpdb->prepare("DELETE FROM {$config_history_table} WHERE id > %d", $history_checkpoint));
Fsync_Config_Io::flush();
Fsync_Config::flush();

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
