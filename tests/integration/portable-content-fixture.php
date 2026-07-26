<?php

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

$mode = (string) ($args[0] ?? 'seed');
$slug = 'fsync-reference-fixture';

if ($mode === 'seed') {
    $attachment_id = wp_insert_post(
        array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => 'FSYNC reference image',
            'post_name' => 'fsync-reference-image',
            'post_mime_type' => 'image/png',
        ),
        true
    );
    if (is_wp_error($attachment_id)) {
        WP_CLI::error($attachment_id->get_error_message());
    }
    $content = '<!-- wp:image {"id":' . (int) $attachment_id . '} -->'
        . '<figure class="wp-block-image wp-image-' . (int) $attachment_id . '"><img src="' . esc_url(home_url('/fixture.png')) . '" /></figure>'
        . '<!-- /wp:image -->';
    $post_id = wp_insert_post(
        array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => 'FSYNC reference fixture',
            'post_name' => $slug,
            'post_content' => $content,
        ),
        true
    );
    if (is_wp_error($post_id)) {
        WP_CLI::error($post_id->get_error_message());
    }
    $post_uid = Fsync_Identity::uid('post', (int) $post_id);
    $attachment_uid = Fsync_Identity::uid('post', (int) $attachment_id);
    if (is_wp_error($post_uid) || is_wp_error($attachment_uid)) {
        WP_CLI::error('Could not create fixture UIDs.');
    }
    WP_CLI::line(wp_json_encode(array('post_uid' => $post_uid, 'attachment_uid' => $attachment_uid)));
    return;
}

if ($mode === 'verify') {
    $post = get_page_by_path($slug, OBJECT, 'post');
    if (! $post) {
        WP_CLI::error('Migrated reference post is missing.');
    }
    $blocks = parse_blocks((string) $post->post_content);
    $attachment_id = (int) ($blocks[0]['attrs']['id'] ?? 0);
    if ($attachment_id <= 0 || ! get_post($attachment_id)) {
        WP_CLI::error('Gutenberg attachment ID was not hydrated.');
    }
    if (strpos((string) $post->post_content, '{{FSYNC_REF:') !== false
        || strpos((string) $post->post_content, 'wp-image-' . $attachment_id) === false
        || strpos((string) $post->post_content, untrailingslashit(home_url('/')) . '/fixture.png') === false) {
        WP_CLI::error('Content reference or URL hydration is incomplete.');
    }
    WP_CLI::line(wp_json_encode(array('post_id' => (int) $post->ID, 'attachment_id' => $attachment_id)));
    return;
}

if ($mode === 'cleanup') {
    $post = get_page_by_path($slug, OBJECT, 'post');
    if ($post) {
        $blocks = parse_blocks((string) $post->post_content);
        $attachment_id = (int) ($blocks[0]['attrs']['id'] ?? 0);
        wp_delete_post((int) $post->ID, true);
        if ($attachment_id > 0) {
            wp_delete_post($attachment_id, true);
        }
    }
    WP_CLI::success('Reference fixture removed.');
    return;
}

WP_CLI::error('Unknown mode.');
