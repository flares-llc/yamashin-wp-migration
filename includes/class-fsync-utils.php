<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Small shared helpers: stable hashing, path safety, encoding.
 *
 * canonical_hash()/canonicalize() and normalize_relative_path() are ported from
 * the shusei-deploy plugin, which proved them in production. The notable change
 * is that encoding failures are now detected instead of silently producing a
 * hash of the string "false".
 */
final class Fsync_Utils
{
    const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * Hash a value in a way that is stable across sites and PHP versions.
     *
     * Associative arrays are key-sorted so that insertion order cannot change
     * the hash; list arrays keep their order because it is usually meaningful
     * (ACF repeaters, for example).
     *
     * @param mixed $value
     * @return string|WP_Error 64 hex characters.
     */
    public static function canonical_hash($value)
    {
        $encoded = self::encode(self::canonicalize($value));
        if (is_wp_error($encoded)) {
            return $encoded;
        }

        return hash('sha256', $encoded);
    }

    /**
     * JSON-encode with hard failure on invalid input.
     *
     * json_encode() returns false when handed malformed UTF-8. Callers that
     * ignore this write empty lines into dumps and hash the wrong thing, so
     * the failure is surfaced as a WP_Error instead.
     *
     * @param mixed $value
     * @return string|WP_Error
     */
    public static function encode($value)
    {
        $encoded = json_encode($value, self::JSON_FLAGS);
        if ($encoded === false) {
            return new WP_Error(
                'fsync_json_encode_failed',
                sprintf('データをJSONに変換できません: %s', json_last_error_msg())
            );
        }

        return $encoded;
    }

    /**
     * Decode JSON into associative arrays, with hard failure.
     *
     * @param string $json
     * @return mixed|WP_Error
     */
    public static function decode($json)
    {
        $decoded = json_decode((string) $json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error(
                'fsync_json_decode_failed',
                sprintf('JSONを解析できません: %s', json_last_error_msg())
            );
        }

        return $decoded;
    }

    /**
     * Recursively key-sort associative arrays, leaving lists untouched.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function canonicalize($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! self::is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    /**
     * Whether an array is a zero-indexed list rather than a map.
     *
     * @param array $value
     * @return bool
     */
    public static function is_list($value)
    {
        if ($value === array()) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * Reject anything that could escape the directory it is resolved against.
     *
     * @param string $path
     * @return string|WP_Error Normalized relative path using forward slashes.
     */
    public static function normalize_relative_path($path)
    {
        $path = str_replace('\\', '/', trim((string) $path));
        $path = (string) preg_replace('#/+#', '/', $path);
        $path = ltrim($path, '/');

        if ($path === '') {
            return new WP_Error('fsync_path_empty', 'ファイルパスが空です。');
        }

        if (strpos($path, "\0") !== false) {
            return new WP_Error('fsync_path_invalid', 'ファイルパスに不正な文字が含まれています。');
        }

        // Windows drive letters and UNC paths are absolute despite not starting
        // with a slash.
        if (preg_match('#^[a-zA-Z]:#', $path)) {
            return new WP_Error('fsync_path_absolute', '絶対パスは指定できません。');
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return new WP_Error('fsync_path_traversal', 'ファイルパスに相対参照を含められません。');
            }
        }

        return $path;
    }

    /**
     * Constant-time comparison that tolerates non-string input.
     *
     * @param string $known
     * @param string $given
     * @return bool
     */
    public static function hash_equals($known, $given)
    {
        if (! is_string($known) || ! is_string($given)) {
            return false;
        }

        return hash_equals($known, $given);
    }

    /**
     * Random lowercase hex string.
     *
     * @param int $bytes
     * @return string|WP_Error
     */
    public static function random_hex($bytes = 16)
    {
        try {
            return bin2hex(random_bytes(max(1, (int) $bytes)));
        } catch (Exception $exception) {
            return new WP_Error('fsync_random_failed', '安全な乱数を生成できません。');
        }
    }

    /**
     * Random UUID v4 used as the portable identity of WordPress entities.
     *
     * @return string|WP_Error
     */
    public static function uuid4()
    {
        try {
            $bytes = random_bytes(16);
        } catch (Exception $exception) {
            return new WP_Error('fsync_random_failed', '安全なUUIDを生成できません。');
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public static function is_sha256($value)
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    /**
     * Validate a public opaque id without exposing incremental database ids.
     *
     * @param mixed $value
     * @return bool
     */
    public static function is_public_id($value)
    {
        return is_string($value) && preg_match('/^[a-f0-9]{32}$/', $value) === 1;
    }

    /**
     * URL-safe base64 without padding, used for tokens and pairing blobs.
     *
     * @param string $raw
     * @return string
     */
    public static function base64url_encode($raw)
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @param string $encoded
     * @return string|false
     */
    public static function base64url_decode($encoded)
    {
        $encoded = strtr((string) $encoded, '-_', '+/');
        $padding = strlen($encoded) % 4;
        if ($padding > 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }

        return base64_decode($encoded, true);
    }

    /**
     * Short, human-comparable fingerprint of a secret. Safe to display: it is a
     * one-way hash truncated to a length that is useless for recovery but long
     * enough to tell two credentials apart.
     *
     * @param string $secret
     * @return string
     */
    public static function fingerprint($secret)
    {
        if ((string) $secret === '') {
            return '';
        }

        return substr(hash('sha256', (string) $secret), 0, 8);
    }

    /**
     * Current UTC time. Isolated so that tests can reason about it and so that
     * every timestamp in the plugin agrees on a source.
     *
     * @return int
     */
    public static function now()
    {
        return time();
    }

    /**
     * @return string ISO-8601 UTC timestamp.
     */
    public static function now_iso()
    {
        return gmdate('c');
    }
}
