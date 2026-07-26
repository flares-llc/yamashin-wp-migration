<?php

if (! defined('ABSPATH')) {
    exit;
}

/** Durable, idempotent job state for bounded HTTP and cron execution. */
final class Fsync_Job
{
    const STATUS_QUEUED = 'queued';
    const STATUS_RUNNING = 'running';
    const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';
    const STATUS_COMPLETE = 'complete';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    public static function register_hooks()
    {
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        add_action('fsync_tick', [self::class, 'tick']);
        if (! wp_next_scheduled('fsync_tick')) {
            wp_schedule_event(time() + 60, 'fsync_5min', 'fsync_tick');
        }
    }

    public static function cron_schedules($schedules)
    {
        $schedules['fsync_5min'] = array('interval' => 300, 'display' => 'Yamashin WP Migration 5分');
        $schedules['fsync_15min'] = array('interval' => 900, 'display' => 'Yamashin WP Migration 15分');
        $schedules['fsync_6h'] = array('interval' => 21600, 'display' => 'Yamashin WP Migration 6時間');
        $schedules['fsync_weekly'] = array('interval' => 604800, 'display' => 'Yamashin WP Migration 週次');

        return $schedules;
    }

    /** @return array|WP_Error */
    public static function create($operation, array $payload = array(), $release_id = '')
    {
        global $wpdb;
        $allowed = array('push_release', 'pull_release', 'apply_release');
        if (! in_array($operation, $allowed, true)) {
            return new WP_Error('fsync_job_operation_invalid', 'ジョブ種別が不正です。');
        }
        $job_id = Fsync_Utils::random_hex(16);
        if (is_wp_error($job_id)) {
            return $job_id;
        }
        if ($operation === 'pull_release' && (string) $release_id === '') {
            $release_id = $job_id;
        }
        $default_stage = $operation === 'push_release' ? 'prepare' : ($operation === 'pull_release' ? 'request_pull' : 'apply');
        $payload['stage'] = (string) ($payload['stage'] ?? $default_stage);
        $encoded = Fsync_Utils::encode($payload);
        if (is_wp_error($encoded)) {
            return $encoded;
        }
        $now = Fsync_Utils::now();
        $saved = $wpdb->insert(
            Fsync_Schema::table('jobs'),
            array(
                'job_id' => $job_id,
                'release_id' => (string) $release_id,
                'operation' => $operation,
                'status' => self::STATUS_QUEUED,
                'phase' => $payload['stage'],
                'cursor_pos' => 0,
                'attempts' => 0,
                'progress' => 0,
                'total' => 0,
                'payload' => $encoded,
                'result' => '{}',
                'error' => '',
                'heartbeat_at' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            )
        );

        return $saved === false ? new WP_Error('fsync_job_save_failed', 'ジョブを保存できません。') : self::get($job_id);
    }

    /** Queue a peer-side push back to this site. Both directions must be paired. */
    public static function create_pull($peer_id, $profile, $idempotency_key)
    {
        global $wpdb;
        if (Fsync_Peer::find((string) $peer_id) === null) {
            return new WP_Error('fsync_peer_missing', '取得元の接続先が見つかりません。');
        }
        $profile = (string) $profile;
        if (! in_array($profile, array('content', 'full'), true)) {
            return new WP_Error('fsync_profile_invalid', '移行プロファイルが不正です。');
        }
        if (! Fsync_Utils::is_public_id((string) $idempotency_key)) {
            return new WP_Error('fsync_idempotency_key_required', 'pull開始には32桁hexのidempotency_keyが必要です。');
        }
        $idempotency_hash = hash('sha256', (string) $idempotency_key);
        $rows = $wpdb->get_results(
            "SELECT * FROM " . Fsync_Schema::table('jobs') . " WHERE operation = 'pull_release' ORDER BY created_at DESC",
            ARRAY_A
        );
        foreach ((array) $rows as $row) {
            $candidate = self::shape($row);
            if (! hash_equals((string) ($candidate['payload']['idempotency_hash'] ?? ''), $idempotency_hash)) {
                continue;
            }
            if ((string) ($candidate['payload']['peer_id'] ?? '') !== (string) $peer_id
                || (string) ($candidate['payload']['profile'] ?? '') !== $profile) {
                return new WP_Error('fsync_idempotency_conflict', '同じidempotency_keyが異なるpull条件に使用されています。', array('status' => 409));
            }

            return $candidate;
        }

        return self::create(
            'pull_release',
            array(
                'stage' => 'request_pull',
                'peer_id' => (string) $peer_id,
                'profile' => $profile,
                'idempotency_hash' => $idempotency_hash,
            )
        );
    }

    /** Queue a target-side apply without persisting the one-time confirmation in plaintext. */
    public static function queue_apply($release_id, $plan_hash, $confirmation, $idempotency_key)
    {
        global $wpdb;

        $release = Fsync_Release::get((string) $release_id);
        if (is_wp_error($release)) {
            return $release;
        }
        if ($release['status'] === Fsync_Release::STATUS_VERIFIED) {
            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT job_id FROM " . Fsync_Schema::table('jobs') . " WHERE release_id = %s AND operation = 'apply_release' AND status = 'complete' ORDER BY created_at DESC LIMIT 1",
                    $release_id
                )
            );

            return is_string($existing) && $existing !== '' ? self::get($existing) : array('release_id' => $release_id, 'status' => self::STATUS_COMPLETE);
        }
        $authorized = Fsync_Release::verify_confirmation($release, (string) $plan_hash, (string) $confirmation);
        if (is_wp_error($authorized)) {
            return $authorized;
        }
        $idempotency_hash = hash('sha256', (string) $idempotency_key);
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . Fsync_Schema::table('jobs') . " WHERE release_id = %s AND operation = 'apply_release' ORDER BY created_at DESC LIMIT 20",
                $release_id
            ),
            ARRAY_A
        );
        foreach ((array) $rows as $row) {
            $candidate = self::shape($row);
            if (hash_equals((string) ($candidate['payload']['idempotency_hash'] ?? ''), $idempotency_hash)) {
                return $candidate;
            }
            if (in_array($candidate['status'], array(self::STATUS_QUEUED, self::STATUS_RUNNING), true)) {
                return new WP_Error('fsync_apply_already_queued', 'このリリースの適用ジョブは既に実行中です。', array('status' => 409, 'job_id' => $candidate['job_id']));
            }
        }

        $job = self::create(
            'apply_release',
            array('stage' => 'apply', 'plan_hash' => (string) $plan_hash, 'idempotency_hash' => $idempotency_hash),
            (string) $release_id
        );
        if (is_wp_error($job)) {
            return $job;
        }
        $ciphertext = Fsync_Crypto::encrypt((string) $confirmation, 'job-confirmation', $job['job_id']);
        if (is_wp_error($ciphertext)) {
            self::cancel($job['job_id']);

            return $ciphertext;
        }
        $payload = $job['payload'];
        $payload['confirmation_ciphertext'] = $ciphertext;

        return self::save_payload($job['job_id'], $payload);
    }

    /** Execute a bounded step. Repeating the call is safe. */
    public static function run($job_id)
    {
        $job = self::get($job_id);
        if (is_wp_error($job)) {
            return $job;
        }
        if (in_array($job['status'], array(self::STATUS_COMPLETE, self::STATUS_CANCELLED, self::STATUS_AWAITING_CONFIRMATION), true)) {
            return $job;
        }
        $started = self::update($job_id, array('status' => self::STATUS_RUNNING, 'attempts' => $job['attempts'] + 1, 'heartbeat_at' => Fsync_Utils::now(), 'error' => ''));
        if (is_wp_error($started)) {
            return $started;
        }

        if ($job['operation'] === 'apply_release') {
            $payload = $job['payload'];
            $confirmation = Fsync_Crypto::decrypt(
                (string) ($payload['confirmation_ciphertext'] ?? ''),
                'job-confirmation',
                $job_id
            );
            if (is_wp_error($confirmation)) {
                return self::fail($job_id, $confirmation);
            }
            $step = Fsync_Apply::step($job, $confirmation);
            if (is_wp_error($step)) {
                return self::fail($job_id, $step);
            }
            if (empty($step['complete'])) {
                return self::save_payload(
                    $job_id,
                    (array) $step['payload'],
                    array(
                        'status' => self::STATUS_RUNNING,
                        'phase' => (string) $step['phase'],
                        'progress' => (int) $step['progress'],
                        'total' => (int) $step['total'],
                        'heartbeat_at' => Fsync_Utils::now(),
                    )
                );
            }

            return self::complete(
                $job_id,
                (array) $step['result'],
                array('progress' => (int) $step['progress'], 'total' => (int) $step['total'])
            );
        }

        return $job['operation'] === 'pull_release' ? self::run_pull($job) : self::run_push($job);
    }

    /** Explicitly continue from the reviewed remote plan into apply. */
    public static function confirm_remote_apply($job_id, $plan_hash)
    {
        $job = self::get($job_id);
        if (is_wp_error($job)) {
            return $job;
        }
        if ($job['status'] !== self::STATUS_AWAITING_CONFIRMATION
            || ! hash_equals((string) ($job['result']['plan_hash'] ?? ''), (string) $plan_hash)) {
            return new WP_Error('fsync_job_confirmation_invalid', '確認対象の差分計画が一致しません。', array('status' => 409));
        }
        foreach ((array) ($job['result']['items'] ?? array()) as $item) {
            if (($item['action'] ?? '') === Fsync_Diff::ACTION_CONFLICT && empty($item['resolution'])) {
                return new WP_Error('fsync_conflicts_unresolved', '未解決の競合があります。', array('status' => 409));
            }
            if (($item['action'] ?? '') === Fsync_Diff::ACTION_DELETE && ($item['resolution'] ?? '') !== 'source') {
                return new WP_Error('fsync_deletes_unconfirmed', '削除対象が明示確認されていません。', array('status' => 409));
            }
        }
        if ($job['operation'] === 'pull_release') {
            return self::confirm_pull_apply($job, (string) $plan_hash);
        }
        $peer = Fsync_Peer::find((string) $job['payload']['peer_id']);
        $client = $peer === null ? null : Fsync_Client::for_peer($peer);
        if ($client === null || is_wp_error($client)) {
            return $client === null ? new WP_Error('fsync_peer_missing', '接続先が見つかりません。') : $client;
        }
        $confirmation = Fsync_Crypto::decrypt(
            (string) ($job['payload']['confirmation_ciphertext'] ?? ''),
            'job-confirmation',
            $job_id
        );
        if (is_wp_error($confirmation)) {
            return $confirmation;
        }
        $result = $client->post(
            'migration/releases/' . $job['release_id'] . '/apply',
            array('plan_hash' => $plan_hash, 'confirmation' => $confirmation, 'idempotency_key' => $job_id),
            array(),
            120
        );
        if (is_wp_error($result)) {
            return self::is_retryable($result) ? $result : self::fail($job_id, $result);
        }
        $remote_job = (array) ($result['job'] ?? array());
        if ((string) ($remote_job['job_id'] ?? '') !== '') {
            $payload = $job['payload'];
            $payload['stage'] = 'remote_apply';
            $payload['remote_job_id'] = (string) $remote_job['job_id'];
            $saved = self::save_payload($job_id, $payload, array('status' => self::STATUS_RUNNING, 'phase' => 'remote_apply'));

            return is_wp_error($saved) ? self::fail($job_id, $saved) : $saved;
        }
        self::record_remote_verification($job, $result);

        return self::complete($job_id, $result);
    }

    /** Resolve item-level conflicts on the receiving site and rotate confirmation. */
    public static function resolve_remote($job_id, $plan_hash, array $resolutions)
    {
        return self::review_remote(
            $job_id,
            $plan_hash,
            'resolve',
            array('plan_hash' => (string) $plan_hash, 'resolutions' => $resolutions, 'idempotency_key' => (string) $job_id)
        );
    }

    /** Confirm the exact remote delete set independently from apply. */
    public static function confirm_remote_deletes($job_id, $plan_hash)
    {
        return self::review_remote(
            $job_id,
            $plan_hash,
            'confirm-deletes',
            array('plan_hash' => (string) $plan_hash, 'idempotency_key' => (string) $job_id, 'confirm' => true)
        );
    }

    public static function cancel($job_id)
    {
        $job = self::get($job_id);
        if (is_wp_error($job)) {
            return $job;
        }
        if (in_array($job['status'], array(self::STATUS_COMPLETE, self::STATUS_CANCELLED), true)) {
            return $job;
        }
        if ($job['operation'] === 'pull_release'
            && Fsync_Utils::is_public_id((string) ($job['payload']['remote_job_id'] ?? ''))) {
            $peer = Fsync_Peer::find((string) ($job['payload']['peer_id'] ?? ''));
            $client = $peer === null ? null : Fsync_Client::for_peer($peer);
            if ($client === null || is_wp_error($client)) {
                return $client === null ? new WP_Error('fsync_peer_missing', '取得元が見つかりません。') : $client;
            }
            $cancelled = $client->post(
                'migration/jobs/' . $job['payload']['remote_job_id'] . '/cancel',
                array('idempotency_key' => $job['job_id']),
                array(),
                120
            );
            if (is_wp_error($cancelled)) {
                return $cancelled;
            }
        }
        if ($job['operation'] === 'push_release'
            && (string) ($job['payload']['stage'] ?? '') === 'remote_apply'
            && Fsync_Utils::is_public_id((string) ($job['payload']['remote_job_id'] ?? ''))) {
            $peer = Fsync_Peer::find((string) ($job['payload']['peer_id'] ?? ''));
            $client = $peer === null ? null : Fsync_Client::for_peer($peer);
            if ($client === null || is_wp_error($client)) {
                return $client === null ? new WP_Error('fsync_peer_missing', '接続先が見つかりません。') : $client;
            }
            $cancelled = $client->post(
                'migration/jobs/' . $job['payload']['remote_job_id'] . '/cancel',
                array('idempotency_key' => $job['job_id']),
                array(),
                120
            );
            if (is_wp_error($cancelled)) {
                return $cancelled;
            }
        }
        if ($job['operation'] === 'apply_release' && (string) ($job['payload']['snapshot_id'] ?? '') !== '') {
            $aborted = Fsync_Apply::abort($job);
            if (is_wp_error($aborted)) {
                return $aborted;
            }
        }

        return self::update($job_id, array('status' => self::STATUS_CANCELLED, 'updated_at' => Fsync_Utils::now()));
    }

    public static function tick()
    {
        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT job_id FROM " . Fsync_Schema::table('jobs') . " WHERE status IN ('queued','running') ORDER BY created_at ASC LIMIT 3"
        );
        foreach ((array) $ids as $id) {
            self::run((string) $id);
        }
        Fsync_Snapshot::purge_expired();
    }

    public static function get($job_id)
    {
        global $wpdb;
        if (! Fsync_Utils::is_public_id($job_id)) {
            return new WP_Error('fsync_job_id_invalid', 'ジョブIDが不正です。');
        }
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . Fsync_Schema::table('jobs') . ' WHERE job_id = %s', $job_id),
            ARRAY_A
        );

        return $row === null ? new WP_Error('fsync_job_missing', 'ジョブが見つかりません。', array('status' => 404)) : self::shape($row);
    }

    public static function all($limit = 50)
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . Fsync_Schema::table('jobs') . ' ORDER BY created_at DESC LIMIT %d', max(1, min(200, (int) $limit))),
            ARRAY_A
        );

        return array_map([self::class, 'shape'], (array) $rows);
    }

    /** Return the newest durable job for an idempotently recreated release. */
    public static function find_latest($release_id, $operation)
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Fsync_Schema::table('jobs') . ' WHERE release_id = %s AND operation = %s ORDER BY created_at DESC LIMIT 1',
                (string) $release_id,
                (string) $operation
            ),
            ARRAY_A
        );

        return $row === null ? null : self::shape($row);
    }

    /** Mark a job whose fatal/expired runtime guard restored its snapshot. */
    public static function mark_recovered($job_id)
    {
        $job = self::get((string) $job_id);
        if (is_wp_error($job)) {
            return $job;
        }

        return self::update(
            $job['job_id'],
            array(
                'status' => self::STATUS_FAILED,
                'phase' => 'auto_rollback',
                'error' => '致命的エラーまたは期限切れを検知し、スナップショットを自動復元しました。',
                'updated_at' => Fsync_Utils::now(),
            )
        );
    }

    private static function run_push(array $job)
    {
        $payload = $job['payload'];

        $release = Fsync_Release::get($job['release_id']);
        if (is_wp_error($release)) {
            return self::fail($job['job_id'], $release);
        }
        $peer = Fsync_Peer::find((string) ($payload['peer_id'] ?? $release['peer_id']));
        $client = $peer === null ? null : Fsync_Client::for_peer($peer);
        if ($client === null || is_wp_error($client)) {
            return self::fail($job['job_id'], $client === null ? new WP_Error('fsync_peer_missing', '接続先が見つかりません。') : $client);
        }

        if (($payload['stage'] ?? '') === 'remote_apply') {
            $remote_job_id = (string) ($payload['remote_job_id'] ?? '');
            if (! Fsync_Utils::is_public_id($remote_job_id)) {
                return self::fail($job['job_id'], new WP_Error('fsync_remote_job_missing', '接続先の適用ジョブIDが失われています。'));
            }
            $response = $client->post(
                'migration/jobs/' . $remote_job_id . '/continue',
                array('idempotency_key' => substr(hash('sha256', $job['job_id'] . ':' . ($payload['stage'] ?? '') . ':' . ($payload['cursor'] ?? 0)), 0, 32)),
                array(),
                120
            );
            if (is_wp_error($response)) {
                return self::retry_or_fail($job, $payload, $response);
            }
            $remote_job = (array) ($response['job'] ?? array());
            $remote_status = (string) ($remote_job['status'] ?? '');
            if ($remote_status === self::STATUS_FAILED) {
                return self::fail($job['job_id'], new WP_Error('fsync_remote_apply_failed', (string) ($remote_job['error'] ?? '接続先の適用ジョブに失敗しました。')));
            }
            if ($remote_status === self::STATUS_CANCELLED) {
                return self::update($job['job_id'], array('status' => self::STATUS_CANCELLED, 'phase' => 'cancelled'));
            }
            if ($remote_status === self::STATUS_COMPLETE) {
                $remote_result = (array) ($remote_job['result'] ?? array());
                self::record_remote_verification($job, $remote_result);

                return self::complete(
                    $job['job_id'],
                    $remote_result,
                    array('progress' => (int) ($remote_job['progress'] ?? 0), 'total' => (int) ($remote_job['total'] ?? 0))
                );
            }

            // The remote progress is the idempotency boundary for the next
            // continue call. A successful bounded step also proves that a
            // previous transport/lock failure has recovered, so its retry
            // counter must not poison a later independent failure.
            $payload['cursor'] = (int) ($remote_job['progress'] ?? 0);
            unset($payload['retry_key'], $payload['retry_count']);

            return self::save_payload(
                $job['job_id'],
                $payload,
                array(
                    'status' => self::STATUS_RUNNING,
                    'phase' => 'remote_apply',
                    'progress' => (int) ($remote_job['progress'] ?? 0),
                    'total' => (int) ($remote_job['total'] ?? 0),
                    'heartbeat_at' => Fsync_Utils::now(),
                )
            );
        }

        if ($payload['stage'] === 'prepare') {
            $manifest = Fsync_Manifest::get($release['manifest_id']);
            if (is_wp_error($manifest)) {
                return self::fail($job['job_id'], $manifest);
            }
            $response = $client->post(
                'migration/releases/prepare',
                array(
                    'release_id' => $release['release_id'],
                    'manifest' => $manifest,
                    'environment' => (array) ($release['summary']['source'] ?? array()),
                ),
                array(),
                120
            );
            if (is_wp_error($response)) {
                return self::retry_or_fail($job, $payload, $response);
            }
            $missing = array_values((array) ($response['missing_objects'] ?? array()));
            $payload['stage'] = $missing === array() ? 'finalize' : 'upload';
            $payload['missing'] = $missing;
            $payload['object_index'] = 0;
            $payload['offset'] = 0;
            $payload['chunk_bytes'] = max(
                262144,
                min(Fsync_Store::MAX_CHUNK_BYTES, (int) ($response['suggested_chunk_bytes'] ?? Fsync_Env::suggested_chunk_bytes()))
            );
            $saved = self::save_payload($job['job_id'], $payload, array('phase' => $payload['stage'], 'total' => count($missing), 'progress' => 0));
            if (is_wp_error($saved)) {
                return self::fail($job['job_id'], $saved);
            }
            $job = self::get($job['job_id']);
        }

        if ($payload['stage'] === 'upload') {
            $index = (int) $payload['object_index'];
            $hash = (string) ($payload['missing'][$index] ?? '');
            if ($hash === '') {
                $payload['stage'] = 'finalize';
                $saved = self::save_payload($job['job_id'], $payload, array('phase' => 'finalize'));
                if (is_wp_error($saved)) {
                    return self::fail($job['job_id'], $saved);
                }
            } else {
                $path = Fsync_Store::path($hash);
                if (is_wp_error($path) || ! Fsync_Store::verify($hash)) {
                    return self::fail($job['job_id'], new WP_Error('fsync_object_missing', sprintf('送信オブジェクトがありません: %s', $hash)));
                }
                $offset = (int) $payload['offset'];
                $chunk_bytes = max(262144, min(Fsync_Store::MAX_CHUNK_BYTES, (int) ($payload['chunk_bytes'] ?? Fsync_Env::suggested_chunk_bytes())));
                if ($offset === 0 && (int) filesize($path) <= 131072) {
                    $batch = array();
                    $batch_bytes = 0;
                    $raw_budget = max(131072, (int) floor($chunk_bytes * 0.70));
                    for ($candidate_index = $index; $candidate_index < count($payload['missing']) && count($batch) < 100; $candidate_index++) {
                        $candidate_hash = (string) $payload['missing'][$candidate_index];
                        $candidate_path = Fsync_Store::path($candidate_hash);
                        if (is_wp_error($candidate_path) || ! Fsync_Store::verify($candidate_hash)) {
                            return self::fail($job['job_id'], new WP_Error('fsync_object_missing', sprintf('送信オブジェクトがありません: %s', $candidate_hash)));
                        }
                        $size = (int) filesize($candidate_path);
                        if ($size > 131072 || ($batch !== array() && $batch_bytes + $size > $raw_budget)) {
                            break;
                        }
                        $bytes = file_get_contents($candidate_path);
                        if ($bytes === false) {
                            return self::fail($job['job_id'], new WP_Error('fsync_object_read_failed', '送信オブジェクトを読み取れません。'));
                        }
                        $batch[] = array('hash' => $candidate_hash, 'total' => $size, 'data' => base64_encode($bytes));
                        $batch_bytes += $size;
                    }
                    $response = $client->post('migration/objects/batch', array('objects' => $batch), array(), 120);
                    if (is_wp_error($response)) {
                        return self::retry_or_fail($job, $payload, $response);
                    }
                    $payload['object_index'] = $index + count($batch);
                    $payload['offset'] = 0;
                    if ($payload['object_index'] >= count($payload['missing'])) {
                        $payload['stage'] = 'finalize';
                    }
                    $saved = self::save_payload(
                        $job['job_id'],
                        $payload,
                        array('phase' => $payload['stage'], 'progress' => (int) $payload['object_index'])
                    );
                    if (is_wp_error($saved)) {
                        return self::fail($job['job_id'], $saved);
                    }

                    return self::get($job['job_id']);
                }
                $handle = fopen($path, 'rb');
                fseek($handle, $offset);
                $chunk = fread($handle, $chunk_bytes);
                fclose($handle);
                if ($chunk === false) {
                    return self::fail($job['job_id'], new WP_Error('fsync_object_read_failed', '送信オブジェクトを読み取れません。'));
                }
                $response = $client->post(
                    'migration/objects/' . $hash,
                    array('offset' => $offset, 'total' => (int) filesize($path), 'data' => base64_encode($chunk)),
                    array(),
                    120
                );
                if (is_wp_error($response)) {
                    if ($response->get_error_code() === 'fsync_chunk_offset_mismatch') {
                        $expected = (int) (($response->get_error_data()['expected_offset'] ?? -1));
                        if ($expected >= 0 && $expected <= (int) filesize($path)) {
                            $payload['offset'] = $expected;

                            return self::save_payload(
                                $job['job_id'],
                                $payload,
                                array('status' => self::STATUS_RUNNING, 'phase' => 'upload', 'error' => '')
                            );
                        }
                    }

                    return self::retry_or_fail($job, $payload, $response);
                }
                if (! empty($response['complete'])) {
                    $payload['object_index'] = $index + 1;
                    $payload['offset'] = 0;
                } else {
                    $payload['offset'] = (int) ($response['offset'] ?? ($offset + strlen($chunk)));
                }
                if ($payload['object_index'] >= count($payload['missing'])) {
                    $payload['stage'] = 'finalize';
                }
                $saved = self::save_payload(
                    $job['job_id'],
                    $payload,
                    array('phase' => $payload['stage'], 'progress' => (int) $payload['object_index'])
                );
                if (is_wp_error($saved)) {
                    return self::fail($job['job_id'], $saved);
                }

                return self::get($job['job_id']);
            }
        }

        if ($payload['stage'] === 'finalize') {
            $response = $client->post(
                'migration/releases/' . $release['release_id'] . '/dry-run',
                array('idempotency_key' => $job['job_id']),
                array(),
                120
            );
            if (is_wp_error($response)) {
                return self::retry_or_fail($job, $payload, $response);
            }
            $remote = (array) ($response['release'] ?? $response);
            $confirmation = (string) ($remote['confirmation'] ?? $response['confirmation'] ?? '');
            if ($confirmation === '') {
                return self::fail($job['job_id'], new WP_Error('fsync_remote_confirmation_missing', '接続先が適用確認を返しませんでした。'));
            }
            unset($remote['confirmation']);
            $ciphertext = Fsync_Crypto::encrypt($confirmation, 'job-confirmation', $job['job_id']);
            if (is_wp_error($ciphertext)) {
                return self::fail($job['job_id'], $ciphertext);
            }
            $payload['confirmation_ciphertext'] = $ciphertext;
            $payload['stage'] = 'awaiting_confirmation';
            $review = array(
                'release_id' => $release['release_id'],
                'plan_hash' => (string) ($remote['plan_hash'] ?? ''),
                'status' => (string) ($remote['status'] ?? ''),
                'summary' => (array) ($remote['summary'] ?? array()),
                'items' => array_values((array) ($response['items'] ?? array())),
            );
            unset($payload['review']);
            $encoded_payload = Fsync_Utils::encode($payload);
            $encoded_review = Fsync_Utils::encode($review);
            if (is_wp_error($encoded_payload) || is_wp_error($encoded_review)) {
                return self::fail($job['job_id'], new WP_Error('fsync_job_encode_failed', '確認待ちジョブを保存できません。'));
            }

            return self::update(
                $job['job_id'],
                array(
                    'status' => self::STATUS_AWAITING_CONFIRMATION,
                    'phase' => 'awaiting_confirmation',
                    'payload' => $encoded_payload,
                    'result' => $encoded_review,
                )
            );
        }

        return self::get($job['job_id']);
    }

    /** Proxy a pull by asking the peer to run its normal push pipeline back to this site. */
    private static function run_pull(array $job)
    {
        $payload = $job['payload'];
        $peer = Fsync_Peer::find((string) ($payload['peer_id'] ?? ''));
        $client = $peer === null ? null : Fsync_Client::for_peer($peer);
        if ($client === null || is_wp_error($client)) {
            return self::fail($job['job_id'], $client === null ? new WP_Error('fsync_peer_missing', '取得元が見つかりません。') : $client);
        }

        if (($payload['stage'] ?? '') === 'request_pull') {
            $response = $client->post(
                'migration/releases',
                array(
                    'profile' => (string) ($payload['profile'] ?? 'full'),
                    'direction' => 'push',
                    'start_push' => true,
                    'idempotency_key' => $job['job_id'],
                ),
                array(),
                120
            );
            if (is_wp_error($response)) {
                return self::retry_or_fail($job, $payload, $response);
            }
            $remote_job = (array) ($response['job'] ?? array());
            $remote_job_id = (string) ($remote_job['job_id'] ?? '');
            if (! Fsync_Utils::is_public_id($remote_job_id)) {
                return self::fail($job['job_id'], new WP_Error('fsync_pull_pairing_required', '取得元からこのサイトへの接続がありません。両方向をペアリングしてからpullしてください。'));
            }
            $payload['remote_job_id'] = $remote_job_id;
            $payload['remote_release_id'] = (string) (($response['release']['release_id'] ?? '') ?: ($remote_job['release_id'] ?? ''));
            $payload['stage'] = 'remote_transfer';
            $payload['cursor'] = 0;
            unset($payload['retry_key'], $payload['retry_count']);

            return self::save_payload(
                $job['job_id'],
                $payload,
                array('status' => self::STATUS_RUNNING, 'phase' => 'remote_transfer', 'progress' => 0, 'total' => 0, 'heartbeat_at' => Fsync_Utils::now())
            );
        }

        $remote_job_id = (string) ($payload['remote_job_id'] ?? '');
        if (! Fsync_Utils::is_public_id($remote_job_id)) {
            return self::fail($job['job_id'], new WP_Error('fsync_remote_job_missing', '取得元のpullジョブIDが失われています。'));
        }
        $response = $client->post(
            'migration/jobs/' . $remote_job_id . '/continue',
            array('idempotency_key' => substr(hash('sha256', $job['job_id'] . ':' . ($payload['stage'] ?? '') . ':' . ($payload['cursor'] ?? 0)), 0, 32)),
            array(),
            120
        );
        if (is_wp_error($response)) {
            return self::retry_or_fail($job, $payload, $response);
        }

        return self::sync_pull_job($job, (array) ($response['job'] ?? array()));
    }

    /** Mirror the authoritative peer-side push job into the local pull proxy. */
    private static function sync_pull_job(array $job, array $remote_job)
    {
        $status = (string) ($remote_job['status'] ?? '');
        if ($status === self::STATUS_FAILED) {
            return self::fail($job['job_id'], new WP_Error('fsync_remote_pull_failed', (string) ($remote_job['error'] ?? '取得元のpullジョブに失敗しました。')));
        }
        if ($status === self::STATUS_CANCELLED) {
            return self::update($job['job_id'], array('status' => self::STATUS_CANCELLED, 'phase' => 'cancelled'));
        }
        if ($status === self::STATUS_COMPLETE) {
            return self::complete(
                $job['job_id'],
                (array) ($remote_job['result'] ?? array()),
                array('progress' => (int) ($remote_job['progress'] ?? 0), 'total' => (int) ($remote_job['total'] ?? 0))
            );
        }
        if (! in_array($status, array(self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_AWAITING_CONFIRMATION), true)) {
            return self::fail($job['job_id'], new WP_Error('fsync_remote_job_invalid', '取得元が不正なジョブ状態を返しました。'));
        }

        $payload = $job['payload'];
        $payload['cursor'] = (int) ($remote_job['progress'] ?? 0);
        $payload['stage'] = $status === self::STATUS_AWAITING_CONFIRMATION ? 'awaiting_confirmation' : (string) ($payload['stage'] ?? 'remote_transfer');
        unset($payload['retry_key'], $payload['retry_count']);
        $patch = array(
            'status' => $status === self::STATUS_AWAITING_CONFIRMATION ? self::STATUS_AWAITING_CONFIRMATION : self::STATUS_RUNNING,
            'phase' => $status === self::STATUS_AWAITING_CONFIRMATION ? 'awaiting_confirmation' : $payload['stage'],
            'progress' => (int) ($remote_job['progress'] ?? 0),
            'total' => (int) ($remote_job['total'] ?? 0),
            'heartbeat_at' => Fsync_Utils::now(),
        );
        if ($status === self::STATUS_AWAITING_CONFIRMATION) {
            $encoded = Fsync_Utils::encode((array) ($remote_job['result'] ?? array()));
            if (is_wp_error($encoded)) {
                return self::fail($job['job_id'], $encoded);
            }
            $patch['result'] = $encoded;
        }

        return self::save_payload($job['job_id'], $payload, $patch);
    }

    /** Continue the peer-side push from review into application on this site. */
    private static function confirm_pull_apply(array $job, $plan_hash)
    {
        $peer = Fsync_Peer::find((string) ($job['payload']['peer_id'] ?? ''));
        $client = $peer === null ? null : Fsync_Client::for_peer($peer);
        if ($client === null || is_wp_error($client)) {
            return $client === null ? new WP_Error('fsync_peer_missing', '取得元が見つかりません。') : $client;
        }
        $remote_job_id = (string) ($job['payload']['remote_job_id'] ?? '');
        if (! Fsync_Utils::is_public_id($remote_job_id)) {
            return new WP_Error('fsync_remote_job_missing', '取得元のpullジョブIDが失われています。');
        }
        $response = $client->post(
            'migration/jobs/' . $remote_job_id . '/confirm',
            array('plan_hash' => (string) $plan_hash, 'idempotency_key' => $job['job_id'], 'confirm' => true),
            array(),
            120
        );
        if (is_wp_error($response)) {
            return $response;
        }
        $remote_job = (array) ($response['job'] ?? array());
        if ((string) ($remote_job['status'] ?? '') === self::STATUS_COMPLETE) {
            return self::sync_pull_job($job, $remote_job);
        }
        $payload = $job['payload'];
        $payload['stage'] = 'remote_apply';
        unset($payload['retry_key'], $payload['retry_count']);

        return self::save_payload($job['job_id'], $payload, array('status' => self::STATUS_RUNNING, 'phase' => 'remote_apply'));
    }

    private static function review_remote($job_id, $plan_hash, $action, array $body)
    {
        $job = self::get($job_id);
        if (is_wp_error($job)) {
            return $job;
        }
        if ($job['status'] !== self::STATUS_AWAITING_CONFIRMATION
            || ! hash_equals((string) ($job['result']['plan_hash'] ?? ''), (string) $plan_hash)) {
            return new WP_Error('fsync_job_confirmation_invalid', '確認対象の差分計画が一致しません。', array('status' => 409));
        }
        if ($job['operation'] === 'pull_release') {
            $peer = Fsync_Peer::find((string) ($job['payload']['peer_id'] ?? ''));
            $client = $peer === null ? null : Fsync_Client::for_peer($peer);
            if ($client === null || is_wp_error($client)) {
                return $client === null ? new WP_Error('fsync_peer_missing', '取得元が見つかりません。') : $client;
            }
            $remote_job_id = (string) ($job['payload']['remote_job_id'] ?? '');
            if (! Fsync_Utils::is_public_id($remote_job_id)) {
                return new WP_Error('fsync_remote_job_missing', '取得元のpullジョブIDが失われています。');
            }
            $response = $client->post(
                'migration/jobs/' . $remote_job_id . '/' . $action,
                $body,
                array(),
                120
            );
            if (is_wp_error($response)) {
                return $response;
            }

            return self::sync_pull_job($job, (array) ($response['job'] ?? array()));
        }
        $peer = Fsync_Peer::find((string) ($job['payload']['peer_id'] ?? ''));
        $client = $peer === null ? null : Fsync_Client::for_peer($peer);
        if ($client === null || is_wp_error($client)) {
            return $client === null ? new WP_Error('fsync_peer_missing', '接続先が見つかりません。') : $client;
        }
        $response = $client->post(
            'migration/releases/' . $job['release_id'] . '/' . $action,
            $body,
            array(),
            120
        );
        if (is_wp_error($response)) {
            return $response;
        }
        $remote = (array) ($response['release'] ?? array());
        $confirmation = (string) ($response['confirmation'] ?? '');
        if ($confirmation === '' || (string) ($remote['plan_hash'] ?? '') === '') {
            return new WP_Error('fsync_remote_confirmation_missing', '接続先が更新後の適用確認を返しませんでした。');
        }
        $ciphertext = Fsync_Crypto::encrypt($confirmation, 'job-confirmation', $job_id);
        if (is_wp_error($ciphertext)) {
            return $ciphertext;
        }
        $review = array(
            'release_id' => $job['release_id'],
            'plan_hash' => (string) $remote['plan_hash'],
            'status' => (string) ($remote['status'] ?? ''),
            'summary' => (array) ($remote['summary'] ?? array()),
            'items' => array_values((array) ($response['items'] ?? array())),
        );
        $payload = $job['payload'];
        $payload['stage'] = 'awaiting_confirmation';
        $payload['confirmation_ciphertext'] = $ciphertext;
        unset($payload['review']);
        $encoded_payload = Fsync_Utils::encode($payload);
        $encoded = Fsync_Utils::encode($review);
        if (is_wp_error($encoded_payload) || is_wp_error($encoded)) {
            return new WP_Error('fsync_job_encode_failed', '確認待ちジョブを保存できません。');
        }

        return self::update(
            $job_id,
            array(
                'status' => self::STATUS_AWAITING_CONFIRMATION,
                'phase' => 'awaiting_confirmation',
                'payload' => $encoded_payload,
                'result' => $encoded,
            )
        );
    }

    private static function save_payload($job_id, array $payload, array $patch = array())
    {
        $encoded = Fsync_Utils::encode($payload);
        if (is_wp_error($encoded)) {
            return $encoded;
        }
        $patch['payload'] = $encoded;
        $patch['updated_at'] = Fsync_Utils::now();

        return self::update($job_id, $patch);
    }

    private static function complete($job_id, array $result, array $patch = array())
    {
        $encoded = Fsync_Utils::encode($result);
        if (is_wp_error($encoded)) {
            return self::fail($job_id, $encoded);
        }
        self::update(
            $job_id,
            array_merge($patch, array('status' => self::STATUS_COMPLETE, 'phase' => 'complete', 'result' => $encoded, 'updated_at' => Fsync_Utils::now()))
        );

        return self::get($job_id);
    }

    private static function record_remote_verification(array $job, array $response)
    {
        $result = isset($response['result']) && is_array($response['result']) ? $response['result'] : $response;
        if ((string) ($result['status'] ?? '') !== Fsync_Release::STATUS_VERIFIED) {
            return;
        }
        $peer = Fsync_Peer::find((string) ($job['payload']['peer_id'] ?? ''));
        Fsync_Release::record_remote_verification(
            $job['release_id'],
            $peer === null ? '' : (string) $peer['env_name'],
            $result
        );
    }

    private static function fail($job_id, WP_Error $error)
    {
        self::update(
            $job_id,
            array(
                'status' => self::STATUS_FAILED,
                'error' => $error->get_error_message(),
                'updated_at' => Fsync_Utils::now(),
            )
        );

        return $error;
    }

    /** Keep retryable transport failures resumable without hiding hard errors. */
    private static function retry_or_fail(array $job, array $payload, WP_Error $error)
    {
        if (! self::is_retryable($error)) {
            return self::fail($job['job_id'], $error);
        }
        $retry_key = implode(':', array(
            (string) ($payload['stage'] ?? ''),
            (int) ($payload['object_index'] ?? 0),
            (int) ($payload['offset'] ?? 0),
        ));
        $count = (string) ($payload['retry_key'] ?? '') === $retry_key
            ? (int) ($payload['retry_count'] ?? 0) + 1
            : 1;
        if ($count > 5) {
            return self::fail($job['job_id'], new WP_Error('fsync_retry_exhausted', '通信の自動再試行が5回失敗しました。接続を確認してからジョブを再作成してください。'));
        }
        $payload['retry_key'] = $retry_key;
        $payload['retry_count'] = $count;

        return self::save_payload(
            $job['job_id'],
            $payload,
            array(
                'status' => self::STATUS_QUEUED,
                'error' => $error->get_error_message(),
                'heartbeat_at' => Fsync_Utils::now(),
            )
        );
    }

    private static function is_retryable(WP_Error $error)
    {
        $data = $error->get_error_data();

        return is_array($data) && ! empty($data['retryable']);
    }

    private static function update($job_id, array $fields)
    {
        global $wpdb;
        foreach (array('job_id', 'release_id', 'operation', 'created_at') as $immutable) {
            unset($fields[$immutable]);
        }
        $updated = $wpdb->update(Fsync_Schema::table('jobs'), $fields, array('job_id' => $job_id));

        return $updated === false ? new WP_Error('fsync_job_update_failed', 'ジョブを更新できません。') : self::get($job_id);
    }

    private static function shape(array $row)
    {
        $payload = json_decode((string) $row['payload'], true);
        $result = json_decode((string) $row['result'], true);

        return array(
            'job_id' => (string) $row['job_id'],
            'release_id' => (string) $row['release_id'],
            'operation' => (string) $row['operation'],
            'status' => (string) $row['status'],
            'phase' => (string) $row['phase'],
            'cursor' => (int) $row['cursor_pos'],
            'attempts' => (int) $row['attempts'],
            'progress' => (int) $row['progress'],
            'total' => (int) $row['total'],
            'payload' => is_array($payload) ? $payload : array(),
            'result' => is_array($result) ? $result : array(),
            'error' => (string) $row['error'],
            'heartbeat_at' => (int) $row['heartbeat_at'],
            'created_at' => (int) $row['created_at'],
            'updated_at' => (int) $row['updated_at'],
        );
    }
}
