<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Content-addressed storage shared by entity payloads and file chunks.
 *
 * A caller never chooses the final path. The SHA-256 digest is validated and
 * split into a two-level directory so a large site does not put millions of
 * files in one directory.
 */
final class Fsync_Store
{
    const MAX_CHUNK_BYTES = 4194304;

    /**
     * @param string $contents
     * @return string|WP_Error SHA-256 object id.
     */
    public static function put($contents)
    {
        if (! is_string($contents)) {
            return new WP_Error('fsync_object_invalid', 'オブジェクトはバイト列で指定してください。');
        }

        $hash = hash('sha256', $contents);
        $path = self::path($hash);
        if (is_wp_error($path)) {
            return $path;
        }

        if (is_file($path)) {
            if (self::verify($hash)) {
                return $hash;
            }
            $quarantined = self::quarantine($path, $hash);
            if (is_wp_error($quarantined)) {
                return $quarantined;
            }
        }

        $written = Fsync_Fs::write_atomic($path, $contents);
        if (is_wp_error($written)) {
            return $written;
        }

        return $hash;
    }

    /**
     * Import a local file without loading it into PHP memory.
     *
     * @param string $source Absolute readable path.
     * @return string|WP_Error
     */
    public static function import_file($source)
    {
        if (! is_string($source) || ! is_file($source) || ! is_readable($source)) {
            return new WP_Error('fsync_object_source_missing', '取り込むファイルを読み取れません。');
        }

        $hash = @hash_file('sha256', $source);
        if (! is_string($hash) || ! Fsync_Utils::is_sha256($hash)) {
            return new WP_Error('fsync_object_hash_failed', 'ファイルのハッシュを計算できません。');
        }

        $path = self::path($hash);
        if (is_wp_error($path)) {
            return $path;
        }

        if (is_file($path)) {
            if (self::verify($hash)) {
                return $hash;
            }
            $quarantined = self::quarantine($path, $hash);
            if (is_wp_error($quarantined)) {
                return $quarantined;
            }
        }

        if (! is_dir(dirname($path)) && ! wp_mkdir_p(dirname($path))) {
            return new WP_Error('fsync_mkdir_failed', 'オブジェクト保存先を作成できません。');
        }

        $suffix = Fsync_Utils::random_hex(8);
        if (is_wp_error($suffix)) {
            return $suffix;
        }
        $tmp = Fsync_Fs::private_dir('tmp/' . $hash . '.' . $suffix . '.import');
        if (! is_dir(dirname($tmp)) && ! wp_mkdir_p(dirname($tmp))) {
            return new WP_Error('fsync_mkdir_failed', 'オブジェクトの一時保存先を作成できません。');
        }
        $input = @fopen($source, 'rb');
        $output = @fopen($tmp, 'wb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($tmp);

            return new WP_Error('fsync_object_copy_failed', 'オブジェクトの一時ファイルを作成できません。');
        }

        $copied = stream_copy_to_stream($input, $output);
        fflush($output);
        fclose($input);
        fclose($output);

        if ($copied === false || ! hash_equals($hash, (string) @hash_file('sha256', $tmp))) {
            @unlink($tmp);

            return new WP_Error('fsync_object_copy_failed', 'オブジェクトのコピー検証に失敗しました。');
        }

        if (! @rename($tmp, $path)) {
            @unlink($tmp);

            return new WP_Error('fsync_rename_failed', 'オブジェクトを確定できません。');
        }

        return $hash;
    }

    /**
     * Receive one base64 encoded chunk. Duplicate chunks at the current offset
     * are idempotent; a mismatched offset is rejected instead of corrupting the
     * partial object.
     *
     * @param string $hash
     * @param int $offset
     * @param int $total
     * @param string $encoded
     * @return array|WP_Error
     */
    public static function put_chunk($hash, $offset, $total, $encoded)
    {
        if (! Fsync_Utils::is_sha256($hash)) {
            return new WP_Error('fsync_object_hash_invalid', 'オブジェクトIDが不正です。');
        }

        $offset = (int) $offset;
        $total = (int) $total;
        if ($offset < 0 || $total < 0 || $offset > $total) {
            return new WP_Error('fsync_chunk_range_invalid', 'チャンク範囲が不正です。');
        }

        $chunk = base64_decode((string) $encoded, true);
        if ($chunk === false || strlen($chunk) > self::MAX_CHUNK_BYTES || $offset + strlen($chunk) > $total) {
            return new WP_Error('fsync_chunk_invalid', 'チャンクを復号できないか、許可サイズを超えています。');
        }

        $final = self::path($hash);
        if (is_wp_error($final)) {
            return $final;
        }
        if (is_file($final)) {
            if (self::verify($hash)) {
                return array('complete' => true, 'offset' => $total, 'hash' => $hash);
            }
            // missing() deliberately reports corrupt objects as absent. Move
            // the bad bytes aside so an authenticated retry can repair the
            // content-addressed entry instead of becoming permanently stuck.
            $quarantined = self::quarantine($final, $hash);
            if (is_wp_error($quarantined)) {
                return $quarantined;
            }
        }

        $part = Fsync_Fs::private_dir('tmp/' . $hash . '.part');
        if (! is_dir(dirname($part)) && ! wp_mkdir_p(dirname($part))) {
            return new WP_Error('fsync_mkdir_failed', 'チャンクの一時保存先を作成できません。');
        }
        $current = is_file($part) ? (int) filesize($part) : 0;
        if ($current !== $offset) {
            return new WP_Error(
                'fsync_chunk_offset_mismatch',
                'チャンクの開始位置が一致しません。',
                array('expected_offset' => $current, 'status' => 409)
            );
        }

        $handle = @fopen($part, $offset === 0 ? 'wb' : 'ab');
        if ($handle === false) {
            return new WP_Error('fsync_chunk_write_failed', 'チャンク一時ファイルを開けません。');
        }
        $written = fwrite($handle, $chunk);
        fflush($handle);
        fclose($handle);
        if ($written !== strlen($chunk)) {
            return new WP_Error('fsync_chunk_write_failed', 'チャンクを最後まで保存できません。');
        }

        $next = $offset + $written;
        if ($next < $total) {
            return array('complete' => false, 'offset' => $next, 'hash' => $hash);
        }

        if (! hash_equals($hash, (string) @hash_file('sha256', $part))) {
            @unlink($part);

            return new WP_Error('fsync_object_hash_mismatch', '受信オブジェクトのハッシュが一致しません。');
        }

        if ((! is_dir(dirname($final)) && ! wp_mkdir_p(dirname($final))) || ! @rename($part, $final)) {
            @unlink($part);

            return new WP_Error('fsync_rename_failed', '受信オブジェクトを確定できません。');
        }

        return array('complete' => true, 'offset' => $next, 'hash' => $hash);
    }

    /**
     * @param string $hash
     * @return string|WP_Error
     */
    public static function get($hash)
    {
        $path = self::path($hash);
        if (is_wp_error($path)) {
            return $path;
        }
        if (! is_readable($path) || ! self::verify($hash)) {
            return new WP_Error('fsync_object_missing', 'オブジェクトが存在しないか破損しています。', array('status' => 404));
        }

        $contents = file_get_contents($path);

        return $contents === false
            ? new WP_Error('fsync_object_read_failed', 'オブジェクトを読み取れません。')
            : $contents;
    }

    /**
     * @param string $hash
     * @return string|WP_Error
     */
    public static function path($hash)
    {
        if (! Fsync_Utils::is_sha256($hash)) {
            return new WP_Error('fsync_object_hash_invalid', 'オブジェクトIDが不正です。');
        }

        return Fsync_Fs::private_dir('objects/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash);
    }

    /**
     * @param string $hash
     * @return bool
     */
    public static function has($hash)
    {
        $path = self::path($hash);

        return ! is_wp_error($path) && is_file($path) && self::verify($hash);
    }

    /**
     * @param string $hash
     * @return bool
     */
    public static function verify($hash)
    {
        $path = self::path($hash);

        return ! is_wp_error($path)
            && is_file($path)
            && hash_equals((string) $hash, (string) @hash_file('sha256', $path));
    }

    /** Copy a verified object into place atomically without buffering it. */
    public static function materialize($hash, $destination)
    {
        $source = self::path($hash);
        if (is_wp_error($source) || ! self::verify($hash)) {
            return new WP_Error('fsync_object_missing', '配置するオブジェクトが存在しないか破損しています。');
        }
        $destination = str_replace('\\', '/', (string) $destination);
        if ($destination === '' || (! is_dir(dirname($destination)) && ! wp_mkdir_p(dirname($destination)))) {
            return new WP_Error('fsync_mkdir_failed', 'ファイル配置先を作成できません。');
        }
        $suffix = Fsync_Utils::random_hex(4);
        if (is_wp_error($suffix)) {
            return $suffix;
        }
        $tmp = $destination . '.fsync-' . substr($hash, 0, 12) . '-' . $suffix . '.tmp';
        $input = @fopen($source, 'rb');
        $output = @fopen($tmp, 'wb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($tmp);

            return new WP_Error('fsync_file_stage_failed', '配置用一時ファイルを作成できません。');
        }
        $copied = stream_copy_to_stream($input, $output);
        fflush($output);
        fclose($input);
        fclose($output);
        if ($copied === false || ! hash_equals($hash, (string) @hash_file('sha256', $tmp))) {
            @unlink($tmp);

            return new WP_Error('fsync_file_stage_failed', '配置用ファイルの検証に失敗しました。');
        }
        if (! @rename($tmp, $destination)) {
            @unlink($tmp);

            return new WP_Error('fsync_rename_failed', 'ファイルを原子的に切り替えられません。');
        }

        return true;
    }

    /**
     * @param array $hashes
     * @return array
     */
    public static function missing(array $hashes)
    {
        $missing = array();
        foreach (array_values(array_unique($hashes)) as $hash) {
            if (Fsync_Utils::is_sha256($hash) && ! self::has($hash)) {
                $missing[] = $hash;
            }
        }

        return $missing;
    }

    private static function quarantine($path, $hash)
    {
        $suffix = Fsync_Utils::random_hex(4);
        if (is_wp_error($suffix)) {
            return $suffix;
        }
        $quarantine = Fsync_Fs::private_dir('tmp/' . $hash . '.corrupt.' . Fsync_Utils::now() . '.' . $suffix);
        if (! @rename($path, $quarantine) && ! @unlink($path)) {
            return new WP_Error('fsync_object_corrupt', '破損オブジェクトを隔離できません。');
        }

        return true;
    }
}
