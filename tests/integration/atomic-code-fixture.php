<?php

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

$mode = (string) ($args[0] ?? '');
$root = WP_PLUGIN_DIR . '/fsync-atomic-fixture';
$main = $root . '/fsync-atomic-fixture.php';
$old = $root . '/old.php';
$new = $root . '/new.php';

$fail = static function ($message) {
    WP_CLI::error((string) $message);
};
$write = static function ($path, $contents) use ($fail) {
    $result = Fsync_Fs::write_atomic($path, $contents);
    if (is_wp_error($result)) {
        $fail($result->get_error_code() . ': ' . $result->get_error_message());
    }
};
$remove_tree = static function ($path) use (&$remove_tree) {
    if (is_link($path) || is_file($path)) {
        return @unlink($path);
    }
    if (! is_dir($path)) {
        return true;
    }
    foreach (array_diff((array) scandir($path), array('.', '..')) as $entry) {
        if (! $remove_tree($path . '/' . $entry)) {
            return false;
        }
    }

    return @rmdir($path);
};

if (in_array($mode, array('source', 'target', 'target-missing'), true)) {
    if (! get_option('fsync_atomic_fixture_original_config_saved', false)) {
        update_option('fsync_atomic_fixture_original_config', get_option(Fsync_Config_Io::OPTION_DOCUMENT, array()), false);
        update_option('fsync_atomic_fixture_original_config_saved', 1, false);
    }
    if ($mode === 'target-missing') {
        if (! function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        deactivate_plugins('fsync-atomic-fixture/fsync-atomic-fixture.php', true);
        if (! $remove_tree($root)) {
            $fail('fixture directory could not be removed');
        }
    } else {
        if (! is_dir($root) && ! wp_mkdir_p($root)) {
            $fail('fixture directory could not be created');
        }
        $version = $mode === 'source' ? '2.0.0' : '1.0.0';
        $write(
            $main,
            "<?php\n/**\n * Plugin Name: FSYNC Atomic Fixture\n * Version: " . $version . "\n */\n"
        );
        if ($mode === 'source') {
            $write($new, "<?php return 'new';\n");
            @unlink($old);
        } else {
            $write($old, "<?php return 'old';\n");
            @unlink($new);
        }
    }

    $document = Fsync_Config::document();
    $document['sync']['scope']['post_types'] = array(
        'page' => array(
            'statuses' => array('future'),
            'meta' => array('mode' => 'none'),
            'taxonomies' => array(),
            'delete' => false,
        ),
    );
    $document['sync']['scope']['taxonomies'] = array(
        'category' => array('meta' => array('mode' => 'none'), 'delete' => false),
    );
    $document['sync']['scope']['comments'] = false;
    $document['sync']['scope']['comments_delete'] = false;
    $document['sync']['scope']['users'] = array('enabled' => false, 'passwords' => false, 'delete' => false);
    $document['sync']['scope']['options'] = array('allow' => array(), 'delete' => false);
    $document['sync']['scope']['tables'] = array();
    $document['sync']['scope']['files'] = array(
        'uploads' => false,
        'theme' => array(),
        'plugins' => array('fsync-atomic-fixture'),
        'mu_plugins' => false,
        'core' => 'checksum-only',
        'delete' => true,
    );
    $document['sync']['policy']['allow_delete'] = true;
    update_option(Fsync_Config_Io::OPTION_DOCUMENT, $document, false);
    Fsync_Config::flush();
    if ($mode !== 'target-missing') {
        if (! function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $activated = activate_plugin('fsync-atomic-fixture/fsync-atomic-fixture.php');
        if (is_wp_error($activated)) {
            $fail($activated->get_error_message());
        }
    }
    WP_CLI::success($mode . ' fixture ready');
    return;
}

if ($mode === 'verify-new') {
    // A target-only file is intentionally not deleted until it has appeared
    // in a successful source receipt; this first pass only checks the swap.
    if (strpos((string) file_get_contents($main), '2.0.0') === false || ! is_file($new)) {
        $fail('atomic fixture did not reach the source state');
    }
    WP_CLI::success('new atomic state verified');
    return;
}

if ($mode === 'verify-exact-new') {
    if (strpos((string) file_get_contents($main), '2.0.0') === false || ! is_file($new) || is_file($old)) {
        $fail('owned code deletion was not committed with the directory swap');
    }
    WP_CLI::success('new atomic state and owned deletion verified');
    return;
}

if ($mode === 'rollback') {
    $snapshot = Fsync_Snapshot::all(1);
    if ($snapshot === array()) {
        $fail('snapshot missing');
    }
    $result = Fsync_Snapshot::restore((string) $snapshot[0]['snapshot_id']);
    if (is_wp_error($result)) {
        $fail($result->get_error_code() . ': ' . $result->get_error_message());
    }
    WP_CLI::success('snapshot restored');
    return;
}

if ($mode === 'verify-old') {
    if (strpos((string) file_get_contents($main), '1.0.0') === false || ! is_file($old) || is_file($new)) {
        $fail('atomic fixture did not return to the target state');
    }
    WP_CLI::success('old atomic state verified');
    return;
}

if ($mode === 'verify-removed') {
    if (is_dir($root)) {
        $fail('new code group was not removed by rollback');
    }
    WP_CLI::success('new code group removal verified');
    return;
}

if ($mode === 'cleanup') {
    if (! function_exists('deactivate_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    deactivate_plugins('fsync-atomic-fixture/fsync-atomic-fixture.php', true);
    if (get_option('fsync_atomic_fixture_original_config_saved', false)) {
        update_option(Fsync_Config_Io::OPTION_DOCUMENT, (array) get_option('fsync_atomic_fixture_original_config', array()), false);
    }
    delete_option('fsync_atomic_fixture_original_config');
    delete_option('fsync_atomic_fixture_original_config_saved');
    Fsync_Config::flush();
    if (! $remove_tree($root)) {
        $fail('fixture directory could not be removed');
    }
    WP_CLI::success('fixture cleaned');
    return;
}

$fail('unknown fixture mode');
