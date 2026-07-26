<?php

if (! defined('ABSPATH')) {
    exit;
}

T::group('Fsync_Config_Io::strip_jsonc');

// The failure that every naive comment stripper has: "//" inside a string.
// This configuration is full of URLs, so getting it wrong corrupts every
// document that mentions a site.
$with_url = '{"url": "https://example.jp/path"}';
T::same(
    array('url' => 'https://example.jp/path'),
    Fsync_Config_Io::parse($with_url),
    'a URL is not treated as a comment'
);

T::same(
    array('a' => 1),
    Fsync_Config_Io::parse("{\n  // explanation\n  \"a\": 1\n}"),
    'line comments are removed'
);

T::same(
    array('a' => 1),
    Fsync_Config_Io::parse("{\n  /* block\n     comment */\n  \"a\": 1\n}"),
    'block comments are removed'
);

T::same(
    array('a' => 1, 'b' => 2),
    Fsync_Config_Io::parse("{\n  \"a\": 1, // trailing note\n  \"b\": 2\n}"),
    'a comment after a value is removed'
);

T::same(
    array('note' => 'keep // this'),
    Fsync_Config_Io::parse('{"note": "keep // this"}'),
    'a comment marker inside a string survives'
);

T::same(
    array('note' => 'keep /* this */ too'),
    Fsync_Config_Io::parse('{"note": "keep /* this */ too"}'),
    'a block marker inside a string survives'
);

T::same(
    array('note' => 'quote \\" then // not a comment'),
    Fsync_Config_Io::parse('{"note": "quote \\\\\" then // not a comment"}'),
    'an escaped quote does not end the string early'
);

T::same(
    array('a' => 1, 'b' => array(1, 2)),
    Fsync_Config_Io::parse("{\n  \"a\": 1,\n  \"b\": [1, 2,],\n}"),
    'trailing commas are tolerated'
);

T::same(
    array('note' => 'a, b,'),
    Fsync_Config_Io::parse('{"note": "a, b,"}'),
    'a comma inside a string is preserved'
);

T::is_error(Fsync_Config_Io::parse('{not json'), 'fsync_config_parse_failed', 'malformed JSON is an error');
T::is_error(Fsync_Config_Io::parse('"a string"'), 'fsync_config_not_object', 'a scalar document is rejected');

T::group('Fsync_Config_Io::merge');

T::same(
    array('a' => 1, 'b' => 2),
    Fsync_Config_Io::merge(array('a' => 1), array('b' => 2)),
    'disjoint keys merge'
);

T::same(
    array('a' => array('x' => 1, 'y' => 9)),
    Fsync_Config_Io::merge(array('a' => array('x' => 1, 'y' => 2)), array('a' => array('y' => 9))),
    'maps merge recursively'
);

// Lists replace rather than append, so an override can shorten an allowlist.
T::same(
    array('a' => array(3)),
    Fsync_Config_Io::merge(array('a' => array(1, 2)), array('a' => array(3))),
    'lists are replaced, not appended'
);

T::same(
    array('a' => 'scalar'),
    Fsync_Config_Io::merge(array('a' => array('x' => 1)), array('a' => 'scalar')),
    'a scalar override replaces a map'
);

T::group('Fsync_Config protected options');

foreach (array('siteurl', 'home', 'active_plugins', 'cron', 'blog_public', 'admin_email') as $name) {
    T::ok(Fsync_Config::is_protected_option($name), sprintf('%s is protected', $name));
}

T::ok(Fsync_Config::is_protected_option('_transient_foo'), 'transients are protected');
T::ok(Fsync_Config::is_protected_option('_site_transient_timeout_bar'), 'site transient timeouts are protected');
T::ok(Fsync_Config::is_protected_option('fsync_config'), 'the plugin\'s own options are protected');

// Prefix-dependent option names must resolve against the live prefix, or a
// site whose prefix is not wp_ would sync its role definitions.
T::ok(Fsync_Config::is_protected_option('wp_user_roles'), 'prefix-dependent user_roles is protected');

T::ok(! Fsync_Config::is_protected_option('blogdescription'), 'an ordinary option is not protected');
T::ok(Fsync_Config::is_warned_option('permalink_structure'), 'permalink_structure is flagged for review');

T::ok(Fsync_Config::is_protected_meta('_edit_lock'), 'editor lock meta is protected');
T::ok(Fsync_Config::is_protected_meta('_oembed_abc123'), 'oembed cache meta is protected');
T::ok(Fsync_Config::is_protected_meta('_fsync_uid'), 'the plugin\'s own meta is protected');
T::ok(! Fsync_Config::is_protected_meta('hero_links'), 'an ordinary meta key is not protected');

T::group('Fsync_Config::credential_references');

T::same(
    array('peer-prod', 'gcs-backup'),
    Fsync_Config::credential_references(
        array(
            'environments' => array('production' => array('credential' => 'peer-prod')),
            'storage' => array('main' => array('credential' => 'gcs-backup')),
        )
    ),
    'credential ids are collected from anywhere in the document'
);

// ---------------------------------------------------------------------------

/**
 * @param array $overrides
 * @return array
 */
function fsync_test_config(array $overrides = array())
{
    return Fsync_Config_Io::merge(
        array(
            'config_version' => 1,
            'sync' => array(
                'scope' => array(
                    'post_types' => array('page' => array('statuses' => array('publish'))),
                    'options' => array('allow' => array('blogdescription')),
                ),
                'policy' => array('conflict' => 'manual'),
            ),
            'environments' => array(
                'local' => array('role' => 'source'),
                'staging' => array(
                    'url' => 'https://stg.example.jp/',
                    'credential' => 'peer-stg',
                    'promotes_to' => array('production'),
                ),
                'production' => array(
                    'url' => 'https://example.jp/',
                    'credential' => 'peer-prod',
                    'requires_verified_on' => array('staging'),
                ),
            ),
        ),
        $overrides
    );
}

/**
 * @param array $result
 * @param string $code
 * @return bool
 */
function fsync_has_issue(array $result, $code)
{
    foreach (array_merge($result['errors'], $result['warnings']) as $issue) {
        if ($issue['code'] === $code) {
            return true;
        }
    }

    return false;
}

/**
 * @param array $result
 * @param string $code
 * @return string
 */
function fsync_pointer_for(array $result, $code)
{
    foreach (array_merge($result['errors'], $result['warnings']) as $issue) {
        if ($issue['code'] === $code) {
            return $issue['pointer'];
        }
    }

    return '';
}

$context = array('credentials' => array('peer-stg', 'peer-prod'));

T::group('Fsync_Config_Validate happy path');

$result = Fsync_Config_Validate::check(fsync_test_config(), $context);
T::ok($result['ok'], 'a well-formed document validates');
T::same(array(), $result['errors'], 'no errors on a valid document');

T::group('Fsync_Config_Validate secret leakage');

// This is the single most important rule: the document goes into git.
$leaks = array(
    'private_key' => array('storage' => array('gcs' => array('type' => 'gcs', 'bucket' => 'b', 'private_key' => 'x'))),
    'client_secret' => array('storage' => array('g' => array('type' => 'gcs', 'bucket' => 'b', 'client_secret' => 'x'))),
    'password' => array('sync' => array('password' => 'hunter2')),
    'token' => array('notify' => array('n' => array('type' => 'slack', 'token' => 'abc'))),
);

foreach ($leaks as $label => $overrides) {
    $result = Fsync_Config_Validate::check(fsync_test_config($overrides), $context);
    T::ok(! $result['ok'], sprintf('a %s key is rejected', $label));
    T::ok(fsync_has_issue($result, 'secret_in_config'), sprintf('a %s key reports secret_in_config', $label));
}

$pem = fsync_test_config(array('sync' => array('note' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----")));
$result = Fsync_Config_Validate::check($pem, $context);
T::ok(! $result['ok'], 'an inline PEM block is rejected');

$sa = fsync_test_config(array('sync' => array('note' => '{"type": "service_account", "project_id": "x"}')));
T::ok(! Fsync_Config_Validate::check($sa, $context)['ok'], 'an inline service account JSON is rejected');

$slack = fsync_test_config(
    array('notify' => array('ops' => array('type' => 'slack', 'url' => 'https://hooks.slack.com/services/T0/B0/abcdefgh')))
);
T::ok(! Fsync_Config_Validate::check($slack, $context)['ok'], 'an inline Slack webhook URL is rejected');

$blob = fsync_test_config(array('sync' => array('note' => 'aGVsbG8gd29ybGQgdGhpcyBpcyBhIHNlY3JldCB2YWx1ZQ==')));
T::ok(! Fsync_Config_Validate::check($blob, $context)['ok'], 'a long base64 blob is rejected');

// Hashes are hex and legitimately appear; they must not be mistaken for keys.
$hash = fsync_test_config(array('sync' => array('note' => str_repeat('a1b2c3d4', 8))));
T::ok(Fsync_Config_Validate::check($hash, $context)['ok'], 'a hex hash is not mistaken for a secret');

$as_value = fsync_test_config(
    array('environments' => array('staging' => array('credential' => 'not a valid id!!')))
);
$result = Fsync_Config_Validate::check($as_value, $context);
T::ok(fsync_has_issue($result, 'credential_not_id'), 'a credential that is not an id is rejected');

T::group('Fsync_Config_Validate scope rules');

$protected = fsync_test_config(
    array('sync' => array('scope' => array('options' => array('allow' => array('blogdescription', 'siteurl')))))
);
$result = Fsync_Config_Validate::check($protected, $context);
T::ok(! $result['ok'], 'allowing a protected option is an error');
T::same('/sync/scope/options/allow/1', fsync_pointer_for($result, 'protected_option'), 'the pointer names the exact array index');

$deny = fsync_test_config(
    array('sync' => array('scope' => array('options' => array('deny' => array('x')))))
);
T::ok(fsync_has_issue(Fsync_Config_Validate::check($deny, $context), 'options_deny_unsupported'), 'option deny lists are rejected');

$warned = fsync_test_config(
    array('sync' => array('scope' => array('options' => array('allow' => array('permalink_structure')))))
);
$result = Fsync_Config_Validate::check($warned, $context);
T::ok($result['ok'], 'a sensitive option is allowed');
T::ok(fsync_has_issue($result, 'sensitive_option'), 'a sensitive option produces a warning');

$bad_meta = fsync_test_config(
    array('sync' => array('scope' => array('post_types' => array('page' => array('meta' => array('mode' => 'everything'))))))
);
T::ok(fsync_has_issue(Fsync_Config_Validate::check($bad_meta, $context), 'invalid_meta_mode'), 'an invalid meta mode is rejected');

$known = array('introspect' => array('post_types' => array('page' => array()), 'taxonomies' => array()), 'credentials' => $context['credentials']);
$unknown_type = fsync_test_config(
    array('sync' => array('scope' => array('post_types' => array('nonexistent' => array()))))
);
$result = Fsync_Config_Validate::check($unknown_type, $known);
T::ok(fsync_has_issue($result, 'unknown_post_type'), 'a post type this site does not register is flagged');

$core = fsync_test_config(array('sync' => array('scope' => array('files' => array('core' => 'sync')))));
T::ok(fsync_has_issue(Fsync_Config_Validate::check($core, $context), 'core_sync_enabled'), 'core file sync warns');

$bad_core = fsync_test_config(array('sync' => array('scope' => array('files' => array('core' => 'maybe')))));
T::ok(fsync_has_issue(Fsync_Config_Validate::check($bad_core, $context), 'invalid_core_mode'), 'an invalid core mode is rejected');

T::group('Fsync_Config_Validate structure');

$no_version = fsync_test_config();
unset($no_version['config_version']);
T::ok(fsync_has_issue(Fsync_Config_Validate::check($no_version, $context), 'missing_config_version'), 'config_version is required');

$bad_version = fsync_test_config(array('config_version' => 2));
T::ok(fsync_has_issue(Fsync_Config_Validate::check($bad_version, $context), 'unsupported_config_version'), 'an unknown version is rejected');

$bad_conflict = fsync_test_config(array('sync' => array('policy' => array('conflict' => 'yolo'))));
T::ok(fsync_has_issue(Fsync_Config_Validate::check($bad_conflict, $context), 'invalid_conflict_policy'), 'an invalid conflict policy is rejected');

$deletes = fsync_test_config(array('sync' => array('policy' => array('allow_delete' => true))));
T::ok(fsync_has_issue(Fsync_Config_Validate::check($deletes, $context), 'delete_enabled'), 'enabling deletes warns');

T::group('Fsync_Config_Validate environments');

$no_url = fsync_test_config();
unset($no_url['environments']['production']['url']);
T::ok(fsync_has_issue(Fsync_Config_Validate::check($no_url, $context), 'missing_env_url'), 'a target environment needs a URL');

// The source environment is where releases are authored and nothing connects
// to it, so it legitimately has neither URL nor credential.
$source_only = array(
    'config_version' => 1,
    'environments' => array('local' => array('role' => 'source')),
);
T::ok(Fsync_Config_Validate::check($source_only, $context)['ok'], 'a source environment needs no URL or credential');

$insecure = fsync_test_config(array('environments' => array('production' => array('url' => 'http://example.jp/'))));
T::ok(fsync_has_issue(Fsync_Config_Validate::check($insecure, $context), 'insecure_env_url'), 'plain HTTP to a public host is rejected');

$local_http = fsync_test_config(array('environments' => array('production' => array('url' => 'http://localhost:8183/'))));
T::ok(! fsync_has_issue(Fsync_Config_Validate::check($local_http, $context), 'insecure_env_url'), 'plain HTTP to localhost is allowed');

$dangling = fsync_test_config(
    array('environments' => array('staging' => array('promotes_to' => array('nowhere'))))
);
T::ok(fsync_has_issue(Fsync_Config_Validate::check($dangling, $context), 'unknown_environment_reference'), 'a promotion target must exist');

$selfref = fsync_test_config(
    array('environments' => array('staging' => array('promotes_to' => array('staging'))))
);
T::ok(fsync_has_issue(Fsync_Config_Validate::check($selfref, $context), 'self_referential_environment'), 'an environment cannot promote to itself');

// A gate nothing can satisfy blocks every release forever, silently.
$broken_gate = fsync_test_config(
    array('environments' => array('staging' => array('promotes_to' => array())))
);
T::ok(
    fsync_has_issue(Fsync_Config_Validate::check($broken_gate, $context), 'promotion_path_incomplete'),
    'an unsatisfiable promotion gate is flagged'
);

T::group('Fsync_Config_Validate storage and schedules');

$gdrive = fsync_test_config(
    array('storage' => array('drive' => array('type' => 'gdrive', 'credential' => 'gd')))
);
T::ok(
    fsync_has_issue(Fsync_Config_Validate::check($gdrive, array('credentials' => array('peer-stg', 'peer-prod', 'gd'))), 'missing_shared_drive'),
    'Google Drive without a shared drive id is rejected'
);

$gcs = fsync_test_config(array('storage' => array('main' => array('type' => 'gcs', 'credential' => 'g'))));
T::ok(
    fsync_has_issue(Fsync_Config_Validate::check($gcs, array('credentials' => array('peer-stg', 'peer-prod', 'g'))), 'missing_bucket'),
    'GCS without a bucket is rejected'
);

$dest = fsync_test_config(array('backup' => array('destinations' => array('local', 'nowhere'))));
T::ok(fsync_has_issue(Fsync_Config_Validate::check($dest, $context), 'unknown_destination'), 'an undefined backup destination is rejected');

$schedule = fsync_test_config(
    array('schedules' => array(array('name' => 's', 'job' => 'drift_check', 'env' => 'ghost', 'interval' => 'fsync_daily')))
);
T::ok(fsync_has_issue(Fsync_Config_Validate::check($schedule, $context), 'unknown_schedule_environment'), 'a schedule against an undefined environment is rejected');

$auto = fsync_test_config(
    array('schedules' => array(array('name' => 's', 'job' => 'sync_push', 'env' => 'production', 'interval' => 'fsync_daily', 'auto_apply' => true)))
);
T::ok(fsync_has_issue(Fsync_Config_Validate::check($auto, $context), 'unattended_apply'), 'unattended apply warns');

T::group('Fsync_Config_Validate credentials');

$result = Fsync_Config_Validate::check(fsync_test_config(), array('credentials' => array('peer-stg')));
T::ok(fsync_has_issue($result, 'credential_not_set'), 'a referenced but unregistered credential is reported');
T::ok($result['ok'], 'an unregistered credential is a warning, not a hard failure');

T::group('Fsync_Config_Validate::escape_pointer');

T::same('a~1b', Fsync_Config_Validate::escape_pointer('a/b'), 'slashes are escaped as ~1');
T::same('a~0b', Fsync_Config_Validate::escape_pointer('a~b'), 'tildes are escaped as ~0');
