<?php

if (! defined('ABSPATH')) {
    exit;
}

/** Stable portable identities independent of site-specific WordPress ids. */
final class Fsync_Identity
{
    const META_KEY = '_fsync_uid';

    /** @var array<string, array<int, string>> */
    private static $cache = array();

    /** @var array<string, array<string, int>> Detect cloned/duplicated UID metadata. */
    private static $uid_owners = array();

    /**
     * @param string $kind post|term|comment|user
     * @param int $local_id
     * @param bool $create
     * @return string|WP_Error
     */
    public static function uid($kind, $local_id, $create = true)
    {
        $kind = (string) $kind;
        $local_id = (int) $local_id;
        if ($local_id <= 0 || ! in_array($kind, array('post', 'term', 'comment', 'user'), true)) {
            return new WP_Error('fsync_identity_invalid', 'エンティティ種別またはIDが不正です。');
        }
        if (isset(self::$cache[$kind][$local_id])) {
            return self::$cache[$kind][$local_id];
        }

        $uid = self::read_meta($kind, $local_id);
        if (self::valid_uid($uid)) {
            if (! self::remember($kind, $uid, $local_id)) {
                return new WP_Error('fsync_identity_duplicate', sprintf('可搬UIDが別の%sに既に割り当てられています: %s', $kind, $uid));
            }

            return $uid;
        }
        if (! $create) {
            return new WP_Error('fsync_identity_missing', 'エンティティに可搬UIDがありません。');
        }

        $uid = Fsync_Utils::uuid4();
        if (is_wp_error($uid)) {
            return $uid;
        }
        if (! self::write_meta($kind, $local_id, $uid)) {
            return new WP_Error('fsync_identity_write_failed', '可搬UIDを保存できません。');
        }

        if (! self::remember($kind, $uid, $local_id)) {
            return new WP_Error('fsync_identity_write_failed', '新しい可搬UIDの対応表を保存できません。');
        }

        return $uid;
    }

    /** Prime portable UIDs and entity mappings in bounded bulk queries. */
    public static function prime($kind, array $local_ids)
    {
        global $wpdb;

        $kind = (string) $kind;
        $ids = array_values(array_unique(array_filter(array_map('intval', $local_ids), static function ($id) {
            return $id > 0;
        })));
        if ($ids === array()) {
            return true;
        }
        $meta = self::meta_storage($kind);
        if (is_wp_error($meta)) {
            return $meta;
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $meta_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT {$meta['id']} AS local_id, meta_value AS uid FROM {$meta['table']} WHERE meta_key = %s AND {$meta['id']} IN ({$placeholders}) ORDER BY {$meta['meta_id']} ASC",
                    array_merge(array(self::META_KEY), $chunk)
                ),
                ARRAY_A
            );
            $uids = array();
            foreach ((array) $meta_rows as $row) {
                $id = (int) $row['local_id'];
                $candidate = (string) $row['uid'];
                if (! self::valid_uid($candidate)) {
                    continue;
                }
                if (isset($uids[$id]) && ! hash_equals((string) $uids[$id], $candidate)) {
                    return new WP_Error('fsync_identity_duplicate_meta', sprintf('同じ%sに異なる可搬UIDが複数保存されています: %d', $kind, $id));
                }
                $uids[$id] = $candidate;
            }
            $entity_rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT local_id, entity_uid FROM ' . Fsync_Schema::table('entities') . " WHERE entity_kind = %s AND local_id IN ({$placeholders})",
                    array_merge(array($kind), $chunk)
                ),
                ARRAY_A
            );
            foreach ((array) $entity_rows as $row) {
                $id = (int) $row['local_id'];
                if (! isset($uids[$id]) && self::valid_uid((string) $row['entity_uid'])) {
                    $uids[$id] = (string) $row['entity_uid'];
                }
            }
            $new_meta = array();
            foreach ($chunk as $id) {
                if (! isset($uids[$id])) {
                    $uid = Fsync_Utils::uuid4();
                    if (is_wp_error($uid)) {
                        return $uid;
                    }
                    $uids[$id] = $uid;
                    $new_meta[] = $wpdb->prepare('(%d,%s,%s)', $id, self::META_KEY, $uid);
                }
            }
            foreach ($uids as $id => $uid) {
                $owner = (int) (self::$uid_owners[$kind][$uid] ?? 0);
                if ($owner > 0 && $owner !== (int) $id) {
                    return new WP_Error(
                        'fsync_identity_duplicate',
                        sprintf('同じ可搬UIDが複数の%sに付与されています: %s', $kind, $uid)
                    );
                }
                self::$uid_owners[$kind][$uid] = (int) $id;
            }
            if ($new_meta !== array()) {
                $inserted = $wpdb->query(
                    "INSERT INTO {$meta['table']} ({$meta['id']}, meta_key, meta_value) VALUES " . implode(',', $new_meta)
                );
                if ($inserted === false) {
                    return new WP_Error('fsync_identity_write_failed', '可搬UIDを一括保存できません。');
                }
            }
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM ' . Fsync_Schema::table('entities') . " WHERE entity_kind = %s AND local_id IN ({$placeholders})",
                    array_merge(array($kind), $chunk)
                )
            );
            if ($deleted === false) {
                return new WP_Error('fsync_identity_write_failed', 'UID対応表を整理できません。');
            }
            $entity_values = array();
            $now = Fsync_Utils::now();
            foreach ($uids as $id => $uid) {
                $entity_values[] = $wpdb->prepare('(%s,%s,%d,%d)', $kind, $uid, $id, $now);
                self::$cache[$kind][$id] = $uid;
            }
            if ($entity_values !== array()) {
                $inserted = $wpdb->query(
                    'INSERT INTO ' . Fsync_Schema::table('entities')
                    . ' (entity_kind, entity_uid, local_id, updated_at) VALUES ' . implode(',', $entity_values)
                    . ' ON DUPLICATE KEY UPDATE local_id = VALUES(local_id), updated_at = VALUES(updated_at)'
                );
                if ($inserted === false) {
                    return new WP_Error('fsync_identity_write_failed', 'UID対応表を一括保存できません。');
                }
            }
        }

        return true;
    }

    /**
     * @param string $kind
     * @param string $uid
     * @return int
     */
    public static function local_id($kind, $uid)
    {
        global $wpdb;

        if (! self::valid_uid($uid)) {
            return 0;
        }

        $found = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT local_id FROM ' . Fsync_Schema::table('entities') . ' WHERE entity_kind = %s AND entity_uid = %s',
                (string) $kind,
                (string) $uid
            )
        );

        return (int) $found;
    }

    /**
     * @param string $kind
     * @param string $uid
     * @param int $local_id
     * @param string $identity_key
     * @param string $hash
     * @return bool
     */
    public static function remember($kind, $uid, $local_id, $identity_key = '', $hash = '')
    {
        global $wpdb;

        if (! self::valid_uid($uid) || (int) $local_id < 0) {
            return false;
        }

        $kind = (string) $kind;
        $uid = (string) $uid;
        $local_id = (int) $local_id;
        $mapped_local_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT local_id FROM ' . Fsync_Schema::table('entities') . ' WHERE entity_kind = %s AND entity_uid = %s',
                $kind,
                $uid
            )
        );
        if ($mapped_local_id > 0 && $local_id > 0 && $mapped_local_id !== $local_id) {
            return false;
        }
        $owner = (int) (self::$uid_owners[$kind][$uid] ?? 0);
        if ($owner > 0 && $local_id > 0 && $owner !== $local_id) {
            return false;
        }
        if ($local_id > 0) {
            self::$cache[$kind][$local_id] = $uid;
            self::$uid_owners[$kind][$uid] = $local_id;
        }

        // One local row must never resolve through two portable identities.
        // This also lets a first migration adopt independently-created target
        // content by its natural key and replace the bootstrap UID safely.
        if ($local_id > 0) {
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM ' . Fsync_Schema::table('entities') . ' WHERE entity_kind = %s AND local_id = %d AND entity_uid <> %s',
                    $kind,
                    $local_id,
                    $uid
                )
            );
        }

        $data = array(
            'local_id' => $local_id,
            'updated_at' => Fsync_Utils::now(),
        );
        if ((string) $identity_key !== '') {
            $data['identity_key'] = (string) $identity_key;
        }
        if (Fsync_Utils::is_sha256($hash)) {
            $data['current_hash'] = $hash;
        }

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT entity_uid FROM ' . Fsync_Schema::table('entities') . ' WHERE entity_kind = %s AND entity_uid = %s',
                $kind,
                $uid
            )
        );
        $result = $exists
            ? $wpdb->update(Fsync_Schema::table('entities'), $data, array('entity_kind' => $kind, 'entity_uid' => $uid))
            : $wpdb->insert(
                Fsync_Schema::table('entities'),
                array_merge(
                    array('entity_kind' => $kind, 'entity_uid' => $uid, 'identity_key' => '', 'current_hash' => ''),
                    $data
                )
            );

        return $result !== false;
    }

    /**
     * @param string $uid
     * @return bool
     */
    public static function valid_uid($uid)
    {
        return is_string($uid)
            && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $uid) === 1;
    }

    public static function forget($kind, $uid)
    {
        global $wpdb;

        if (! self::valid_uid($uid)) {
            return false;
        }

        return $wpdb->delete(
            Fsync_Schema::table('entities'),
            array('entity_kind' => (string) $kind, 'entity_uid' => (string) $uid)
        ) !== false;
    }

    private static function read_meta($kind, $id)
    {
        if ($kind === 'post') {
            return (string) get_post_meta($id, self::META_KEY, true);
        }
        if ($kind === 'term') {
            return (string) get_term_meta($id, self::META_KEY, true);
        }
        if ($kind === 'comment') {
            return (string) get_comment_meta($id, self::META_KEY, true);
        }

        return (string) get_user_meta($id, self::META_KEY, true);
    }

    private static function write_meta($kind, $id, $uid)
    {
        if ($kind === 'post') {
            return update_post_meta($id, self::META_KEY, $uid) !== false;
        }
        if ($kind === 'term') {
            return update_term_meta($id, self::META_KEY, $uid) !== false;
        }
        if ($kind === 'comment') {
            return update_comment_meta($id, self::META_KEY, $uid) !== false;
        }

        return update_user_meta($id, self::META_KEY, $uid) !== false;
    }

    private static function meta_storage($kind)
    {
        global $wpdb;
        if ($kind === 'post') {
            return array('table' => $wpdb->postmeta, 'id' => 'post_id', 'meta_id' => 'meta_id');
        }
        if ($kind === 'term') {
            return array('table' => $wpdb->termmeta, 'id' => 'term_id', 'meta_id' => 'meta_id');
        }
        if ($kind === 'comment') {
            return array('table' => $wpdb->commentmeta, 'id' => 'comment_id', 'meta_id' => 'meta_id');
        }
        if ($kind === 'user') {
            return array('table' => $wpdb->usermeta, 'id' => 'user_id', 'meta_id' => 'umeta_id');
        }

        return new WP_Error('fsync_identity_invalid', 'エンティティ種別が不正です。');
    }
}
