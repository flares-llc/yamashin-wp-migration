<?php

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

global $wpdb;
$mode = (string) ($args[0] ?? 'install');
$role = (string) ($args[1] ?? 'target');
$name = 'fsync_fixture';
$table = $wpdb->prefix . $name;
$uid = '54d980c0-e71c-4d31-8c1a-7f25a94e9511';
$config = array(
    'name' => $name,
    'primary_key' => 'id',
    'uid_column' => 'portable_uid',
    'natural_key' => array('natural_code'),
    'refs' => array('post_id' => 'post'),
    'portable' => array('endpoint' => 'url', 'payload' => 'serialized'),
    'delete' => true,
);

$save_scope = static function ($enabled) use ($config, $name) {
    $document = get_option('fsync_config');
    if (! is_array($document)) {
        WP_CLI::error('Stored test configuration is missing.');
    }
    $tables = array_values((array) ($document['sync']['scope']['tables'] ?? array()));
    $tables = array_values(array_filter($tables, static function ($entry) use ($name) {
        return (string) ($entry['name'] ?? '') !== $name;
    }));
    if ($enabled) {
        $tables[] = $config;
    }
    $document['sync']['scope']['tables'] = $tables;
    update_option('fsync_config', $document, false);
    Fsync_Config::flush();
};

if ($mode === 'install') {
    $created = $wpdb->query(
        "CREATE TABLE IF NOT EXISTS `{$table}` (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            portable_uid char(36) NOT NULL,
            natural_code varchar(100) NOT NULL,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            endpoint longtext NOT NULL,
            payload longtext NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY portable_uid (portable_uid),
            UNIQUE KEY natural_code (natural_code)
        ) " . $wpdb->get_charset_collate()
    );
    if ($created === false) {
        WP_CLI::error($wpdb->last_error);
    }
    $wpdb->query("TRUNCATE TABLE `{$table}`");
    $save_scope(true);
    if ($role === 'source') {
        $post = get_page_by_path('fsync-reference-fixture', OBJECT, 'post');
        if (! $post) {
            WP_CLI::error('Reference fixture post is required.');
        }
        $inserted = $wpdb->insert(
            $table,
            array(
                'portable_uid' => $uid,
                'natural_code' => 'alpha',
                'post_id' => (int) $post->ID,
                'endpoint' => home_url('/api/alpha'),
                'payload' => maybe_serialize(array('url' => home_url('/asset/alpha'))),
            )
        );
        if ($inserted === false) {
            WP_CLI::error($wpdb->last_error);
        }
    }
    WP_CLI::success('Custom table fixture installed.');
    return;
}

if ($mode === 'verify') {
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE portable_uid = %s", $uid), ARRAY_A);
    $post = get_page_by_path('fsync-reference-fixture', OBJECT, 'post');
    $payload = is_array($row) ? maybe_unserialize($row['payload']) : null;
    if (! is_array($row) || ! $post
        || (int) $row['post_id'] !== (int) $post->ID
        || (string) $row['endpoint'] !== home_url('/api/alpha')
        || ! is_array($payload)
        || (string) ($payload['url'] ?? '') !== home_url('/asset/alpha')) {
        WP_CLI::error('Custom table row was not portably applied.');
    }
    WP_CLI::line(wp_json_encode(array('id' => (int) $row['id'], 'post_id' => (int) $row['post_id'])));
    return;
}

if ($mode === 'cleanup') {
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
    $save_scope(false);
    WP_CLI::success('Custom table fixture removed.');
    return;
}

WP_CLI::error('Unknown mode.');
