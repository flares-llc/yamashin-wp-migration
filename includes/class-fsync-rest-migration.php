<?php

if (! defined('ABSPATH')) {
    exit;
}

/** REST transport for releases, objects, jobs, receipts and snapshots. */
final class Fsync_Rest_Migration
{
    public static function register_routes()
    {
        self::route('/migration/releases', WP_REST_Server::READABLE, 'list_releases', 'read');
        self::route('/migration/releases', WP_REST_Server::CREATABLE, 'create_release', 'write');
        self::route('/migration/releases/(?P<release_id>[a-f0-9]{32})', WP_REST_Server::READABLE, 'get_release', 'read');
        self::route('/migration/releases/prepare', WP_REST_Server::CREATABLE, 'prepare_release', 'write');
        self::route('/migration/releases/(?P<release_id>[a-f0-9]{32})/dry-run', WP_REST_Server::CREATABLE, 'dry_run', 'write');
        self::route('/migration/releases/(?P<release_id>[a-f0-9]{32})/resolve', WP_REST_Server::CREATABLE, 'resolve', 'write');
        self::route('/migration/releases/(?P<release_id>[a-f0-9]{32})/confirm-deletes', WP_REST_Server::CREATABLE, 'confirm_deletes', 'write');
        self::route('/migration/releases/(?P<release_id>[a-f0-9]{32})/apply', WP_REST_Server::CREATABLE, 'apply', 'write');
        self::route('/migration/manifests/(?P<manifest_id>[a-f0-9]{32})', WP_REST_Server::READABLE, 'manifest', 'read');
        self::route('/migration/manifests/(?P<manifest_id>[a-f0-9]{32})/buckets/(?P<bucket>[a-f0-9]{2})', WP_REST_Server::READABLE, 'bucket', 'read');
        self::route('/migration/objects/(?P<object_hash>[a-f0-9]{64})', WP_REST_Server::CREATABLE, 'object_chunk', 'files');
        self::route('/migration/objects/batch', WP_REST_Server::CREATABLE, 'object_batch', 'files');
        self::route('/migration/jobs', WP_REST_Server::READABLE, 'list_jobs', 'read');
        self::route('/migration/jobs/(?P<job_id>[a-f0-9]{32})', WP_REST_Server::READABLE, 'get_job', 'read');
        self::route('/migration/jobs/(?P<job_id>[a-f0-9]{32})/continue', WP_REST_Server::CREATABLE, 'continue_job', 'write');
        self::route('/migration/jobs/(?P<job_id>[a-f0-9]{32})/resolve', WP_REST_Server::CREATABLE, 'resolve_job', 'write');
        self::route('/migration/jobs/(?P<job_id>[a-f0-9]{32})/confirm-deletes', WP_REST_Server::CREATABLE, 'confirm_job_deletes', 'write');
        self::route('/migration/jobs/(?P<job_id>[a-f0-9]{32})/confirm', WP_REST_Server::CREATABLE, 'confirm_job', 'write');
        self::route('/migration/jobs/(?P<job_id>[a-f0-9]{32})/cancel', WP_REST_Server::CREATABLE, 'cancel_job', 'write');
        self::route('/migration/snapshots', WP_REST_Server::READABLE, 'snapshots', 'read');
        self::route('/migration/snapshots/(?P<snapshot_id>[a-f0-9]{32})/rollback', WP_REST_Server::CREATABLE, 'rollback', 'restore');
        self::route('/migration/receipts', WP_REST_Server::READABLE, 'receipts', 'read');
    }

    private static function route($path, $methods, $callback, $capability)
    {
        register_rest_route(
            FSYNC_REST_NAMESPACE,
            $path,
            array(
                'methods' => $methods,
                'callback' => [self::class, $callback],
                'permission_callback' => Fsync_Rest::admin_or_capability($capability),
            )
        );
    }

    public static function list_releases($request)
    {
        return Fsync_Rest::respond(array('ok' => true, 'releases' => array_map([self::class, 'public_release'], Fsync_Release::all())));
    }

    public static function create_release($request)
    {
        $body = self::body($request);
        $idempotent = self::require_idempotency_key($body);
        if (is_wp_error($idempotent)) {
            return Fsync_Rest::error($idempotent);
        }
        $peer_id = self::peer_id($body);
        if (is_wp_error($peer_id)) {
            return Fsync_Rest::error($peer_id);
        }
        $profile = (string) ($body['profile'] ?? 'full');
        $direction = (string) ($body['direction'] ?? 'push');
        if (! in_array($direction, array('push', 'pull'), true)) {
            return Fsync_Rest::error(new WP_Error('fsync_direction_invalid', 'directionはpushまたはpullです。', array('status' => 400)));
        }
        if ($direction === 'pull') {
            $job = Fsync_Job::create_pull($peer_id, $profile, (string) $idempotent);

            return is_wp_error($job)
                ? Fsync_Rest::error($job)
                : Fsync_Rest::respond(array('ok' => true, 'release' => null, 'job' => self::public_job($job)), 202);
        }
        $created = Fsync_Release::create($peer_id, $profile, 'push', (string) $idempotent);
        if (is_wp_error($created)) {
            return Fsync_Rest::error($created);
        }
        $job = null;
        if (($body['start_push'] ?? true) !== false) {
            $job = ! empty($created['idempotent'])
                ? Fsync_Job::find_latest($created['release']['release_id'], 'push_release')
                : null;
            if ($job === null) {
                $job = Fsync_Job::create('push_release', array('peer_id' => $peer_id), $created['release']['release_id']);
            }
            if (is_wp_error($job)) {
                return Fsync_Rest::error($job);
            }
        }

        return Fsync_Rest::respond(
            array(
                'ok' => true,
                'release' => self::public_release($created['release']),
                'job' => $job === null ? null : self::public_job($job),
            ),
            202
        );
    }

    public static function get_release($request)
    {
        $release = Fsync_Release::get((string) $request['release_id']);
        if (is_wp_error($release)) {
            return Fsync_Rest::error($release);
        }

        return Fsync_Rest::respond(array('ok' => true, 'release' => self::public_release($release), 'items' => Fsync_Release::items($release['release_id'])));
    }

    public static function prepare_release($request)
    {
        $body = self::body($request);
        $target_environment = Fsync_Config::environment(Fsync_Config_Io::active_env());
        $current_key = Fsync_Auth::current_key();
        if ((array) ($target_environment['requires_verified_on'] ?? array()) !== array()
            && $current_key !== null
            && ! Fsync_Keys::can($current_key, 'promote')) {
            return Fsync_Rest::error(new WP_Error('fsync_capability_missing', 'この環境への昇格にはpromote権限が必要です。', array('status' => 403)));
        }
        $peer_id = self::peer_id($body);
        if (is_wp_error($peer_id)) {
            return Fsync_Rest::error($peer_id);
        }
        $prepared = Fsync_Release::prepare(
            (string) ($body['release_id'] ?? ''),
            (array) ($body['manifest'] ?? array()),
            (array) ($body['environment'] ?? array()),
            $peer_id
        );
        if (is_wp_error($prepared)) {
            return Fsync_Rest::error($prepared);
        }
        $prepared['release'] = self::public_release($prepared['release']);

        return Fsync_Rest::respond(array_merge(array('ok' => true), $prepared), 202);
    }

    public static function dry_run($request)
    {
        $body = self::body($request);
        $idempotent = self::require_idempotency_key($body);
        if (is_wp_error($idempotent)) {
            return Fsync_Rest::error($idempotent);
        }
        $release = Fsync_Release::finalize_dry_run((string) $request['release_id']);
        if (is_wp_error($release)) {
            return Fsync_Rest::error($release);
        }
        $confirmation = (string) ($release['confirmation'] ?? '');
        unset($release['confirmation']);
        $response = Fsync_Rest::respond(
            array('ok' => true, 'release' => self::public_release($release), 'items' => self::public_items($release['release_id']), 'confirmation' => $confirmation),
            200
        );
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    public static function resolve($request)
    {
        $body = self::body($request);
        $idempotent = self::require_idempotency_key($body);
        if (is_wp_error($idempotent)) {
            return Fsync_Rest::error($idempotent);
        }
        $release = Fsync_Release::resolve(
            (string) $request['release_id'],
            (string) ($body['plan_hash'] ?? ''),
            (array) ($body['resolutions'] ?? array())
        );
        if (is_wp_error($release)) {
            return Fsync_Rest::error($release);
        }
        $confirmation = (string) ($release['confirmation'] ?? '');
        unset($release['confirmation']);
        $response = Fsync_Rest::respond(array('ok' => true, 'release' => self::public_release($release), 'items' => self::public_items($release['release_id']), 'confirmation' => $confirmation));
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    public static function confirm_deletes($request)
    {
        $body = self::body($request);
        $idempotent = self::require_idempotency_key($body);
        if (is_wp_error($idempotent)) {
            return Fsync_Rest::error($idempotent);
        }
        if (($body['confirm'] ?? false) !== true) {
            return Fsync_Rest::error(new WP_Error('fsync_confirmation_required', '削除対象の確定にはconfirm=trueが必要です。', array('status' => 409)));
        }
        $release = Fsync_Release::confirm_deletes((string) $request['release_id'], (string) ($body['plan_hash'] ?? ''));
        if (is_wp_error($release)) {
            return Fsync_Rest::error($release);
        }
        $confirmation = (string) ($release['confirmation'] ?? '');
        unset($release['confirmation']);
        $response = Fsync_Rest::respond(array('ok' => true, 'release' => self::public_release($release), 'items' => self::public_items($release['release_id']), 'confirmation' => $confirmation));
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    public static function apply($request)
    {
        $body = self::body($request);
        $idempotent = self::require_idempotency_key($body);
        if (is_wp_error($idempotent)) {
            return Fsync_Rest::error($idempotent);
        }
        $release = Fsync_Release::get((string) $request['release_id']);
        if (is_wp_error($release)) {
            return Fsync_Rest::error($release);
        }
        if ($release['status'] === Fsync_Release::STATUS_VERIFIED) {
            return Fsync_Rest::respond(array('ok' => true, 'release' => self::public_release($release), 'idempotent' => true));
        }
        $job = Fsync_Job::queue_apply(
            $release['release_id'],
            (string) ($body['plan_hash'] ?? ''),
            (string) ($body['confirmation'] ?? ''),
            (string) $idempotent
        );

        return is_wp_error($job)
            ? Fsync_Rest::error($job)
            : Fsync_Rest::respond(array('ok' => true, 'job' => self::public_job($job)), 202);
    }

    public static function manifest($request)
    {
        $manifest = Fsync_Manifest::get((string) $request['manifest_id']);

        return is_wp_error($manifest) ? Fsync_Rest::error($manifest) : Fsync_Rest::respond(array('ok' => true, 'manifest' => $manifest));
    }

    public static function bucket($request)
    {
        $manifest = Fsync_Manifest::get((string) $request['manifest_id']);
        if (is_wp_error($manifest)) {
            return Fsync_Rest::error($manifest);
        }

        return Fsync_Rest::respond(
            array(
                'ok' => true,
                'manifest_id' => $manifest['manifest_id'],
                'bucket' => (string) $request['bucket'],
                'hash' => (string) ($manifest['bucket_hashes'][$request['bucket']] ?? ''),
                'items' => Fsync_Manifest::bucket($manifest, (string) $request['bucket']),
            )
        );
    }

    public static function object_chunk($request)
    {
        $body = self::body($request);
        $result = Fsync_Store::put_chunk(
            (string) $request['object_hash'],
            (int) ($body['offset'] ?? -1),
            (int) ($body['total'] ?? -1),
            (string) ($body['data'] ?? '')
        );

        return is_wp_error($result) ? Fsync_Rest::error($result) : Fsync_Rest::respond(array_merge(array('ok' => true), $result));
    }

    /** Receive up to 100 complete small CAS objects in one signed request. */
    public static function object_batch($request)
    {
        $objects = array_values((array) (self::body($request)['objects'] ?? array()));
        if ($objects === array() || count($objects) > 100) {
            return Fsync_Rest::error(new WP_Error('fsync_object_batch_invalid', 'オブジェクトバッチは1〜100件で指定してください。'));
        }
        $completed = array();
        foreach ($objects as $object) {
            $hash = (string) ($object['hash'] ?? '');
            $total = (int) ($object['total'] ?? -1);
            $result = Fsync_Store::put_chunk($hash, 0, $total, (string) ($object['data'] ?? ''));
            if (is_wp_error($result)) {
                return Fsync_Rest::error($result);
            }
            if (empty($result['complete'])) {
                return Fsync_Rest::error(new WP_Error('fsync_object_batch_incomplete', '小オブジェクトを確定できませんでした。'));
            }
            $completed[] = $hash;
        }

        return Fsync_Rest::respond(array('ok' => true, 'completed' => $completed));
    }

    public static function list_jobs($request)
    {
        return Fsync_Rest::respond(array('ok' => true, 'jobs' => array_map([self::class, 'public_job'], Fsync_Job::all())));
    }

    public static function get_job($request)
    {
        $job = Fsync_Job::get((string) $request['job_id']);

        return is_wp_error($job) ? Fsync_Rest::error($job) : Fsync_Rest::respond(array('ok' => true, 'job' => self::public_job($job)));
    }

    public static function continue_job($request)
    {
        $body = self::body($request);
        $idempotent = self::require_idempotency_key($body);
        if (is_wp_error($idempotent)) {
            return Fsync_Rest::error($idempotent);
        }
        $job = Fsync_Job::run((string) $request['job_id']);

        return is_wp_error($job) ? Fsync_Rest::error($job) : Fsync_Rest::respond(array('ok' => true, 'job' => self::public_job($job)), 202);
    }

    public static function confirm_job($request)
    {
        $body = self::body($request);
        $idempotent = self::require_idempotency_key($body);
        if (is_wp_error($idempotent)) {
            return Fsync_Rest::error($idempotent);
        }
        if (($body['confirm'] ?? false) !== true) {
            return Fsync_Rest::error(new WP_Error('fsync_confirmation_required', '接続先への適用にはconfirm=trueが必要です。', array('status' => 409)));
        }
        $job = Fsync_Job::confirm_remote_apply((string) $request['job_id'], (string) ($body['plan_hash'] ?? ''));

        return is_wp_error($job) ? Fsync_Rest::error($job) : Fsync_Rest::respond(array('ok' => true, 'job' => self::public_job($job)));
    }

    public static function resolve_job($request)
    {
        $body = self::body($request);
        $idempotent = self::require_idempotency_key($body);
        if (is_wp_error($idempotent)) {
            return Fsync_Rest::error($idempotent);
        }
        $job = Fsync_Job::resolve_remote(
            (string) $request['job_id'],
            (string) ($body['plan_hash'] ?? ''),
            (array) ($body['resolutions'] ?? array())
        );

        return is_wp_error($job) ? Fsync_Rest::error($job) : Fsync_Rest::respond(array('ok' => true, 'job' => self::public_job($job)));
    }

    public static function confirm_job_deletes($request)
    {
        $body = self::body($request);
        $idempotent = self::require_idempotency_key($body);
        if (is_wp_error($idempotent)) {
            return Fsync_Rest::error($idempotent);
        }
        if (($body['confirm'] ?? false) !== true) {
            return Fsync_Rest::error(new WP_Error('fsync_confirmation_required', '接続先の削除対象確定にはconfirm=trueが必要です。', array('status' => 409)));
        }
        $job = Fsync_Job::confirm_remote_deletes((string) $request['job_id'], (string) ($body['plan_hash'] ?? ''));

        return is_wp_error($job) ? Fsync_Rest::error($job) : Fsync_Rest::respond(array('ok' => true, 'job' => self::public_job($job)));
    }

    public static function cancel_job($request)
    {
        $body = self::body($request);
        $idempotent = self::require_idempotency_key($body);
        if (is_wp_error($idempotent)) {
            return Fsync_Rest::error($idempotent);
        }
        $job = Fsync_Job::cancel((string) $request['job_id']);

        return is_wp_error($job) ? Fsync_Rest::error($job) : Fsync_Rest::respond(array('ok' => true, 'job' => self::public_job($job)));
    }

    public static function snapshots($request)
    {
        return Fsync_Rest::respond(array('ok' => true, 'snapshots' => Fsync_Snapshot::all()));
    }

    public static function rollback($request)
    {
        $body = self::body($request);
        $idempotent = self::require_idempotency_key($body);
        if (is_wp_error($idempotent)) {
            return Fsync_Rest::error($idempotent);
        }
        if (($body['confirm'] ?? false) !== true) {
            return Fsync_Rest::error(new WP_Error('fsync_confirmation_required', 'ロールバックにはconfirm=trueが必要です。', array('status' => 409)));
        }
        $authorized = Fsync_Snapshot::authorize_rollback((string) $request['snapshot_id'], (string) ($body['plan_hash'] ?? ''));
        $result = is_wp_error($authorized) ? $authorized : Fsync_Snapshot::restore((string) $request['snapshot_id']);

        return is_wp_error($result) ? Fsync_Rest::error($result) : Fsync_Rest::respond(array('ok' => true, 'result' => $result));
    }

    public static function receipts($request)
    {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . Fsync_Schema::table('receipts') . ' ORDER BY applied_at DESC LIMIT 100', ARRAY_A);
        foreach ((array) $rows as &$row) {
            $data = json_decode((string) $row['data'], true);
            $row['data'] = is_array($data) ? array_diff_key($data, array('baseline' => true)) : array();
        }

        return Fsync_Rest::respond(array('ok' => true, 'receipts' => (array) $rows));
    }

    public static function public_release(array $release)
    {
        unset($release['confirmation_hash']);
        if (isset($release['summary']['required_objects'])) {
            $release['summary']['required_object_count'] = count((array) $release['summary']['required_objects']);
            unset($release['summary']['required_objects']);
        }

        return $release;
    }

    public static function public_job(array $job)
    {
        unset($job['payload']['confirmation_ciphertext']);

        return $job;
    }

    public static function public_items($release_id)
    {
        return array_map(static function ($item) {
            return array_intersect_key(
                $item,
                array_flip(array('item_key', 'entity_kind', 'entity_uid', 'action', 'resolution', 'status', 'error'))
            );
        }, Fsync_Release::items((string) $release_id));
    }

    private static function body($request)
    {
        $body = $request->get_json_params();

        return is_array($body) ? $body : array();
    }

    private static function peer_id(array $body)
    {
        $key = Fsync_Auth::current_key();
        if ($key !== null && $key['peer_id'] !== '') {
            return $key['peer_id'];
        }
        $peer_id = (string) ($body['peer_id'] ?? '');

        return preg_match('/^[a-f0-9]{16,32}$/', $peer_id) === 1
            ? $peer_id
            : new WP_Error('fsync_peer_missing', '送信元ピアを特定できません。');
    }

    private static function require_idempotency_key(array $body)
    {
        $key = (string) ($body['idempotency_key'] ?? '');

        return Fsync_Utils::is_public_id($key)
            ? $key
            : new WP_Error('fsync_idempotency_key_required', '更新操作には32桁のidempotency_keyが必要です。', array('status' => 400));
    }
}
