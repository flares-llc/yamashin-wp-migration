<?php

if (! defined('ABSPATH')) {
    exit;
}

/** Application-level snapshots for every row and file touched by a release. */
final class Fsync_Snapshot
{
    /** @return array|WP_Error */
    public static function create(array $release)
    {
        global $wpdb;

        $target_manifest_id = (string) ($release['summary']['target_manifest_id'] ?? '');
        $target = Fsync_Manifest::get($target_manifest_id);
        if (is_wp_error($target)) {
            return $target;
        }
        $snapshot_id = Fsync_Utils::random_hex(16);
        if (is_wp_error($snapshot_id)) {
            return $snapshot_id;
        }

        $items = array();
        foreach (Fsync_Release::items($release['release_id']) as $item) {
            if (! self::will_mutate($item)) {
                continue;
            }
            $target_key = (string) (($item['target_item_key'] ?? '') ?: $item['item_key']);
            $target_item = $target['items'][$target_key] ?? null;
            $delete_record = array();
            if (! is_array($target_item) && (string) ($item['payload_hash'] ?? '') !== '') {
                $raw = Fsync_Store::get((string) $item['payload_hash']);
                $decoded = is_wp_error($raw) ? $raw : Fsync_Utils::decode($raw);
                if (is_wp_error($decoded)) {
                    return $decoded;
                }
                // A create rollback must not depend on the same object that
                // may have caused apply to fail. Keep the authenticated
                // portable record inside the independently hashed snapshot.
                $delete_record = $decoded;
            }
            $items[$item['item_key']] = array(
                'kind' => $item['entity_kind'],
                'uid' => $item['entity_uid'],
                'exists' => is_array($target_item),
                'payload_hash' => is_array($target_item) ? (string) $target_item['payload_hash'] : '',
                'objects' => is_array($target_item) ? (array) $target_item['objects'] : array(),
                'delete_payload_hash' => is_array($target_item) ? '' : (string) ($item['payload_hash'] ?? ''),
                'delete_record' => $delete_record,
            );
        }
        $snapshot = array(
            'format_version' => 1,
            'snapshot_id' => $snapshot_id,
            'release_id' => $release['release_id'],
            'plan_hash' => $release['plan_hash'],
            'items' => $items,
            'runtime' => array(
                'active_plugins' => (array) get_option('active_plugins', array()),
                'stylesheet' => (string) get_option('stylesheet', ''),
                'template' => (string) get_option('template', ''),
            ),
            'created_at' => Fsync_Utils::now_iso(),
        );
        $hash = Fsync_Utils::canonical_hash($snapshot);
        if (is_wp_error($hash)) {
            return $hash;
        }
        $path = Fsync_Fs::private_dir('snapshots/' . $snapshot_id . '.json');
        $written = Fsync_Fs::write_json($path, $snapshot);
        if (is_wp_error($written)) {
            return $written;
        }
        $keep_days = max(1, (int) (Fsync_Config::document()['backup']['retention']['safety_backup_days'] ?? 7));
        $saved = $wpdb->insert(
            Fsync_Schema::table('snapshots'),
            array(
                'snapshot_id' => $snapshot_id,
                'release_id' => $release['release_id'],
                'status' => 'ready',
                'manifest_hash' => $hash,
                'path' => 'snapshots/' . $snapshot_id . '.json',
                'size_bytes' => (int) filesize($path),
                'created_at' => Fsync_Utils::now(),
                'expires_at' => Fsync_Utils::now() + ($keep_days * DAY_IN_SECONDS),
                'restored_at' => 0,
            )
        );
        if ($saved === false) {
            @unlink($path);

            return new WP_Error('fsync_snapshot_save_failed', 'スナップショットを保存できません。');
        }

        return array('snapshot_id' => $snapshot_id, 'manifest_hash' => $hash, 'items' => count($items));
    }

    /** @return array|WP_Error */
    public static function restore($snapshot_id)
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT release_id, status, expires_at FROM ' . Fsync_Schema::table('snapshots') . ' WHERE snapshot_id = %s',
                $snapshot_id
            ),
            ARRAY_A
        );
        if ($row === null) {
            return new WP_Error('fsync_snapshot_missing', 'スナップショットが見つかりません。', array('status' => 404));
        }
        if ((string) $row['status'] === 'restored') {
            return array('snapshot_id' => $snapshot_id, 'release_id' => (string) $row['release_id'], 'restored' => true, 'idempotent' => true);
        }
        $expires_at = (int) $row['expires_at'];
        if ($expires_at > 0 && $expires_at < Fsync_Utils::now()) {
            return new WP_Error('fsync_snapshot_expired', 'このスナップショットの保持期間は終了しました。', array('status' => 410));
        }

        $snapshot = self::get($snapshot_id);
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }
        $release = Fsync_Release::get($snapshot['release_id']);
        if (is_wp_error($release)) {
            return $release;
        }
        // A plugin/theme/mu-plugin directory is restored as one rename before
        // row and individual-file compensation. This also removes code groups
        // that did not exist before the release.
        $code_restored = Fsync_Apply::restore_code_backups($release['release_id']);
        if (is_wp_error($code_restored)) {
            return $code_restored;
        }

        $records = array();
        $missing = array();
        foreach ((array) $snapshot['items'] as $key => $item) {
            if (empty($item['exists'])) {
                $missing[$key] = $item;
                continue;
            }
            $raw = Fsync_Store::get((string) $item['payload_hash']);
            if (is_wp_error($raw)) {
                return $raw;
            }
            $record = Fsync_Utils::decode($raw);
            if (is_wp_error($record)) {
                return $record;
            }
            $records[$key] = $record;
        }

        $restored = Fsync_Apply::apply_records($records, true);
        if (is_wp_error($restored)) {
            return $restored;
        }
        foreach ($missing as $key => $item) {
            $record = (array) ($item['delete_record'] ?? array());
            if ($record === array() && ! empty($item['delete_payload_hash'])) {
                $raw = Fsync_Store::get((string) $item['delete_payload_hash']);
                if (is_wp_error($raw)) {
                    return $raw;
                }
                $record = Fsync_Utils::decode($raw);
                if (is_wp_error($record)) {
                    return $record;
                }
            }
            if ($record !== array()) {
                $record['data'] = Fsync_Portable::hydrate_value((array) ($record['data'] ?? array()));
            }
            $deleted = Fsync_Apply::delete_identity((string) $item['kind'], (string) $item['uid'], (string) $key, $record);
            if (is_wp_error($deleted)) {
                return $deleted;
            }
        }

        update_option('active_plugins', array_values((array) $snapshot['runtime']['active_plugins']), false);
        update_option('stylesheet', (string) $snapshot['runtime']['stylesheet'], false);
        update_option('template', (string) $snapshot['runtime']['template'], false);

        $wpdb->update(
            Fsync_Schema::table('snapshots'),
            array('status' => 'restored', 'restored_at' => Fsync_Utils::now()),
            array('snapshot_id' => $snapshot_id)
        );
        Fsync_Release::set_status($release['release_id'], Fsync_Release::STATUS_ROLLED_BACK, array('restored_snapshot_id' => $snapshot_id));
        Fsync_Apply::cleanup_code_state($release['release_id']);
        Fsync_Log::warning('snapshot_restored', '移行スナップショットを復元しました。', array('data' => array('snapshot_id' => $snapshot_id)));

        return array('snapshot_id' => $snapshot_id, 'release_id' => $release['release_id'], 'restored' => true);
    }

    /** @return array|WP_Error */
    public static function get($snapshot_id)
    {
        global $wpdb;

        if (! Fsync_Utils::is_public_id($snapshot_id)) {
            return new WP_Error('fsync_snapshot_id_invalid', 'スナップショットIDが不正です。');
        }
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . Fsync_Schema::table('snapshots') . ' WHERE snapshot_id = %s', $snapshot_id),
            ARRAY_A
        );
        if ($row === null) {
            return new WP_Error('fsync_snapshot_missing', 'スナップショットが見つかりません。', array('status' => 404));
        }
        $path = Fsync_Fs::resolve(Fsync_Fs::private_dir(), (string) $row['path']);
        if (is_wp_error($path)) {
            return $path;
        }
        $snapshot = Fsync_Fs::read_json($path);
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }
        $hash = Fsync_Utils::canonical_hash($snapshot);
        if (is_wp_error($hash) || ! hash_equals((string) $row['manifest_hash'], (string) $hash)) {
            return new WP_Error('fsync_snapshot_corrupt', 'スナップショットが改変または破損しています。');
        }

        return $snapshot;
    }

    /** Require the exact immutable plan before an operator-initiated restore. */
    public static function authorize_rollback($snapshot_id, $plan_hash)
    {
        $snapshot = self::get((string) $snapshot_id);
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }
        if (! Fsync_Utils::is_sha256((string) $plan_hash)
            || ! hash_equals((string) ($snapshot['plan_hash'] ?? ''), (string) $plan_hash)) {
            return new WP_Error('fsync_rollback_plan_mismatch', 'ロールバック対象のplan_hashが一致しません。', array('status' => 409));
        }

        return true;
    }

    public static function all($limit = 50)
    {
        global $wpdb;

        return (array) $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . Fsync_Schema::table('snapshots') . ' ORDER BY created_at DESC LIMIT %d', max(1, min(200, (int) $limit))),
            ARRAY_A
        );
    }

    /** Delete expired snapshots and their retained atomic code backups. */
    public static function purge_expired($limit = 20)
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT snapshot_id, release_id, path FROM ' . Fsync_Schema::table('snapshots') . ' WHERE expires_at > 0 AND expires_at < %d ORDER BY expires_at ASC LIMIT %d',
                Fsync_Utils::now(),
                max(1, min(100, (int) $limit))
            ),
            ARRAY_A
        );
        $purged = 0;
        foreach ((array) $rows as $row) {
            $release = Fsync_Release::get((string) $row['release_id']);
            if (! is_wp_error($release) && $release['status'] === Fsync_Release::STATUS_APPLYING) {
                continue;
            }
            $path = Fsync_Fs::resolve(Fsync_Fs::private_dir(), (string) $row['path']);
            if (! is_wp_error($path) && is_file($path)) {
                @unlink($path);
            }
            Fsync_Apply::cleanup_code_state((string) $row['release_id']);
            $deleted = $wpdb->delete(Fsync_Schema::table('snapshots'), array('snapshot_id' => (string) $row['snapshot_id']));
            if ($deleted !== false) {
                $purged++;
            }
        }

        return $purged;
    }

    private static function will_mutate(array $item)
    {
        if (in_array($item['action'], array(Fsync_Diff::ACTION_CREATE, Fsync_Diff::ACTION_UPDATE, Fsync_Diff::ACTION_DELETE), true)) {
            return true;
        }

        return $item['action'] === Fsync_Diff::ACTION_CONFLICT && $item['resolution'] === 'source';
    }
}
