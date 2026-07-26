<?php

if (! defined('ABSPATH')) {
    exit;
}

/** Apply portable records with compensating snapshot rollback. */
final class Fsync_Apply
{
    const LOCK_PREFIX = 'fsync_apply_';
    const BATCH_SIZE = 100;

    /** Execute one resumable apply batch for a durable job. */
    public static function step(array $job, $confirmation)
    {
        $release = Fsync_Release::get((string) $job['release_id']);
        if (is_wp_error($release)) {
            return $release;
        }
        $payload = (array) $job['payload'];
        $stage = (string) ($payload['stage'] ?? 'apply');
        $locked = self::durable_lock($release['release_id'], (string) $job['job_id']);
        if (is_wp_error($locked)) {
            return $locked;
        }

        if ($stage === 'apply') {
            if ($release['status'] !== Fsync_Release::STATUS_DRY_RUN_READY) {
                self::durable_unlock($release['release_id'], (string) $job['job_id']);

                return new WP_Error('fsync_release_not_ready', 'ドライランが完了していません。', array('status' => 409));
            }
            $authorized = Fsync_Release::verify_confirmation($release, (string) ($payload['plan_hash'] ?? ''), (string) $confirmation);
            if (is_wp_error($authorized)) {
                self::durable_unlock($release['release_id'], (string) $job['job_id']);

                return $authorized;
            }
            $config_hash = Fsync_Utils::canonical_hash(Fsync_Config::document());
            if (is_wp_error($config_hash) || ! hash_equals($release['config_hash'], (string) $config_hash)) {
                self::durable_unlock($release['release_id'], (string) $job['job_id']);

                return new WP_Error('fsync_config_changed', 'ドライラン後に設定が変更されています。もう一度ドライランしてください。', array('status' => 409));
            }
            $snapshot = Fsync_Snapshot::create($release);
            if (is_wp_error($snapshot)) {
                self::durable_unlock($release['release_id'], (string) $job['job_id']);

                return $snapshot;
            }
            $updated = Fsync_Release::set_status($release['release_id'], Fsync_Release::STATUS_APPLYING, array('snapshot_id' => $snapshot['snapshot_id']));
            if (is_wp_error($updated)) {
                self::durable_unlock($release['release_id'], (string) $job['job_id']);

                return $updated;
            }
            self::maintenance(true);
            self::install_runtime_guard($snapshot['snapshot_id'], $release['release_id'], (string) $job['job_id']);
            $payload['snapshot_id'] = $snapshot['snapshot_id'];
            $payload['stage'] = 'apply_records';
            $payload['cursor'] = 0;
            $stage = 'apply_records';
            $release = $updated;
        } elseif ($release['status'] !== Fsync_Release::STATUS_APPLYING) {
            self::durable_unlock($release['release_id'], (string) $job['job_id']);

            return new WP_Error('fsync_apply_state_invalid', '再開対象のリリースが適用中ではありません。', array('status' => 409));
        }

        self::maintenance(true);
        self::refresh_runtime_guard();
        $work = self::work_items($release);
        if (is_wp_error($work)) {
            return self::rollback_failure($release, $payload, $job['job_id'], $work);
        }
        if (empty($payload['code_deletes_staged'])) {
            $staged_deletes = self::stage_code_deletes($release, $work['deletes']);
            if (is_wp_error($staged_deletes)) {
                return self::rollback_failure($release, $payload, $job['job_id'], $staged_deletes);
            }
            $payload['code_deletes_staged'] = true;
        }
        $total = count($work['records']) + count($work['relationships']) + count($work['deletes']) + 1;
        $processed = 0;
        while ($processed < self::BATCH_SIZE && $stage !== 'verify') {
            $list = $stage === 'apply_records'
                ? $work['records']
                : ($stage === 'apply_relationships' ? $work['relationships'] : $work['deletes']);
            $cursor = (int) ($payload['cursor'] ?? 0);
            if ($cursor >= count($list)) {
                if ($stage === 'apply_records' && empty($payload['code_committed'])) {
                    $committed = self::commit_staged_code($release['release_id']);
                    if (is_wp_error($committed)) {
                        return self::rollback_failure($release, $payload, $job['job_id'], $committed);
                    }
                    $payload['code_committed'] = true;
                }
                $stage = $stage === 'apply_records'
                    ? 'apply_relationships'
                    : ($stage === 'apply_relationships' ? 'apply_deletes' : 'verify');
                $payload['stage'] = $stage;
                $payload['cursor'] = 0;
                continue;
            }
            $item = $list[$cursor];
            // Runtime activation must see the completely staged code tree.
            // Keep all database/content work ahead of the directory swap, but
            // commit immediately before the final runtime record is applied.
            if ($stage === 'apply_records'
                && (string) ($item['entity_kind'] ?? '') === 'runtime'
                && empty($payload['code_committed'])) {
                $committed = self::commit_staged_code($release['release_id']);
                if (is_wp_error($committed)) {
                    return self::rollback_failure($release, $payload, $job['job_id'], $committed);
                }
                $payload['code_committed'] = true;
            }
            $applied = self::apply_work_item($release, $item, $stage);
            if (is_wp_error($applied)) {
                return self::rollback_failure($release, $payload, $job['job_id'], $applied);
            }
            $payload['cursor'] = $cursor + 1;
            $processed++;
        }

        $progress = self::work_progress($work, $stage, (int) ($payload['cursor'] ?? 0));
        if ($stage !== 'verify') {
            // Core's .maintenance file prevents the next signed REST request
            // from reaching WordPress at all. Keep it only while a batch is
            // mutating data; the durable job lease still prevents overlap
            // between requests.
            self::maintenance(false);
            return array('complete' => false, 'payload' => $payload, 'phase' => $stage, 'progress' => $progress, 'total' => $total);
        }

        $verified = Fsync_Manifest::build((string) ($release['summary']['profile'] ?? 'full'), (string) ($release['summary']['source_env'] ?? ''));
        if (is_wp_error($verified)) {
            return self::rollback_failure($release, $payload, $job['job_id'], $verified);
        }
        $verification = self::verify_applied_items($release, $verified);
        if (is_wp_error($verification)) {
            return self::rollback_failure($release, $payload, $job['job_id'], $verification);
        }
        $release = Fsync_Release::set_status($release['release_id'], Fsync_Release::STATUS_APPLIED, array('verified_manifest_id' => $verified['manifest_id']));
        $receipt = is_wp_error($release) ? $release : Fsync_Release::receipt($release, $verified);
        if (is_wp_error($receipt)) {
            return self::rollback_failure(is_wp_error($release) ? Fsync_Release::get($job['release_id']) : $release, $payload, $job['job_id'], $receipt);
        }
        $finished = Fsync_Release::set_status($release['release_id'], Fsync_Release::STATUS_VERIFIED, array('receipt_id' => $receipt['receipt_id']));
        if (is_wp_error($finished)) {
            return self::rollback_failure($release, $payload, $job['job_id'], $finished);
        }
        self::maintenance(false);
        self::durable_unlock($release['release_id'], (string) $job['job_id']);
        // Keep the pre-switch code directories until the snapshot retention
        // expires so a later manual rollback is still directory-atomic.
        self::cleanup_code_state($release['release_id'], false);
        delete_option('fsync_runtime_guard');
        delete_option('fsync_runtime_guard_failed');
        Fsync_Log::info('release_verified', '移行リリースを適用し、検証しました。', array('data' => array('release_id' => $release['release_id'], 'receipt_id' => $receipt['receipt_id'])));
        $result = array(
            'release_id' => $release['release_id'],
            'status' => Fsync_Release::STATUS_VERIFIED,
            'snapshot_id' => (string) ($payload['snapshot_id'] ?? ''),
            'receipt_id' => $receipt['receipt_id'],
            'manifest_root' => $verified['root_hash'],
            'source_manifest_root' => (string) ($release['summary']['source_manifest_root'] ?? ''),
        );

        return array('complete' => true, 'payload' => $payload, 'phase' => 'complete', 'progress' => $total, 'total' => $total, 'result' => $result);
    }

    /** Roll back a partially applied job when an operator cancels it. */
    public static function abort(array $job)
    {
        $snapshot_id = (string) ($job['payload']['snapshot_id'] ?? '');
        if ($snapshot_id !== '') {
            $restored = Fsync_Snapshot::restore($snapshot_id);
            if (is_wp_error($restored)) {
                return $restored;
            }
        }
        self::maintenance(false);
        self::durable_unlock((string) $job['release_id'], (string) $job['job_id']);
        self::cleanup_code_state((string) $job['release_id']);
        delete_option('fsync_runtime_guard');
        delete_option('fsync_runtime_guard_failed');

        return true;
    }

    /** @return array|WP_Error */
    public static function release($release_id, $plan_hash, $confirmation)
    {
        $release = Fsync_Release::get($release_id);
        if (is_wp_error($release)) {
            return $release;
        }
        if ($release['status'] !== Fsync_Release::STATUS_DRY_RUN_READY) {
            return new WP_Error('fsync_release_not_ready', 'ドライランが完了していません。', array('status' => 409));
        }
        $authorized = Fsync_Release::verify_confirmation($release, $plan_hash, $confirmation);
        if (is_wp_error($authorized)) {
            return $authorized;
        }
        $config_hash = Fsync_Utils::canonical_hash(Fsync_Config::document());
        if (is_wp_error($config_hash) || ! hash_equals($release['config_hash'], (string) $config_hash)) {
            return new WP_Error('fsync_config_changed', 'ドライラン後に設定が変更されています。もう一度ドライランしてください。', array('status' => 409));
        }
        $locked = self::lock($release_id);
        if (is_wp_error($locked)) {
            return $locked;
        }

        $snapshot = Fsync_Snapshot::create($release);
        if (is_wp_error($snapshot)) {
            self::unlock($release_id);

            return $snapshot;
        }
        Fsync_Release::set_status($release_id, Fsync_Release::STATUS_APPLYING, array('snapshot_id' => $snapshot['snapshot_id']));
        self::maintenance(true);
        self::install_runtime_guard($snapshot['snapshot_id']);

        $result = self::apply_release_items($release);
        if (is_wp_error($result)) {
            Fsync_Log::error('apply_failed', $result->get_error_message(), array('data' => array('release_id' => $release_id)));
            $rolled_back = Fsync_Snapshot::restore($snapshot['snapshot_id']);
            self::maintenance(false);
            self::unlock($release_id);
            if (is_wp_error($rolled_back)) {
                return new WP_Error(
                    'fsync_apply_and_rollback_failed',
                    '適用に失敗し、自動ロールバックにも失敗しました。スナップショットから手動復旧してください。',
                    array('apply_error' => $result->get_error_message(), 'snapshot_id' => $snapshot['snapshot_id'])
                );
            }

            return $result;
        }

        $verified = Fsync_Manifest::build((string) ($release['summary']['profile'] ?? 'full'), (string) ($release['summary']['source_env'] ?? ''));
        if (is_wp_error($verified)) {
            Fsync_Snapshot::restore($snapshot['snapshot_id']);
            self::maintenance(false);
            self::unlock($release_id);

            return $verified;
        }
        $verification = self::verify_applied_items($release, $verified);
        if (is_wp_error($verification)) {
            Fsync_Snapshot::restore($snapshot['snapshot_id']);
            self::maintenance(false);
            self::unlock($release_id);

            return $verification;
        }

        $release = Fsync_Release::set_status($release_id, Fsync_Release::STATUS_APPLIED, array('verified_manifest_id' => $verified['manifest_id']));
        $receipt = is_wp_error($release) ? $release : Fsync_Release::receipt($release, $verified);
        if (is_wp_error($receipt)) {
            Fsync_Snapshot::restore($snapshot['snapshot_id']);
            self::maintenance(false);
            self::unlock($release_id);

            return $receipt;
        }

        Fsync_Release::set_status($release_id, Fsync_Release::STATUS_VERIFIED, array('receipt_id' => $receipt['receipt_id']));
        self::maintenance(false);
        self::unlock($release_id);
        Fsync_Log::info('release_verified', '移行リリースを適用し、検証しました。', array('data' => array('release_id' => $release_id, 'receipt_id' => $receipt['receipt_id'])));

        return array(
            'release_id' => $release_id,
            'status' => Fsync_Release::STATUS_VERIFIED,
            'snapshot_id' => $snapshot['snapshot_id'],
            'receipt_id' => $receipt['receipt_id'],
            'manifest_root' => $verified['root_hash'],
            'source_manifest_root' => (string) ($release['summary']['source_manifest_root'] ?? ''),
        );
    }

    /** Apply decoded records in dependency order. Used by apply and restore. */
    public static function apply_records(array $records, $restoring = false)
    {
        $order = array('user' => 10, 'term' => 20, 'file' => 30, 'post' => 40, 'comment' => 50, 'option' => 60, 'table' => 70, 'runtime' => 80);
        uasort($records, static function ($left, $right) use ($order) {
            return ($order[$left['kind']] ?? 999) - ($order[$right['kind']] ?? 999);
        });
        foreach ($records as $record) {
            $applied = self::record($record, false, $restoring);
            if (is_wp_error($applied)) {
                return $applied;
            }
        }
        // Parent, taxonomy and reference ids can only be resolved after all
        // participating records have local ids.
        foreach ($records as $record) {
            if (in_array($record['kind'], array('term', 'post', 'comment'), true)) {
                $applied = self::record($record, true, $restoring);
                if (is_wp_error($applied)) {
                    return $applied;
                }
            }
        }

        return true;
    }

    /** @return true|WP_Error */
    public static function record(array $record, $relationships = false, $restoring = false)
    {
        if ((int) ($record['format_version'] ?? 0) !== Fsync_Portable::FORMAT_VERSION
            || ! isset($record['kind'], $record['uid'], $record['data'])) {
            return new WP_Error('fsync_record_invalid', '可搬レコードの形式が不正です。');
        }
        $record['data'] = Fsync_Portable::hydrate_value($record['data']);
        switch ($record['kind']) {
            case 'user':
                return self::apply_user($record);
            case 'term':
                return self::apply_term($record, $relationships);
            case 'file':
                return self::apply_file($record);
            case 'post':
                return self::apply_post($record, $relationships);
            case 'comment':
                return self::apply_comment($record, $relationships);
            case 'option':
                return self::apply_option($record);
            case 'table':
                return self::apply_table($record);
            case 'runtime':
                return self::apply_runtime($record, $restoring);
        }

        return new WP_Error('fsync_record_kind_unknown', sprintf('未対応の可搬種別です: %s', $record['kind']));
    }

    /** Delete an entity created after a snapshot or requested by a release. */
    public static function delete_identity($kind, $uid, $key, array $record = array())
    {
        $id = in_array($kind, array('post', 'term', 'comment', 'user'), true)
            ? Fsync_Identity::local_id($kind, $uid)
            : 0;
        if ($kind === 'post') {
            if ($id > 0 && wp_delete_post($id, true) === false) {
                return new WP_Error('fsync_post_delete_failed', '投稿を削除できません。');
            }
            Fsync_Identity::forget($kind, $uid);

            return true;
        }
        if ($kind === 'term') {
            if ($id === 0) {
                Fsync_Identity::forget($kind, $uid);
                return true;
            }
            $taxonomy = (string) ($record['data']['taxonomy'] ?? '');
            $deleted = wp_delete_term($id, $taxonomy);
            if (is_wp_error($deleted)) {
                return $deleted;
            }
            Fsync_Identity::forget($kind, $uid);

            return true;
        }
        if ($kind === 'comment') {
            if ($id > 0 && ! wp_delete_comment($id, true)) {
                return new WP_Error('fsync_comment_delete_failed', 'コメントを削除できません。');
            }
            Fsync_Identity::forget($kind, $uid);

            return true;
        }
        if ($kind === 'user') {
            if ($id === 0) {
                Fsync_Identity::forget($kind, $uid);
                return true;
            }
            if ($id === get_current_user_id() || self::is_last_administrator($id)) {
                return new WP_Error('fsync_emergency_admin_protected', '現在の管理者または最後の管理者は削除できません。');
            }
            require_once ABSPATH . 'wp-admin/includes/user.php';

            if (! wp_delete_user($id)) {
                return new WP_Error('fsync_user_delete_failed', 'ユーザーを削除できません。');
            }
            Fsync_Identity::forget($kind, $uid);

            return true;
        }
        if ($kind === 'option') {
            $name = (string) ($record['data']['name'] ?? '');
            if ($name === '' || Fsync_Config::is_protected_option($name)) {
                return true;
            }

            return delete_option($name) ? true : true;
        }
        if ($kind === 'file') {
            $target = self::file_target((array) ($record['data'] ?? array()));
            if (is_wp_error($target)) {
                return $target;
            }
            if (is_file($target) && ! @unlink($target)) {
                return new WP_Error('fsync_file_delete_failed', sprintf('ファイルを削除できません: %s', $record['data']['path'] ?? $key));
            }

            return true;
        }
        if ($kind === 'table') {
            return self::delete_table_record($record);
        }

        return true;
    }

    private static function apply_release_items(array $release)
    {
        $source_manifest = Fsync_Manifest::get($release['manifest_id']);
        $target_manifest = Fsync_Manifest::get((string) ($release['summary']['target_manifest_id'] ?? ''));
        if (is_wp_error($source_manifest) || is_wp_error($target_manifest)) {
            return is_wp_error($source_manifest) ? $source_manifest : $target_manifest;
        }
        $records = array();
        $deletes = array();
        foreach (Fsync_Release::items($release['release_id']) as $item) {
            $action = $item['action'];
            if ($action === Fsync_Diff::ACTION_UNCHANGED || $action === Fsync_Diff::ACTION_BLOCKED
                || ($action === Fsync_Diff::ACTION_CONFLICT && in_array($item['resolution'], array('target', 'skip'), true))) {
                continue;
            }
            if ($action === Fsync_Diff::ACTION_DELETE) {
                $deletes[$item['item_key']] = $item;
                continue;
            }
            $raw = Fsync_Store::get($item['payload_hash']);
            if (is_wp_error($raw)) {
                return $raw;
            }
            $record = Fsync_Utils::decode($raw);
            if (is_wp_error($record)) {
                return $record;
            }
            $actual_hash = Fsync_Utils::canonical_hash($record);
            if (is_wp_error($actual_hash) || ! hash_equals($item['source_hash'], (string) $actual_hash)) {
                return new WP_Error('fsync_payload_hash_mismatch', sprintf('項目ペイロードが改変されています: %s', $item['item_key']));
            }
            $records[$item['item_key']] = $record;
        }
        $applied = self::apply_records($records);
        if (is_wp_error($applied)) {
            return $applied;
        }

        $delete_order = array('comment' => 10, 'post' => 20, 'term' => 30, 'user' => 40, 'option' => 50, 'table' => 60, 'file' => 70, 'runtime' => 80);
        uasort($deletes, static function ($left, $right) use ($delete_order) {
            return ($delete_order[$left['entity_kind']] ?? 999) - ($delete_order[$right['entity_kind']] ?? 999);
        });
        foreach ($deletes as $key => $item) {
            $target_item = $target_manifest['items'][$key] ?? null;
            $record = array();
            if (is_array($target_item)) {
                $raw = Fsync_Store::get((string) $target_item['payload_hash']);
                if (is_wp_error($raw)) {
                    return $raw;
                }
                $record = Fsync_Utils::decode($raw);
                if (is_wp_error($record)) {
                    return $record;
                }
                $record['data'] = Fsync_Portable::hydrate_value($record['data']);
            }
            $deleted = self::delete_identity($item['entity_kind'], $item['entity_uid'], $key, $record);
            if (is_wp_error($deleted)) {
                return $deleted;
            }
        }

        return true;
    }

    /** Build deterministic dependency-ordered work lists from the immutable plan. */
    private static function work_items(array $release)
    {
        $order = array('user' => 10, 'term' => 20, 'file' => 30, 'post' => 40, 'comment' => 50, 'option' => 60, 'table' => 70, 'runtime' => 80);
        $records = array();
        $relationships = array();
        $deletes = array();
        foreach (Fsync_Release::items($release['release_id']) as $item) {
            $action = (string) $item['action'];
            if ($action === Fsync_Diff::ACTION_UNCHANGED || $action === Fsync_Diff::ACTION_BLOCKED
                || ($action === Fsync_Diff::ACTION_CONFLICT && in_array($item['resolution'], array('target', 'skip'), true))) {
                continue;
            }
            if ($action === Fsync_Diff::ACTION_DELETE) {
                $deletes[] = $item;
                continue;
            }
            $records[] = $item;
            if (! empty($item['has_relationships']) && in_array($item['entity_kind'], array('term', 'post', 'comment'), true)) {
                $relationships[] = $item;
            }
        }
        $sort = static function ($left, $right) use ($order) {
            $comparison = ($order[$left['entity_kind']] ?? 999) - ($order[$right['entity_kind']] ?? 999);

            return $comparison === 0 ? strcmp((string) $left['item_key'], (string) $right['item_key']) : $comparison;
        };
        usort($records, $sort);
        usort($relationships, $sort);
        $delete_order = array('comment' => 10, 'post' => 20, 'term' => 30, 'user' => 40, 'option' => 50, 'table' => 60, 'file' => 70, 'runtime' => 80);
        usort($deletes, static function ($left, $right) use ($delete_order) {
            $comparison = ($delete_order[$left['entity_kind']] ?? 999) - ($delete_order[$right['entity_kind']] ?? 999);

            return $comparison === 0 ? strcmp((string) $left['item_key'], (string) $right['item_key']) : $comparison;
        });

        return array('records' => $records, 'relationships' => $relationships, 'deletes' => $deletes);
    }

    private static function apply_work_item(array $release, array $item, $stage)
    {
        if ($stage === 'apply_deletes') {
            $target_manifest = Fsync_Manifest::get((string) ($release['summary']['target_manifest_id'] ?? ''));
            if (is_wp_error($target_manifest)) {
                return $target_manifest;
            }
            $target_item = $target_manifest['items'][$item['item_key']] ?? null;
            $record = array();
            if (is_array($target_item)) {
                $raw = Fsync_Store::get((string) $target_item['payload_hash']);
                $record = is_wp_error($raw) ? $raw : Fsync_Utils::decode($raw);
                if (is_wp_error($record)) {
                    return $record;
                }
                $record['data'] = Fsync_Portable::hydrate_value((array) ($record['data'] ?? array()));
            }

            return self::delete_identity($item['entity_kind'], $item['entity_uid'], $item['item_key'], $record);
        }
        $raw = Fsync_Store::get((string) $item['payload_hash']);
        $record = is_wp_error($raw) ? $raw : Fsync_Utils::decode($raw);
        if (is_wp_error($record)) {
            return $record;
        }
        $actual_hash = Fsync_Utils::canonical_hash($record);
        if (is_wp_error($actual_hash) || ! hash_equals((string) $item['source_hash'], (string) $actual_hash)) {
            return new WP_Error('fsync_payload_hash_mismatch', sprintf('項目ペイロードが改変されています: %s', $item['item_key']));
        }

        if ((string) ($record['kind'] ?? '') === 'file') {
            return self::stage_or_apply_file($record, $release['release_id']);
        }

        return self::record($record, $stage === 'apply_relationships');
    }

    private static function work_progress(array $work, $stage, $cursor)
    {
        if ($stage === 'apply_records') {
            return $cursor;
        }
        if ($stage === 'apply_relationships') {
            return count($work['records']) + $cursor;
        }
        if ($stage === 'apply_deletes') {
            return count($work['records']) + count($work['relationships']) + $cursor;
        }

        return count($work['records']) + count($work['relationships']) + count($work['deletes']);
    }

    private static function rollback_failure(array $release, array $payload, $job_id, WP_Error $error)
    {
        Fsync_Log::error('apply_failed', $error->get_error_message(), array('data' => array('release_id' => $release['release_id'])));
        $snapshot_id = (string) ($payload['snapshot_id'] ?? '');
        $rolled_back = $snapshot_id === '' ? true : Fsync_Snapshot::restore($snapshot_id);
        self::maintenance(false);
        self::durable_unlock($release['release_id'], (string) $job_id);
        self::cleanup_code_state($release['release_id']);
        delete_option('fsync_runtime_guard');
        delete_option('fsync_runtime_guard_failed');
        if (is_wp_error($rolled_back)) {
            return new WP_Error(
                'fsync_apply_and_rollback_failed',
                '適用に失敗し、自動ロールバックにも失敗しました。スナップショットから手動復旧してください。',
                array('apply_error' => $error->get_error_message(), 'snapshot_id' => $snapshot_id)
            );
        }

        return $error;
    }

    private static function apply_user(array $record)
    {
        global $wpdb;
        $data = (array) $record['data'];
        $existing = get_user_by('login', (string) $data['login']);
        $args = array(
            'user_login' => (string) $data['login'],
            'user_nicename' => (string) $data['nicename'],
            'display_name' => (string) $data['display_name'],
            'user_email' => (string) $data['email'],
            'user_url' => (string) $data['url'],
        );
        if ($existing) {
            $args['ID'] = (int) $existing->ID;
        } else {
            $args['user_pass'] = wp_generate_password(32, true, true);
        }
        $id = wp_insert_user($args);
        if (is_wp_error($id)) {
            return $id;
        }
        if (! empty($data['password_hash'])) {
            $wpdb->update($wpdb->users, array('user_pass' => (string) $data['password_hash']), array('ID' => (int) $id));
        }
        $user = new WP_User((int) $id);
        $roles = array_values((array) ($data['roles'] ?? array()));
        if (in_array('administrator', (array) $user->roles, true)
            && ! in_array('administrator', $roles, true)
            && self::is_last_administrator((int) $id)) {
            $roles[] = 'administrator';
        }
        $user->set_role('');
        foreach ($roles as $role) {
            $user->add_role((string) $role);
        }
        // Capabilities and sessions are destination-owned. The former are
        // applied through WP_User above; the latter are intentionally never
        // transported so active target logins survive a migration.
        self::replace_meta(
            'user',
            (int) $id,
            (array) ($data['meta'] ?? array()),
            array($wpdb->prefix . 'capabilities', $wpdb->prefix . 'user_level', 'session_tokens')
        );
        $registered = (string) ($data['registered'] ?? '');
        if ($registered !== '') {
            $saved_registered = $wpdb->update($wpdb->users, array('user_registered' => $registered), array('ID' => (int) $id));
            if ($saved_registered === false) {
                return new WP_Error('fsync_user_registered_write_failed', 'ユーザー登録日時を保存できません。');
            }
            clean_user_cache((int) $id);
        }
        if (! Fsync_Identity::remember('user', $record['uid'], (int) $id, (string) $data['login'])) {
            return new WP_Error('fsync_identity_conflict', 'ユーザーの可搬UIDが別レコードと衝突しています。');
        }
        update_user_meta((int) $id, Fsync_Identity::META_KEY, $record['uid']);

        return true;
    }

    private static function apply_term(array $record, $relationships)
    {
        $data = (array) $record['data'];
        $taxonomy = (string) $data['taxonomy'];
        $id = Fsync_Identity::local_id('term', $record['uid']);
        if ($id <= 0 && (string) ($data['slug'] ?? '') !== '') {
            $existing = term_exists((string) $data['slug'], $taxonomy);
            if (is_array($existing)) {
                $id = (int) ($existing['term_id'] ?? 0);
            } elseif (is_int($existing)) {
                $id = $existing;
            }
        }
        $args = array('description' => (string) $data['description'], 'slug' => (string) $data['slug']);
        if ($relationships && ! empty($data['parent_uid'])) {
            $args['parent'] = Fsync_Identity::local_id('term', (string) $data['parent_uid']);
            if ($args['parent'] <= 0) {
                return new WP_Error('fsync_reference_unresolved', sprintf('分類の親参照を解決できません: %s', $record['uid']));
            }
        }
        if ($id > 0 && term_exists($id, $taxonomy)) {
            $result = wp_update_term($id, $taxonomy, array_merge($args, array('name' => (string) $data['name'])));
        } else {
            $result = wp_insert_term((string) $data['name'], $taxonomy, $args);
            if (! is_wp_error($result)) {
                $id = (int) $result['term_id'];
            }
        }
        if (is_wp_error($result)) {
            return $result;
        }
        if (! $relationships) {
            self::replace_meta('term', $id, (array) ($data['meta'] ?? array()), array());
        }
        update_term_meta($id, Fsync_Identity::META_KEY, $record['uid']);
        if (! Fsync_Identity::remember('term', $record['uid'], $id, $taxonomy . ':' . $data['slug'])) {
            return new WP_Error('fsync_identity_conflict', '分類の可搬UIDが別レコードと衝突しています。');
        }

        return true;
    }

    private static function apply_post(array $record, $relationships)
    {
        global $wpdb;

        $data = (array) $record['data'];
        $id = Fsync_Identity::local_id('post', $record['uid']);
        if ($id <= 0 && (string) ($data['slug'] ?? '') !== '') {
            $existing = get_page_by_path((string) $data['slug'], OBJECT, (string) $data['post_type']);
            if ($existing) {
                $id = (int) $existing->ID;
            }
        }
        $author_id = self::author_id((string) $data['author_login']);
        if (is_wp_error($author_id)) {
            return $author_id;
        }
        $content = (string) $data['content'];
        if ($relationships && strpos($content, '{{FSYNC_REF:post:') !== false) {
            $content = Fsync_Portable::hydrate_content($content);
            if (is_wp_error($content)) {
                return $content;
            }
        }
        $args = array(
            'post_type' => (string) $data['post_type'],
            'post_status' => (string) $data['status'],
            'post_title' => (string) $data['title'],
            'post_name' => (string) $data['slug'],
            'post_content' => $content,
            'post_excerpt' => (string) $data['excerpt'],
            'post_date_gmt' => (string) $data['date_gmt'],
            'post_modified_gmt' => (string) $data['modified_gmt'],
            'post_parent' => 0,
            'menu_order' => (int) $data['menu_order'],
            'post_mime_type' => (string) $data['mime_type'],
            'post_password' => (string) $data['password'],
            'post_author' => $author_id,
            'comment_status' => (string) $data['comment_status'],
            'ping_status' => (string) $data['ping_status'],
        );
        if ($id > 0 && get_post($id)) {
            $args['ID'] = $id;
        }
        if ($relationships && ! empty($data['parent_uid'])) {
            $args['post_parent'] = Fsync_Identity::local_id('post', (string) $data['parent_uid']);
            if ($args['post_parent'] <= 0) {
                return new WP_Error('fsync_reference_unresolved', sprintf('投稿の親参照を解決できません: %s', $record['uid']));
            }
        }
        $id = wp_insert_post(wp_slash($args), true);
        if (is_wp_error($id)) {
            return $id;
        }

        // wp_insert_post() intentionally stamps post_modified with the current
        // time. Exact hashing needs the source timestamps and raw block markup,
        // so write the already authenticated portable values back explicitly.
        $exact = $wpdb->update(
            $wpdb->posts,
            array(
                'post_author' => (int) $author_id,
                'post_date' => get_date_from_gmt((string) $data['date_gmt']),
                'post_date_gmt' => (string) $data['date_gmt'],
                'post_content' => $content,
                'post_title' => (string) $data['title'],
                'post_excerpt' => (string) $data['excerpt'],
                'post_status' => (string) $data['status'],
                'comment_status' => (string) $data['comment_status'],
                'ping_status' => (string) $data['ping_status'],
                'post_password' => (string) $data['password'],
                'post_name' => (string) $data['slug'],
                'post_modified' => get_date_from_gmt((string) $data['modified_gmt']),
                'post_modified_gmt' => (string) $data['modified_gmt'],
                'post_parent' => (int) ($args['post_parent'] ?? 0),
                'menu_order' => (int) $data['menu_order'],
                'post_type' => (string) $data['post_type'],
                'post_mime_type' => (string) $data['mime_type'],
            ),
            array('ID' => (int) $id)
        );
        if ($exact === false) {
            return new WP_Error('fsync_post_exact_write_failed', '投稿の正確な内容を保存できません。');
        }
        clean_post_cache((int) $id);
        update_post_meta($id, Fsync_Identity::META_KEY, $record['uid']);
        if (! Fsync_Identity::remember('post', $record['uid'], (int) $id, (string) $data['post_type'] . ':' . $data['slug'])) {
            return new WP_Error('fsync_identity_conflict', '投稿の可搬UIDが別レコードと衝突しています。');
        }

        // Terms are available before posts in the dependency order, so assign
        // them in the base pass. This also removes WordPress's automatically
        // added default category when the source intentionally has none.
        foreach ((array) ($data['taxonomies'] ?? array()) as $taxonomy => $uids) {
            $term_ids = array();
            foreach ((array) $uids as $uid) {
                $term_id = Fsync_Identity::local_id('term', (string) $uid);
                if ($term_id <= 0) {
                    return new WP_Error('fsync_reference_unresolved', sprintf('分類参照を解決できません: %s', $uid));
                }
                $term_ids[] = $term_id;
            }
            $assigned = wp_set_object_terms((int) $id, $term_ids, (string) $taxonomy, false);
            if (is_wp_error($assigned)) {
                return $assigned;
            }
        }

        if (! $relationships && ! Fsync_Portable::has_relationships($record)) {
            self::replace_meta('post', (int) $id, (array) ($data['meta'] ?? array()), array());
        }

        if ($relationships) {
            $meta = self::references_in((array) ($data['meta'] ?? array()));
            if (is_wp_error($meta)) {
                return $meta;
            }
            self::replace_meta('post', (int) $id, $meta, array());
        }
        if ((string) $data['post_type'] === 'attachment' && isset($data['attachment'])) {
            $attachment = (array) $data['attachment'];
            $relative_path = (string) ($attachment['relative_path'] ?? '');
            $alt = (string) ($attachment['alt'] ?? '');
            if ($relative_path === '') {
                delete_post_meta($id, '_wp_attached_file');
            } else {
                update_post_meta($id, '_wp_attached_file', $relative_path);
            }
            if ($alt === '') {
                delete_post_meta($id, '_wp_attachment_image_alt');
            } else {
                update_post_meta($id, '_wp_attachment_image_alt', $alt);
            }
            if (! empty($attachment['metadata'])) {
                wp_update_attachment_metadata($id, (array) $attachment['metadata']);
            } else {
                delete_post_meta($id, '_wp_attachment_metadata');
            }
        }

        return true;
    }

    private static function apply_comment(array $record, $relationships)
    {
        global $wpdb;

        $data = (array) $record['data'];
        $id = Fsync_Identity::local_id('comment', $record['uid']);
        $post_id = Fsync_Identity::local_id('post', (string) $data['post_uid']);
        if ($post_id <= 0) {
            return new WP_Error('fsync_reference_unresolved', sprintf('コメントの投稿参照を解決できません: %s', $record['uid']));
        }
        $user = $data['author_login'] === '' ? null : get_user_by('login', (string) $data['author_login']);
        $args = array(
            'comment_ID' => $id,
            'comment_post_ID' => $post_id,
            'comment_parent' => 0,
            'user_id' => $user ? (int) $user->ID : 0,
            'comment_author' => (string) $data['author_name'],
            'comment_author_email' => (string) $data['author_email'],
            'comment_author_url' => (string) $data['author_url'],
            'comment_author_IP' => (string) $data['author_ip'],
            'comment_date_gmt' => (string) $data['date_gmt'],
            'comment_content' => (string) $data['content'],
            'comment_approved' => (string) $data['approved'],
            'comment_agent' => (string) $data['agent'],
            'comment_type' => (string) $data['type'],
        );
        if ($relationships && ! empty($data['parent_uid'])) {
            $args['comment_parent'] = Fsync_Identity::local_id('comment', (string) $data['parent_uid']);
            if ($args['comment_parent'] <= 0) {
                return new WP_Error('fsync_reference_unresolved', sprintf('コメントの親参照を解決できません: %s', $record['uid']));
            }
        }
        if ($id > 0 && get_comment($id)) {
            $result = wp_update_comment(wp_slash($args), true);
        } else {
            unset($args['comment_ID']);
            $id = wp_insert_comment(wp_slash($args));
            $result = $id > 0 ? $id : new WP_Error('fsync_comment_insert_failed', 'コメントを作成できません。');
        }
        if (is_wp_error($result)) {
            return $result;
        }

        // Core's comment insertion filters can rewrite HTML and replace the
        // supplied GMT timestamp with "now". The payload is already trusted,
        // authenticated and hash-verified, so restore the exact portable
        // fields after Core has allocated/validated the row.
        $exact = $wpdb->update(
            $wpdb->comments,
            array(
                'comment_author' => (string) $data['author_name'],
                'comment_author_email' => (string) $data['author_email'],
                'comment_author_url' => (string) $data['author_url'],
                'comment_author_IP' => (string) $data['author_ip'],
                'comment_date' => get_date_from_gmt((string) $data['date_gmt']),
                'comment_date_gmt' => (string) $data['date_gmt'],
                'comment_content' => (string) $data['content'],
                'comment_approved' => (string) $data['approved'],
                'comment_agent' => (string) $data['agent'],
                'comment_type' => (string) $data['type'],
            ),
            array('comment_ID' => (int) $id)
        );
        if ($exact === false) {
            return new WP_Error('fsync_comment_exact_write_failed', 'コメントの正確な内容を保存できません。');
        }
        clean_comment_cache((int) $id);
        if (! $relationships) {
            self::replace_meta('comment', (int) $id, (array) ($data['meta'] ?? array()), array());
        }
        update_comment_meta((int) $id, Fsync_Identity::META_KEY, $record['uid']);
        if (! Fsync_Identity::remember('comment', $record['uid'], (int) $id)) {
            return new WP_Error('fsync_identity_conflict', 'コメントの可搬UIDが別レコードと衝突しています。');
        }

        return true;
    }

    private static function apply_option(array $record)
    {
        $name = (string) $record['data']['name'];
        if ($name === '' || Fsync_Config::is_protected_option($name)) {
            return new WP_Error('fsync_option_protected', sprintf('保護対象のオプションは移行できません: %s', $name));
        }

        return update_option($name, $record['data']['value'], false) !== false ? true : true;
    }

    private static function apply_table(array $record)
    {
        global $wpdb;
        $data = (array) $record['data'];
        $name = (string) ($data['table'] ?? '');
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1 || in_array($name, Fsync_Config::PROTECTED_TABLES, true)) {
            return new WP_Error('fsync_table_invalid', '独自テーブル名が不正または保護対象です。');
        }
        $config = (array) ($data['config'] ?? array());
        $identity = (array) ($data['identity'] ?? array());
        $row = (array) ($data['row'] ?? array());
        if ($row === array()) {
            return new WP_Error('fsync_table_row_invalid', '独自テーブル行が空です。');
        }
        if ($identity === array()) {
            return new WP_Error('fsync_table_identity_missing', '独自テーブル行の安定キーがありません。');
        }
        foreach (array_merge(array_keys($row), array_keys($identity)) as $column) {
            if (preg_match('/^[A-Za-z0-9_]+$/', (string) $column) !== 1) {
                return new WP_Error('fsync_table_column_invalid', '独自テーブル行に不正な列名があります。');
            }
        }
        foreach ((array) ($config['refs'] ?? array()) as $column => $rules) {
            unset($rules);
            if (isset($row[$column]) && is_array($row[$column]) && isset($row[$column]['fsync_ref'], $row[$column]['uids'])) {
                $resolved = self::hydrate_table_reference($row[$column]);
                if (is_wp_error($resolved)) {
                    return $resolved;
                }
                $row[$column] = $resolved;
            }
        }

        $table = $wpdb->prefix . $name;
        $wpdb->last_error = '';
        $matches = $wpdb->get_results(self::table_select_sql($table, $identity), ARRAY_A);
        if ($wpdb->last_error !== '') {
            return new WP_Error('fsync_table_read_failed', sprintf('独自テーブル %s の既存行を確認できません。', $name));
        }
        if (count((array) $matches) > 1) {
            return new WP_Error('fsync_table_identity_duplicate', sprintf('独自テーブル %s の安定キーが複数行に一致しました。', $name));
        }
        $existing = $matches[0] ?? null;
        if (is_array($existing)) {
            $primary = (string) ($config['primary_key'] ?? '');
            if ($primary !== '' && array_key_exists($primary, $existing)) {
                $row[$primary] = $existing[$primary];
            }
            $result = $wpdb->update($table, $row, $identity);
        } else {
            $result = $wpdb->insert($table, $row);
        }

        return $result === false ? new WP_Error('fsync_table_write_failed', sprintf('独自テーブル %s を更新できません。', $name)) : true;
    }

    private static function hydrate_table_reference(array $reference)
    {
        $ids = array();
        foreach ((array) ($reference['uids'] ?? array()) as $uid) {
            if ($uid === null) {
                $ids[] = 0;
                continue;
            }
            $id = Fsync_Identity::local_id((string) ($reference['fsync_ref'] ?? ''), (string) $uid);
            if ($id <= 0) {
                return new WP_Error('fsync_reference_unresolved', sprintf('独自テーブル参照を解決できません: %s', $uid));
            }
            $ids[] = $id;
        }
        $shape = (string) ($reference['shape'] ?? 'scalar');

        return $shape === 'csv' ? implode(',', $ids) : ($shape === 'serialized_array' ? maybe_serialize($ids) : ($ids[0] ?? 0));
    }

    private static function table_select_sql($table, array $identity)
    {
        global $wpdb;
        $clauses = array();
        $values = array();
        foreach ($identity as $column => $value) {
            if ($value === null) {
                $clauses[] = '`' . $column . '` IS NULL';
            } else {
                $clauses[] = '`' . $column . '` = %s';
                $values[] = (string) $value;
            }
        }
        $sql = 'SELECT * FROM `' . $table . '` WHERE ' . implode(' AND ', $clauses) . ' LIMIT 2';

        return $values === array() ? $sql : $wpdb->prepare($sql, $values);
    }

    private static function apply_file(array $record)
    {
        $data = (array) $record['data'];
        $target = self::file_target($data);
        if (is_wp_error($target)) {
            return $target;
        }

        return Fsync_Store::materialize((string) $data['content_hash'], $target);
    }

    /** Stage multi-file plugins/themes; other file classes remain per-file atomic. */
    private static function stage_or_apply_file(array $record, $release_id)
    {
        $descriptor = self::code_descriptor((array) $record['data'], $release_id);
        if ($descriptor === null) {
            return self::apply_file($record);
        }
        if (is_wp_error($descriptor)) {
            return $descriptor;
        }
        $ready = self::ensure_code_stage($descriptor);
        if (is_wp_error($ready)) {
            return $ready;
        }
        $destination = Fsync_Fs::resolve($descriptor['stage'], $descriptor['relative']);
        if (is_wp_error($destination)) {
            return $destination;
        }

        return Fsync_Store::materialize((string) $record['data']['content_hash'], $destination);
    }

    /** Apply code-file deletions to the staged directory before the swap. */
    private static function stage_code_deletes(array $release, array $deletes)
    {
        $target_manifest = Fsync_Manifest::get((string) ($release['summary']['target_manifest_id'] ?? ''));
        if (is_wp_error($target_manifest)) {
            return $target_manifest;
        }
        foreach ($deletes as $item) {
            if ((string) $item['entity_kind'] !== 'file') {
                continue;
            }
            $manifest_item = $target_manifest['items'][$item['item_key']] ?? null;
            if (! is_array($manifest_item)) {
                continue;
            }
            $raw = Fsync_Store::get((string) $manifest_item['payload_hash']);
            $record = is_wp_error($raw) ? $raw : Fsync_Utils::decode($raw);
            if (is_wp_error($record)) {
                return $record;
            }
            $descriptor = self::code_descriptor((array) ($record['data'] ?? array()), $release['release_id']);
            if ($descriptor === null) {
                continue;
            }
            if (is_wp_error($descriptor)) {
                return $descriptor;
            }
            $ready = self::ensure_code_stage($descriptor);
            if (is_wp_error($ready)) {
                return $ready;
            }
            $path = Fsync_Fs::resolve($descriptor['stage'], $descriptor['relative']);
            if (is_wp_error($path)) {
                return $path;
            }
            if ((is_file($path) || is_link($path)) && ! @unlink($path)) {
                return new WP_Error('fsync_stage_delete_failed', sprintf('コード削除を準備できません: %s', $record['data']['path'] ?? ''));
            }
        }

        return true;
    }

    private static function code_descriptor(array $data, $release_id)
    {
        if (! Fsync_Utils::is_public_id((string) $release_id)) {
            return new WP_Error('fsync_release_id_invalid', 'コード検査用リリースIDが不正です。');
        }
        $category = (string) ($data['category'] ?? '');
        $path = Fsync_Utils::normalize_relative_path((string) ($data['path'] ?? ''));
        if (is_wp_error($path)) {
            return $path;
        }
        if ($category === 'plugins') {
            $parts = explode('/', $path);
            if (count($parts) < 2) {
                return null; // Single-file plugins are already file-atomic.
            }
            $group = array_shift($parts);
            $relative = implode('/', $parts);

            return array(
                'category' => 'plugins',
                'group' => $group,
                'relative' => $relative,
                'target' => WP_PLUGIN_DIR . '/' . $group,
                'stage' => Fsync_Fs::private_dir('code-stage/' . $release_id . '/plugins/' . $group),
                'backup' => Fsync_Fs::private_dir('code-backup/' . $release_id . '/plugins/' . $group),
            );
        }
        if ($category === 'mu-plugins') {
            $parts = explode('/', $path);
            if (count($parts) < 2 || ! defined('WPMU_PLUGIN_DIR')) {
                return null; // Flat mu-plugins are already file-atomic.
            }
            $group = array_shift($parts);

            return array(
                'category' => 'mu-plugins',
                'group' => $group,
                'relative' => implode('/', $parts),
                'target' => WPMU_PLUGIN_DIR . '/' . $group,
                'stage' => Fsync_Fs::private_dir('code-stage/' . $release_id . '/mu-plugins/' . $group),
                'backup' => Fsync_Fs::private_dir('code-backup/' . $release_id . '/mu-plugins/' . $group),
            );
        }
        if (strpos($category, 'theme/') === 0) {
            $group = Fsync_Utils::normalize_relative_path(substr($category, 6));
            if (is_wp_error($group) || strpos($group, '/') !== false) {
                return new WP_Error('fsync_theme_path_invalid', 'テーマパスが不正です。');
            }

            return array(
                'category' => 'themes',
                'group' => $group,
                'relative' => $path,
                'target' => get_theme_root() . '/' . $group,
                'stage' => Fsync_Fs::private_dir('code-stage/' . $release_id . '/themes/' . $group),
                'backup' => Fsync_Fs::private_dir('code-backup/' . $release_id . '/themes/' . $group),
            );
        }

        return null;
    }

    private static function ensure_code_stage(array $descriptor)
    {
        if (is_dir($descriptor['stage'])) {
            return true;
        }
        if (is_link($descriptor['target'])) {
            return new WP_Error('fsync_code_symlink_unsupported', sprintf('シンボリックリンクのコードディレクトリは切り替えできません: %s', $descriptor['group']));
        }
        $building = $descriptor['stage'] . '.building';
        Fsync_Fs::delete_private_tree($building);
        $copied = Fsync_Fs::copy_tree($descriptor['target'], $building);
        if (is_wp_error($copied)) {
            return $copied;
        }
        $parent = dirname($descriptor['stage']);
        if (! is_dir($parent)) {
            wp_mkdir_p($parent);
        }
        if (! is_dir($parent) || ! @rename($building, $descriptor['stage'])) {
            Fsync_Fs::delete_private_tree($building);

            return new WP_Error('fsync_code_stage_failed', sprintf('コード検査領域を確定できません: %s', $descriptor['group']));
        }

        return true;
    }

    /** Atomically swap each staged plugin/theme directory. */
    private static function commit_staged_code($release_id)
    {
        $committed = array();
        foreach (self::code_roots() as $category => $target_root) {
            $stage_root = Fsync_Fs::private_dir('code-stage/' . $release_id . '/' . $category);
            if (! is_dir($stage_root)) {
                continue;
            }
            foreach (array_diff((array) scandir($stage_root), array('.', '..')) as $group) {
                $stage = $stage_root . '/' . $group;
                if (! is_dir($stage) || is_link($stage)) {
                    continue;
                }
                $target = $target_root . '/' . $group;
                $backup = Fsync_Fs::private_dir('code-backup/' . $release_id . '/' . $category . '/' . $group);
                $backup_parent = dirname($backup);
                if (! is_dir($backup_parent)) {
                    wp_mkdir_p($backup_parent);
                }
                if (! is_dir($backup_parent)) {
                    return new WP_Error('fsync_code_backup_failed', 'コード切替用の退避領域を作成できません。');
                }
                $had_target = file_exists($target) || is_link($target);
                if ($had_target && ! @rename($target, $backup)) {
                    return new WP_Error('fsync_code_backup_failed', sprintf('現在のコードを退避できません: %s', $group));
                }
                $new_marker = '';
                if (! $had_target) {
                    $new_marker = Fsync_Fs::private_dir('code-new/' . $release_id . '/' . $category . '/' . $group . '.json');
                    $marked = Fsync_Fs::write_json($new_marker, array('category' => $category, 'group' => $group));
                    if (is_wp_error($marked)) {
                        return $marked;
                    }
                }
                if (! @rename($stage, $target)) {
                    if ($had_target) {
                        @rename($backup, $target);
                    } elseif ($new_marker !== '') {
                        @unlink($new_marker);
                    }
                    foreach (array_reverse($committed) as $done) {
                        @rename($done['target'], $done['stage']);
                        if ($done['had_target']) {
                            @rename($done['backup'], $done['target']);
                        } elseif ($done['new_marker'] !== '') {
                            @unlink($done['new_marker']);
                        }
                    }

                    return new WP_Error('fsync_code_switch_failed', sprintf('コードを原子的に切り替えられません: %s', $group));
                }
                $committed[] = compact('target', 'stage', 'backup', 'had_target', 'new_marker');
            }
        }

        return true;
    }

    /** Restore whole directories before row/file snapshot compensation. */
    public static function restore_code_backups($release_id)
    {
        if (! Fsync_Utils::is_public_id((string) $release_id)) {
            return true;
        }
        foreach (self::code_roots() as $category => $target_root) {
            $backup_root = Fsync_Fs::private_dir('code-backup/' . $release_id . '/' . $category);
            if (is_dir($backup_root)) {
                foreach (array_diff((array) scandir($backup_root), array('.', '..')) as $group) {
                    $backup = $backup_root . '/' . $group;
                    if (! is_dir($backup) || is_link($backup)) {
                        continue;
                    }
                    $target = $target_root . '/' . $group;
                    $discard = Fsync_Fs::private_dir('code-stage/' . $release_id . '/discard-' . $category . '-' . $group);
                    if (! is_dir(dirname($discard))) {
                        wp_mkdir_p(dirname($discard));
                    }
                    if ((file_exists($target) || is_link($target)) && ! @rename($target, $discard)) {
                        return new WP_Error('fsync_code_restore_failed', sprintf('失敗したコードを退避できません: %s', $group));
                    }
                    if (! @rename($backup, $target)) {
                        return new WP_Error('fsync_code_restore_failed', sprintf('元のコードへ戻せません: %s', $group));
                    }
                }
            }
            $new_root = Fsync_Fs::private_dir('code-new/' . $release_id . '/' . $category);
            if (! is_dir($new_root)) {
                continue;
            }
            foreach (array_diff((array) scandir($new_root), array('.', '..')) as $marker) {
                if (substr($marker, -5) !== '.json') {
                    continue;
                }
                $group = substr($marker, 0, -5);
                $safe = Fsync_Utils::normalize_relative_path($group);
                if (is_wp_error($safe) || strpos($safe, '/') !== false) {
                    return new WP_Error('fsync_code_restore_marker_invalid', 'コード復旧情報が不正です。');
                }
                $target = $target_root . '/' . $safe;
                $discard = Fsync_Fs::private_dir('code-stage/' . $release_id . '/discard-new-' . $category . '-' . $safe);
                if (! is_dir(dirname($discard))) {
                    wp_mkdir_p(dirname($discard));
                }
                if ((file_exists($target) || is_link($target)) && ! @rename($target, $discard)) {
                    return new WP_Error('fsync_code_restore_failed', sprintf('追加されたコードを退避できません: %s', $safe));
                }
            }
        }

        return true;
    }

    /** Remove transient code state; optionally retain rollback backups. */
    public static function cleanup_code_state($release_id, $delete_backups = true)
    {
        if (! Fsync_Utils::is_public_id((string) $release_id)) {
            return;
        }
        Fsync_Fs::delete_private_tree(Fsync_Fs::private_dir('code-stage/' . $release_id));
        if ($delete_backups) {
            Fsync_Fs::delete_private_tree(Fsync_Fs::private_dir('code-backup/' . $release_id));
            Fsync_Fs::delete_private_tree(Fsync_Fs::private_dir('code-new/' . $release_id));
        }
    }

    private static function code_roots()
    {
        $roots = array('plugins' => WP_PLUGIN_DIR, 'themes' => get_theme_root());
        if (defined('WPMU_PLUGIN_DIR')) {
            $roots['mu-plugins'] = WPMU_PLUGIN_DIR;
        }

        return $roots;
    }

    private static function apply_runtime(array $record, $restoring)
    {
        $data = (array) $record['data'];
        if ((string) ($data['wp_version'] ?? '') !== (string) get_bloginfo('version')) {
            return new WP_Error('fsync_wp_version_mismatch', 'WordPress本体のバージョンが一致しません。');
        }
        $incoming = array_values(array_filter((array) ($data['active_plugins'] ?? array()), static function ($plugin) {
            if ((string) $plugin === plugin_basename(FSYNC_FILE)) {
                return false;
            }
            $relative = Fsync_Utils::normalize_relative_path($plugin);

            return ! is_wp_error($relative) && is_file(WP_PLUGIN_DIR . '/' . $relative);
        }));
        $mode = (string) ($data['plugins_mode'] ?? 'all');
        $selected = array_values((array) ($data['managed_plugins'] ?? array()));
        $plugins = $mode === 'all' ? array() : array_values((array) get_option('active_plugins', array()));
        if ($mode === 'selected') {
            $plugins = array_values(array_filter($plugins, static function ($plugin) use ($selected) {
                return ! in_array(self::plugin_slug((string) $plugin), $selected, true);
            }));
        }
        if ($mode !== 'none') {
            $plugins = array_values(array_unique(array_merge($plugins, $incoming)));
        }
        if (! in_array(plugin_basename(FSYNC_FILE), $plugins, true)) {
            $plugins[] = plugin_basename(FSYNC_FILE);
        }
        update_option('active_plugins', $plugins, false);
        $stylesheet_raw = (string) ($data['stylesheet'] ?? '');
        $template_raw = (string) ($data['template'] ?? '');
        $stylesheet = $stylesheet_raw === '' ? '' : Fsync_Utils::normalize_relative_path($stylesheet_raw);
        $template = $template_raw === '' ? '' : Fsync_Utils::normalize_relative_path($template_raw);
        if (is_wp_error($stylesheet) || is_wp_error($template)
            || strpos((string) $stylesheet, '/') !== false || strpos((string) $template, '/') !== false) {
            return new WP_Error('fsync_theme_path_invalid', 'テーマの有効化パスが不正です。');
        }
        if ($stylesheet !== '' && is_dir(get_theme_root() . '/' . $stylesheet)) {
            if ($template !== '' && ! is_dir(get_theme_root() . '/' . $template)) {
                return new WP_Error('fsync_theme_missing', '親テーマが移行先に存在しません。');
            }
            update_option('stylesheet', $stylesheet, false);
            update_option('template', $template === '' ? $stylesheet : $template, false);
        }

        return true;
    }

    private static function plugin_slug($plugin)
    {
        $directory = dirname((string) $plugin);

        return $directory === '.' ? (string) $plugin : $directory;
    }

    private static function replace_meta($kind, $id, array $meta, array $preserve)
    {
        if ($kind === 'post') {
            $existing = get_post_meta($id);
            $delete = 'delete_post_meta';
            $add = 'add_post_meta';
        } elseif ($kind === 'term') {
            $existing = get_term_meta($id);
            $delete = 'delete_term_meta';
            $add = 'add_term_meta';
        } elseif ($kind === 'comment') {
            $existing = get_comment_meta($id);
            $delete = 'delete_comment_meta';
            $add = 'add_comment_meta';
        } else {
            $existing = get_user_meta($id);
            $delete = 'delete_user_meta';
            $add = 'add_user_meta';
        }
        foreach (array_keys((array) $existing) as $key) {
            if (! Fsync_Config::is_protected_meta($key) && ! in_array($key, $preserve, true)) {
                call_user_func($delete, $id, $key);
            }
        }
        foreach ($meta as $key => $values) {
            if (Fsync_Config::is_protected_meta($key)) {
                continue;
            }
            foreach ((array) $values as $value) {
                call_user_func($add, $id, $key, $value, false);
            }
        }
    }

    private static function references_in(array $meta)
    {
        foreach ($meta as $key => $values) {
            foreach ((array) $values as $index => $value) {
                if (! is_array($value) || ! isset($value['fsync_ref'], $value['uids'])) {
                    continue;
                }
                $ids = array();
                foreach ((array) $value['uids'] as $uid) {
                    if ($uid === null) {
                        $ids[] = 0;
                        continue;
                    }
                    $id = Fsync_Identity::local_id((string) $value['fsync_ref'], (string) $uid);
                    if ($id <= 0) {
                        return new WP_Error('fsync_reference_unresolved', sprintf('メタ参照を解決できません: %s', $uid));
                    }
                    $ids[] = $id;
                }
                $shape = (string) ($value['shape'] ?? 'scalar');
                $meta[$key][$index] = $shape === 'csv' ? implode(',', $ids) : ($shape === 'serialized_array' ? $ids : ($ids[0] ?? 0));
            }
        }

        return $meta;
    }

    private static function author_id($login)
    {
        if ((string) $login === '') {
            // WordPress-owned records such as global styles legitimately use
            // post_author = 0. Requiring a user mapping would make a complete
            // content profile impossible to apply on a fresh installation.
            return 0;
        }
        $authors = (array) (Fsync_Config::scope()['authors'] ?? array());
        $mapped = (string) (($authors['map'][$login] ?? '') ?: $login);
        $user = $mapped === '' ? null : get_user_by('login', $mapped);
        if (! $user && ! empty($authors['fallback'])) {
            $user = get_user_by('login', (string) $authors['fallback']);
        }
        if (! $user) {
            return new WP_Error('fsync_author_unresolved', sprintf('投稿者を割り当てられません: %s', $login));
        }

        return (int) $user->ID;
    }

    private static function file_target(array $data)
    {
        $category = (string) ($data['category'] ?? '');
        $path = (string) ($data['path'] ?? '');
        if ($category === 'uploads') {
            $uploads = wp_upload_dir(null, false);
            $root = (string) $uploads['basedir'];
        } elseif ($category === 'plugins') {
            $root = WP_PLUGIN_DIR;
        } elseif ($category === 'mu-plugins' && defined('WPMU_PLUGIN_DIR')) {
            $root = WPMU_PLUGIN_DIR;
        } elseif ($category === 'core') {
            $root = ABSPATH;
        } elseif (strpos($category, 'theme/') === 0) {
            $slug = substr($category, 6);
            $safe = Fsync_Utils::normalize_relative_path($slug);
            if (is_wp_error($safe) || strpos($safe, '/') !== false) {
                return new WP_Error('fsync_theme_path_invalid', 'テーマパスが不正です。');
            }
            $root = get_theme_root() . '/' . $safe;
        } else {
            return new WP_Error('fsync_file_category_invalid', 'ファイル種別が不正です。');
        }

        return Fsync_Fs::resolve($root, $path);
    }

    private static function delete_table_record(array $record)
    {
        global $wpdb;
        $data = (array) ($record['data'] ?? array());
        $name = (string) ($data['table'] ?? '');
        $identity = (array) ($data['identity'] ?? array());
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1
            || in_array($name, Fsync_Config::PROTECTED_TABLES, true)
            || $identity === array()) {
            return new WP_Error('fsync_table_identity_missing', '独自テーブルの削除キーがありません。');
        }
        $deleted = $wpdb->delete($wpdb->prefix . $name, $identity);

        return $deleted === false ? new WP_Error('fsync_table_delete_failed', sprintf('独自テーブル %s の行を削除できません。', $name)) : true;
    }

    private static function verify_applied_items(array $release, array $manifest)
    {
        foreach (Fsync_Release::items($release['release_id']) as $item) {
            if ($item['action'] === Fsync_Diff::ACTION_UNCHANGED || $item['action'] === Fsync_Diff::ACTION_BLOCKED
                || ($item['action'] === Fsync_Diff::ACTION_CONFLICT && in_array($item['resolution'], array('target', 'skip'), true))) {
                continue;
            }
            $actual = $manifest['items'][$item['item_key']]['hash'] ?? '';
            if ($item['action'] === Fsync_Diff::ACTION_DELETE) {
                if ($actual !== '') {
                    return new WP_Error('fsync_verify_delete_failed', sprintf('削除を検証できません: %s', $item['item_key']));
                }
            } elseif (! hash_equals((string) $item['source_hash'], (string) $actual)) {
                return new WP_Error('fsync_verify_hash_mismatch', sprintf('適用後ハッシュが一致しません: %s', $item['item_key']));
            }
        }

        return true;
    }

    private static function is_last_administrator($user_id)
    {
        $admins = get_users(array('role' => 'administrator', 'fields' => 'ID'));

        return in_array((int) $user_id, array_map('intval', (array) $admins), true) && count($admins) <= 1;
    }

    /** Cross-request lease used by resumable apply jobs. */
    private static function durable_lock($release_id, $job_id)
    {
        $now = Fsync_Utils::now();
        $existing = (array) get_option('fsync_apply_lock', array());
        if ($existing !== array()
            && (string) ($existing['job_id'] ?? '') !== (string) $job_id
            && (int) ($existing['expires'] ?? 0) > $now) {
            return new WP_Error('fsync_apply_locked', '別の移行処理が実行中です。', array('status' => 409));
        }
        $lease = array('release_id' => (string) $release_id, 'job_id' => (string) $job_id, 'expires' => $now + 300);
        if ($existing === array()) {
            if (! add_option('fsync_apply_lock', $lease, '', false)) {
                $existing = (array) get_option('fsync_apply_lock', array());
                if ((string) ($existing['job_id'] ?? '') !== (string) $job_id && (int) ($existing['expires'] ?? 0) > $now) {
                    return new WP_Error('fsync_apply_locked', '別の移行処理が実行中です。', array('status' => 409));
                }
            }
        }
        update_option('fsync_apply_lock', $lease, false);

        return true;
    }

    private static function durable_unlock($release_id, $job_id)
    {
        $existing = (array) get_option('fsync_apply_lock', array());
        if ($existing === array()
            || ((string) ($existing['release_id'] ?? '') === (string) $release_id
                && (string) ($existing['job_id'] ?? '') === (string) $job_id)) {
            delete_option('fsync_apply_lock');
        }
    }

    private static function lock($release_id)
    {
        global $wpdb;
        $name = self::LOCK_PREFIX . substr(hash('sha256', $release_id), 0, 24);
        if (Fsync_Env::supports_get_lock()) {
            $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));

            return (int) $acquired === 1 ? true : new WP_Error('fsync_apply_locked', '別の移行処理が実行中です。', array('status' => 409));
        }
        if (! add_option('fsync_apply_lock', array('release_id' => $release_id, 'expires' => Fsync_Utils::now() + 300), '', false)) {
            $existing = (array) get_option('fsync_apply_lock', array());
            if ((int) ($existing['expires'] ?? 0) > Fsync_Utils::now()) {
                return new WP_Error('fsync_apply_locked', '別の移行処理が実行中です。', array('status' => 409));
            }
            delete_option('fsync_apply_lock');
            if (! add_option('fsync_apply_lock', array('release_id' => $release_id, 'expires' => Fsync_Utils::now() + 300), '', false)) {
                return new WP_Error('fsync_apply_locked', '移行ロックを取得できません。', array('status' => 409));
            }
        }

        return true;
    }

    private static function unlock($release_id)
    {
        global $wpdb;
        $name = self::LOCK_PREFIX . substr(hash('sha256', $release_id), 0, 24);
        if (Fsync_Env::supports_get_lock()) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
        delete_option('fsync_apply_lock');
    }

    private static function maintenance($enabled)
    {
        $path = ABSPATH . '.maintenance';
        if ($enabled) {
            Fsync_Fs::write_atomic($path, "<?php \$upgrading = " . Fsync_Utils::now() . ";\n");
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }

    /** Restore a release after a fatal shutdown or an abandoned apply lease. */
    public static function recover_if_needed()
    {
        $guard = (array) get_option('fsync_runtime_guard', array());
        if ($guard === array()) {
            return true;
        }
        $failed = (array) get_option('fsync_runtime_guard_failed', array());
        if ($failed === array() && (int) ($guard['expires'] ?? 0) >= Fsync_Utils::now()) {
            return true;
        }
        $snapshot_id = (string) ($guard['snapshot_id'] ?? '');
        if (! Fsync_Utils::is_public_id($snapshot_id)) {
            return new WP_Error('fsync_runtime_guard_invalid', '自動復旧用スナップショットIDが不正です。');
        }
        $restored = Fsync_Snapshot::restore($snapshot_id);
        if (is_wp_error($restored)) {
            Fsync_Log::error('runtime_guard_restore_failed', $restored->get_error_message(), array('data' => array('snapshot_id' => $snapshot_id)));

            return $restored;
        }
        self::cleanup_code_state((string) ($guard['release_id'] ?? ''));
        if (Fsync_Utils::is_public_id((string) ($guard['job_id'] ?? ''))) {
            Fsync_Job::mark_recovered((string) $guard['job_id']);
        }
        self::maintenance(false);
        delete_option('fsync_apply_lock');
        delete_option('fsync_runtime_guard');
        delete_option('fsync_runtime_guard_failed');
        Fsync_Log::warning('runtime_guard_restored', '致命的エラーまたは中断された適用を自動復旧しました。', array('data' => array('snapshot_id' => $snapshot_id)));

        return true;
    }

    private static function refresh_runtime_guard()
    {
        $guard = (array) get_option('fsync_runtime_guard', array());
        if ($guard !== array()) {
            $guard['expires'] = Fsync_Utils::now() + 600;
            update_option('fsync_runtime_guard', $guard, false);
        }
    }

    private static function install_runtime_guard($snapshot_id, $release_id = '', $job_id = '')
    {
        if (! defined('WPMU_PLUGIN_DIR') || (string) $job_id === '') {
            return;
        }
        wp_mkdir_p(WPMU_PLUGIN_DIR);
        $state = array(
            'snapshot_id' => $snapshot_id,
            'release_id' => (string) $release_id,
            'job_id' => (string) $job_id,
            'active_plugins' => (array) get_option('active_plugins', array()),
            'stylesheet' => (string) get_option('stylesheet', ''),
            'template' => (string) get_option('template', ''),
            'expires' => Fsync_Utils::now() + 600,
        );
        update_option('fsync_runtime_guard', $state, false);
        $code = <<<'PHP'
<?php
if (! defined('ABSPATH')) { exit; }
$fsync_guard = get_option('fsync_runtime_guard', array());
if (! is_array($fsync_guard) || (int) ($fsync_guard['expires'] ?? 0) < time()) {
    return;
}
register_shutdown_function(static function () use ($fsync_guard) {
    $error = error_get_last();
    if (is_array($error) && in_array((int) $error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
        update_option('active_plugins', (array) ($fsync_guard['active_plugins'] ?? array()), false);
        update_option('stylesheet', (string) ($fsync_guard['stylesheet'] ?? ''), false);
        update_option('template', (string) ($fsync_guard['template'] ?? ''), false);
        update_option('fsync_runtime_guard_failed', array(
            'snapshot_id' => (string) ($fsync_guard['snapshot_id'] ?? ''),
            'error' => array(
                'type' => (int) ($error['type'] ?? 0),
                'file' => basename((string) ($error['file'] ?? '')),
                'line' => (int) ($error['line'] ?? 0),
            ),
            'failed_at' => time(),
        ), false);
    }
});
PHP;
        Fsync_Fs::write_atomic(WPMU_PLUGIN_DIR . '/fsync-guard.php', $code . "\n");
    }
}
