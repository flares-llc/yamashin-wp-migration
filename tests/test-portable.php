<?php

if (! defined('ABSPATH')) {
    exit;
}

T::group('Fsync_Portable natural identities');

$post = array(
    'format_version' => 1,
    'kind' => 'post',
    'uid' => '0cb9aa9a-3898-495e-8a42-82f5c7d15798',
    'data' => array('post_type' => 'post', 'slug' => 'portable-post'),
    'objects' => array(),
);
$same_post = $post;
$same_post['uid'] = '6fa3867d-07b7-4c3a-88ad-31ab8dd6a390';
T::same(Fsync_Portable::identity_key($post), Fsync_Portable::identity_key($same_post), 'post natural identity ignores site-specific UID');

$different_type = $same_post;
$different_type['data']['post_type'] = 'page';
T::ok(Fsync_Portable::identity_key($post) !== Fsync_Portable::identity_key($different_type), 'post type participates in natural identity');

$comment = array('format_version' => 1, 'kind' => 'comment', 'uid' => '0cb9aa9a-3898-495e-8a42-82f5c7d15798', 'data' => array(), 'objects' => array());
T::same('', Fsync_Portable::identity_key($comment), 'comments are never guessed by mutable natural fields');

$unsafe_content = 'before {{FSYNC_REF:post:0cb9aa9a-3898-495e-8a42-82f5c7d15798}} after';
T::ok(Fsync_Portable::has_relationships(array_merge($post, array('data' => array_merge($post['data'], array('content' => $unsafe_content))))), 'portable content reference schedules a relationship pass');
