<?php

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

$mode = (string) ($args[0] ?? 'seed');
$role = (string) ($args[1] ?? 'source');
$slug = 'fsync-natural-adoption';

if ($mode === 'cleanup') {
    $post = get_page_by_path($slug, OBJECT, 'post');
    if ($post) {
        wp_delete_post((int) $post->ID, true);
    }
    WP_CLI::success('Natural identity fixture removed.');
    return;
}

if ($mode === 'seed') {
    $post = get_page_by_path($slug, OBJECT, 'post');
    if ($post) {
        wp_delete_post((int) $post->ID, true);
    }
    $id = wp_insert_post(
        array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_name' => $slug,
            'post_title' => $role === 'source' ? 'Natural source' : 'Natural target',
            'post_content' => $role === 'source' ? 'source-value' : 'target-before-value',
        ),
        true
    );
    if (is_wp_error($id)) {
        WP_CLI::error($id->get_error_message());
    }
    $uid = Fsync_Identity::uid('post', (int) $id);
    if (is_wp_error($uid)) {
        WP_CLI::error($uid->get_error_message());
    }
    WP_CLI::line(wp_json_encode(array('id' => (int) $id, 'uid' => $uid, 'role' => $role)));
    return;
}

$post = get_page_by_path($slug, OBJECT, 'post');
if (! $post) {
    WP_CLI::error('Natural identity fixture is missing.');
}
$uid = (string) get_post_meta((int) $post->ID, Fsync_Identity::META_KEY, true);
if ($mode === 'verify-source') {
    if ((string) $post->post_content !== 'source-value') {
        WP_CLI::error('Source conflict decision was not applied.');
    }
} elseif ($mode === 'verify-restored') {
    if ((string) $post->post_content !== 'target-before-value') {
        WP_CLI::error('Natural identity rollback did not restore the target row.');
    }
} else {
    WP_CLI::error('Unknown mode.');
}
WP_CLI::line(wp_json_encode(array('id' => (int) $post->ID, 'uid' => $uid, 'content' => (string) $post->post_content)));
