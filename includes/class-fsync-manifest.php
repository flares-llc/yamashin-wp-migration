<?php

if (! defined('ABSPATH')) {
    exit;
}

/** Build and persist compact Merkle manifests from portable records. */
final class Fsync_Manifest
{
    const BUCKETS = 256;

    /**
     * @param string $profile
     * @param string $peer_env
     * @return array|WP_Error
     */
    public static function build($profile = 'full', $peer_env = '')
    {
        global $wpdb;

        $items = array();
        $buckets = array_fill(0, self::BUCKETS, array());
        $total_bytes = 0;
        $scanned = Fsync_Portable::scan($profile, $peer_env, static function ($key, $record) use (&$items, &$buckets, &$total_bytes) {
            $encoded = Fsync_Utils::encode($record);
            if (is_wp_error($encoded)) {
                return $encoded;
            }
            $payload_hash = Fsync_Store::put($encoded);
            if (is_wp_error($payload_hash)) {
                return $payload_hash;
            }
            $hash = Fsync_Utils::canonical_hash($record);
            if (is_wp_error($hash)) {
                return $hash;
            }
            $bucket = hexdec(substr(hash('sha256', $key), 0, 2));
            $items[$key] = array(
                'key' => $key,
                'kind' => $record['kind'],
                'uid' => $record['uid'],
                'identity_key' => Fsync_Portable::identity_key($record),
                'hash' => $hash,
                'payload_hash' => $payload_hash,
                'has_relationships' => Fsync_Portable::has_relationships($record),
                'objects' => array_values(array_unique(array_merge(array($payload_hash), (array) $record['objects']))),
                'bytes' => strlen($encoded) + self::object_sizes((array) $record['objects']),
                'bucket' => $bucket,
            );
            $buckets[$bucket][$key] = $hash;
            $total_bytes += (int) $items[$key]['bytes'];
            return true;
        });
        if (is_wp_error($scanned)) {
            return $scanned;
        }
        ksort($items, SORT_STRING);

        $bucket_hashes = array();
        foreach ($buckets as $index => $bucket_items) {
            ksort($bucket_items, SORT_STRING);
            $bucket_hashes[sprintf('%02x', $index)] = hash('sha256', (string) wp_json_encode($bucket_items, Fsync_Utils::JSON_FLAGS));
        }
        $root_hash = Fsync_Utils::canonical_hash($bucket_hashes);
        if (is_wp_error($root_hash)) {
            return $root_hash;
        }
        $manifest_id = Fsync_Utils::random_hex(16);
        if (is_wp_error($manifest_id)) {
            return $manifest_id;
        }
        $scope_fingerprint = Fsync_Config::scope_fingerprint($peer_env);
        if (is_wp_error($scope_fingerprint)) {
            return $scope_fingerprint;
        }

        $manifest = array(
            'format_version' => 1,
            'manifest_id' => $manifest_id,
            'profile' => $profile,
            'peer_env' => $peer_env,
            'scope_fingerprint' => $scope_fingerprint,
            'root_hash' => $root_hash,
            'bucket_hashes' => $bucket_hashes,
            'items' => $items,
            'item_count' => count($items),
            'total_bytes' => $total_bytes,
            'created_at' => Fsync_Utils::now_iso(),
        );

        $path = Fsync_Fs::private_dir('releases/manifests/' . $manifest_id . '.json');
        $existing_row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT peer_id, root_hash FROM ' . Fsync_Schema::table('manifests') . ' WHERE manifest_id = %s',
                $manifest_id
            ),
            ARRAY_A
        );
        if (is_array($existing_row)) {
            return new WP_Error('fsync_manifest_id_collision', '同じmanifest_idに異なる内容が既に保存されています。', array('status' => 409));
        }
        $written = Fsync_Fs::write_json($path, $manifest);
        if (is_wp_error($written)) {
            return $written;
        }
        $saved = $wpdb->insert(
            Fsync_Schema::table('manifests'),
            array(
                'manifest_id' => $manifest_id,
                'scope_fingerprint' => $scope_fingerprint,
                'root_hash' => $root_hash,
                'item_count' => count($items),
                'total_bytes' => $total_bytes,
                'path' => self::relative_path($path),
                'created_at' => Fsync_Utils::now(),
            )
        );
        if ($saved === false) {
            @unlink($path);

            return new WP_Error('fsync_manifest_save_failed', 'マニフェストを保存できません。');
        }

        return $manifest;
    }

    /** @return array|WP_Error */
    public static function get($manifest_id)
    {
        global $wpdb;

        if (! Fsync_Utils::is_public_id($manifest_id)) {
            return new WP_Error('fsync_manifest_id_invalid', 'マニフェストIDが不正です。');
        }
        $path = $wpdb->get_var(
            $wpdb->prepare('SELECT path FROM ' . Fsync_Schema::table('manifests') . ' WHERE manifest_id = %s', $manifest_id)
        );
        if (! is_string($path) || $path === '') {
            return new WP_Error('fsync_manifest_missing', 'マニフェストが見つかりません。', array('status' => 404));
        }
        $absolute = Fsync_Fs::resolve(Fsync_Fs::private_dir(), $path);
        if (is_wp_error($absolute)) {
            return $absolute;
        }

        return Fsync_Fs::read_json($absolute);
    }

    /**
     * Persist a peer-supplied manifest after recomputing every aggregate hash.
     * Payload bytes are transferred separately through the object endpoint.
     *
     * @param array $manifest
     * @param string $peer_id
     * @return array|WP_Error
     */
    public static function accept(array $manifest, $peer_id)
    {
        global $wpdb;

        $top_keys = array('format_version', 'manifest_id', 'profile', 'peer_env', 'scope_fingerprint', 'root_hash', 'bucket_hashes', 'items', 'item_count', 'total_bytes', 'created_at');
        if (array_diff(array_keys($manifest), $top_keys) !== array()
            || array_diff($top_keys, array_keys($manifest)) !== array()) {
            return new WP_Error('fsync_manifest_invalid', '受信マニフェストのフィールドが不正です。');
        }
        $manifest_id = (string) ($manifest['manifest_id'] ?? '');
        if (! Fsync_Utils::is_public_id($manifest_id)
            || (int) ($manifest['format_version'] ?? 0) !== 1
            || ! Fsync_Utils::is_sha256($manifest['root_hash'] ?? '')) {
            return new WP_Error('fsync_manifest_invalid', '受信マニフェストの形式が不正です。');
        }
        if (! in_array((string) ($manifest['profile'] ?? ''), array('content', 'full'), true)
            || ! Fsync_Utils::is_sha256((string) ($manifest['scope_fingerprint'] ?? ''))
            || count((array) ($manifest['items'] ?? array())) > 1000000) {
            return new WP_Error('fsync_manifest_invalid', '受信マニフェストのprofile、scope、または項目数が不正です。');
        }
        $items = (array) ($manifest['items'] ?? array());
        $buckets = array_fill(0, self::BUCKETS, array());
        $total_bytes = 0;
        foreach ($items as $key => $item) {
            $item_keys = array('key', 'kind', 'uid', 'identity_key', 'hash', 'payload_hash', 'has_relationships', 'objects', 'bytes', 'bucket');
            if (! is_array($item)
                || array_diff(array_keys($item), $item_keys) !== array()
                || array_diff($item_keys, array_keys($item)) !== array()
                || (string) ($item['key'] ?? '') !== (string) $key
                || ! in_array((string) ($item['kind'] ?? ''), array('post', 'term', 'comment', 'user', 'option', 'table', 'file', 'runtime'), true)
                || (string) $key !== (string) ($item['kind'] ?? '') . ':' . (string) ($item['uid'] ?? '')
                || preg_match('/^[A-Za-z0-9._:-]{8,191}$/', (string) ($item['uid'] ?? '')) !== 1
                || ((string) ($item['identity_key'] ?? '') !== '' && ! Fsync_Utils::is_sha256((string) $item['identity_key']))
                || ! Fsync_Utils::is_sha256($item['hash'] ?? '')
                || ! Fsync_Utils::is_sha256($item['payload_hash'] ?? '')
                || ! is_bool($item['has_relationships'] ?? null)
                || ! is_int($item['bytes'] ?? null)
                || (int) $item['bytes'] < 0
                || (int) $item['bytes'] > PHP_INT_MAX) {
                return new WP_Error('fsync_manifest_item_invalid', sprintf('マニフェスト項目が不正です: %s', $key));
            }
            $objects = (array) ($item['objects'] ?? array());
            if (! Fsync_Utils::is_list($objects)
                || count($objects) > 100000
                || ! in_array((string) $item['payload_hash'], $objects, true)
                || count($objects) !== count(array_unique($objects))) {
                return new WP_Error('fsync_manifest_object_invalid', sprintf('オブジェクト一覧が不正です: %s', $key));
            }
            foreach ($objects as $hash) {
                if (! Fsync_Utils::is_sha256($hash)) {
                    return new WP_Error('fsync_manifest_object_invalid', sprintf('オブジェクトIDが不正です: %s', $key));
                }
            }
            $bucket = hexdec(substr(hash('sha256', (string) $key), 0, 2));
            if ((int) ($item['bucket'] ?? -1) !== $bucket) {
                return new WP_Error('fsync_manifest_bucket_invalid', sprintf('バケット番号が一致しません: %s', $key));
            }
            $buckets[$bucket][$key] = (string) $item['hash'];
            $total_bytes += (int) $item['bytes'];
            if ($total_bytes < 0) {
                return new WP_Error('fsync_manifest_size_invalid', 'マニフェストの合計サイズが不正です。');
            }
        }
        $bucket_hashes = array();
        foreach ($buckets as $index => $bucket_items) {
            ksort($bucket_items, SORT_STRING);
            $bucket_hashes[sprintf('%02x', $index)] = hash('sha256', (string) wp_json_encode($bucket_items, Fsync_Utils::JSON_FLAGS));
        }
        $root = Fsync_Utils::canonical_hash($bucket_hashes);
        if (is_wp_error($root) || ! hash_equals((string) $manifest['root_hash'], (string) $root)) {
            return new WP_Error('fsync_manifest_root_mismatch', 'マニフェストのMerkleルートが一致しません。');
        }
        if ((int) $manifest['item_count'] !== count($items)
            || (int) $manifest['total_bytes'] !== $total_bytes
            || (array) $manifest['bucket_hashes'] !== $bucket_hashes) {
            return new WP_Error('fsync_manifest_aggregate_mismatch', 'マニフェストの集計値またはMerkleバケットが一致しません。');
        }
        $manifest['bucket_hashes'] = $bucket_hashes;
        $manifest['item_count'] = count($items);
        $manifest['total_bytes'] = $total_bytes;

        $path = Fsync_Fs::private_dir('releases/manifests/' . $manifest_id . '.json');
        $existing_row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT peer_id, root_hash FROM ' . Fsync_Schema::table('manifests') . ' WHERE manifest_id = %s',
                $manifest_id
            ),
            ARRAY_A
        );
        if (is_array($existing_row)
            && (! hash_equals((string) $existing_row['root_hash'], (string) $root)
                || ((string) $existing_row['peer_id'] !== '' && ! hash_equals((string) $existing_row['peer_id'], substr((string) $peer_id, 0, 32))))) {
            return new WP_Error('fsync_manifest_id_collision', '同じmanifest_idに異なる内容が既に保存されています。', array('status' => 409));
        }
        $written = Fsync_Fs::write_json($path, $manifest);
        if (is_wp_error($written)) {
            return $written;
        }
        $encoded_path = self::relative_path($path);
        $existing = is_array($existing_row) ? $manifest_id : '';
        $row = array(
            'peer_id' => substr((string) $peer_id, 0, 32),
            'scope_fingerprint' => substr((string) ($manifest['scope_fingerprint'] ?? ''), 0, 64),
            'root_hash' => (string) $root,
            'item_count' => count($items),
            'total_bytes' => $total_bytes,
            'path' => $encoded_path,
            'created_at' => Fsync_Utils::now(),
        );
        $saved = $existing
            ? $wpdb->update(Fsync_Schema::table('manifests'), $row, array('manifest_id' => $manifest_id))
            : $wpdb->insert(Fsync_Schema::table('manifests'), array_merge(array('manifest_id' => $manifest_id), $row));
        if ($saved === false) {
            return new WP_Error('fsync_manifest_save_failed', '受信マニフェストを保存できません。');
        }

        return $manifest;
    }

    /** @return array */
    public static function bucket(array $manifest, $bucket)
    {
        $bucket = strtolower((string) $bucket);

        return array_filter((array) ($manifest['items'] ?? array()), static function ($item) use ($bucket) {
            return sprintf('%02x', (int) ($item['bucket'] ?? -1)) === $bucket;
        });
    }

    private static function object_sizes(array $hashes)
    {
        $total = 0;
        foreach ($hashes as $hash) {
            $path = Fsync_Store::path($hash);
            if (! is_wp_error($path) && is_file($path)) {
                $total += (int) filesize($path);
            }
        }

        return $total;
    }

    private static function relative_path($path)
    {
        return ltrim(substr(str_replace('\\', '/', $path), strlen(str_replace('\\', '/', Fsync_Fs::private_dir()))), '/');
    }
}
