<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * @param string $hash
 * @return array
 */
function fsync_diff_item($hash)
{
    return array(
        'hash' => $hash,
        'payload_hash' => $hash,
        'kind' => 'post',
        'uid' => 'f75fa3bf-62e8-4de0-abeb-23010904b78a',
    );
}

T::group('Fsync_Diff three-way comparison');

$created = Fsync_Diff::compare(array('post:a' => fsync_diff_item('source')), array());
T::same('create', $created['items']['post:a']['action'], 'a new source item is created');

$target_only = Fsync_Diff::compare(array(), array('post:a' => fsync_diff_item('target')));
T::same('unchanged', $target_only['items']['post:a']['action'], 'unowned target-only content is preserved');

$updated = Fsync_Diff::compare(
    array('post:a' => fsync_diff_item('source-new')),
    array('post:a' => fsync_diff_item('base')),
    array('post:a' => 'base')
);
T::same('update', $updated['items']['post:a']['action'], 'a source-only change is updated');

$target_changed = Fsync_Diff::compare(
    array('post:a' => fsync_diff_item('base')),
    array('post:a' => fsync_diff_item('target-new')),
    array('post:a' => 'base')
);
T::same('unchanged', $target_changed['items']['post:a']['action'], 'a target-only edit is preserved');

$both_changed = Fsync_Diff::compare(
    array('post:a' => fsync_diff_item('source-new')),
    array('post:a' => fsync_diff_item('target-new')),
    array('post:a' => 'base')
);
T::same('conflict', $both_changed['items']['post:a']['action'], 'divergent edits conflict');

$target_deleted = Fsync_Diff::compare(
    array('post:a' => fsync_diff_item('base')),
    array(),
    array('post:a' => 'base')
);
T::same('conflict', $target_deleted['items']['post:a']['action'], 'an intentional target deletion conflicts');

$delete_blocked = Fsync_Diff::compare(
    array(),
    array('post:a' => fsync_diff_item('base')),
    array('post:a' => 'base'),
    false
);
T::same('blocked', $delete_blocked['items']['post:a']['action'], 'source deletion is blocked by default');

$delete_allowed = Fsync_Diff::compare(
    array(),
    array('post:a' => fsync_diff_item('base')),
    array('post:a' => 'base'),
    true
);
T::same('delete', $delete_allowed['items']['post:a']['action'], 'source deletion requires an enabled policy');
