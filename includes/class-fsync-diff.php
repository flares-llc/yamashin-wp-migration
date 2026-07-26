<?php

if (! defined('ABSPATH')) {
    exit;
}

/** Pure three-way comparison used by REST, MCP, admin and unit tests. */
final class Fsync_Diff
{
    const ACTION_CREATE = 'create';
    const ACTION_UPDATE = 'update';
    const ACTION_DELETE = 'delete';
    const ACTION_UNCHANGED = 'unchanged';
    const ACTION_CONFLICT = 'conflict';
    const ACTION_BLOCKED = 'blocked';

    /**
     * @param array $source Manifest items keyed by portable key.
     * @param array $target
     * @param array $base Hash map from the last verified receipt.
     * @param bool $allow_delete
     * @return array
     */
    public static function compare(array $source, array $target, array $base = array(), $allow_delete = false)
    {
        $keys = array_values(array_unique(array_merge(array_keys($source), array_keys($target), array_keys($base))));
        sort($keys, SORT_STRING);
        $items = array();
        $counts = array_fill_keys(
            array(self::ACTION_CREATE, self::ACTION_UPDATE, self::ACTION_DELETE, self::ACTION_UNCHANGED, self::ACTION_CONFLICT, self::ACTION_BLOCKED),
            0
        );

        foreach ($keys as $key) {
            $source_item = isset($source[$key]) ? (array) $source[$key] : null;
            $target_item = isset($target[$key]) ? (array) $target[$key] : null;
            $source_hash = $source_item === null ? '' : (string) ($source_item['hash'] ?? '');
            $target_hash = $target_item === null ? '' : (string) ($target_item['hash'] ?? '');
            $base_hash = is_array($base[$key] ?? null) ? (string) ($base[$key]['hash'] ?? '') : (string) ($base[$key] ?? '');

            if ($source_hash !== '' && $target_hash === '') {
                // When the item existed at the common base, its absence on the
                // target is a target-side change. Recreating it silently would
                // overwrite an intentional deletion, so require an explicit
                // conflict decision even when the source itself is unchanged.
                $action = $base_hash !== '' ? self::ACTION_CONFLICT : self::ACTION_CREATE;
            } elseif ($source_hash === '' && $target_hash !== '') {
                if ($base_hash === '') {
                    $action = self::ACTION_UNCHANGED; // target-only content is not implicitly owned by this peer.
                } elseif ($target_hash !== $base_hash) {
                    $action = self::ACTION_CONFLICT;
                } else {
                    $action = $allow_delete ? self::ACTION_DELETE : self::ACTION_BLOCKED;
                }
            } elseif ($source_hash === $target_hash) {
                $action = self::ACTION_UNCHANGED;
            } elseif ($base_hash === '') {
                $action = self::ACTION_CONFLICT;
            } else {
                $source_changed = $source_hash !== $base_hash;
                $target_changed = $target_hash !== $base_hash;
                if ($source_changed && $target_changed) {
                    $action = self::ACTION_CONFLICT;
                } elseif ($source_changed) {
                    $action = self::ACTION_UPDATE;
                } else {
                    $action = self::ACTION_UNCHANGED;
                }
            }

            $template = $source_item !== null ? $source_item : (array) $target_item;
            $items[$key] = array(
                'key' => $key,
                'kind' => (string) ($template['kind'] ?? ''),
                'uid' => (string) ($template['uid'] ?? ''),
                'action' => $action,
                'source_hash' => $source_hash,
                'target_hash' => $target_hash,
                'base_hash' => $base_hash,
                'payload_hash' => $source_item === null ? '' : (string) ($source_item['payload_hash'] ?? ''),
            );
            $counts[$action]++;
        }

        return array('items' => $items, 'counts' => $counts);
    }
}
