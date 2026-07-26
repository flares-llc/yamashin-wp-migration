<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The shipped example is documentation that people copy verbatim. If it does
 * not survive our own parser and validator, it is worse than no example at all.
 */

T::group('shipped example configuration');

$path = __DIR__ . '/../flares-sync.config.example.jsonc';
T::ok(is_readable($path), 'the example config exists');

$raw = (string) file_get_contents($path);
T::ok(strpos($raw, '//') !== false, 'the example actually uses comments (so JSONC parsing is exercised)');

$document = Fsync_Config_Io::parse($raw);
T::ok(! is_wp_error($document), 'the example parses as JSONC');

if (is_wp_error($document)) {
    return;
}

T::same(1, $document['config_version'] ?? null, 'config_version survives parsing');
T::same('https://stg.example.jp/', $document['environments']['staging']['url'] ?? '', 'a URL with // survives parsing');
T::same(
    array('production'),
    $document['environments']['staging']['promotes_to'] ?? array(),
    'the promotion path survives parsing'
);

$result = Fsync_Config_Validate::check(
    $document,
    array(
        'credentials' => array('peer-staging', 'peer-production', 'slack-ops'),
    )
);

T::ok($result['ok'], 'the example config validates without errors');

if (! $result['ok']) {
    foreach ($result['errors'] as $error) {
        printf("      %s %s: %s\n", $error['pointer'], $error['code'], $error['message']);
    }
}

// The example deliberately demonstrates protected_extra and a regex allowlist
// entry; neither should trip the secret detector.
T::ok(
    ! fsync_has_issue($result, 'secret_in_config'),
    'the example contains no values that look like credentials'
);

// Every credential in the example must be an id, never a value. This is the
// property that makes the file safe to commit.
foreach (Fsync_Config::credential_references($document) as $credential_id) {
    T::ok(
        preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $credential_id) === 1,
        sprintf('credential reference "%s" is an id, not a value', $credential_id)
    );
}

T::group('shipped example merged with defaults');

// The merged result is what the plugin actually runs on, so it has to be valid
// too -- a default could contradict the example.
$merged = Fsync_Config_Io::merge(Fsync_Config::defaults(), $document);
$merged_result = Fsync_Config_Validate::check(
    $merged,
    array('credentials' => array('peer-staging', 'peer-production', 'slack-ops'))
);

T::ok($merged_result['ok'], 'defaults merged with the example still validate');
T::same('manual', $merged['sync']['policy']['conflict'] ?? '', 'the conservative conflict policy survives the merge');
T::same(false, $merged['sync']['policy']['allow_delete'] ?? null, 'deletes stay disabled after the merge');
