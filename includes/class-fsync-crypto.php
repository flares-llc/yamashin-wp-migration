<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Authenticated encryption for stored secrets.
 *
 * AES-256-GCM, with three changes over the straightforward implementation this
 * plugin inherits from shusei-deploy:
 *
 * 1. A four step key resolution chain. Deriving the key solely from the
 *    WordPress salts means that regenerating those salts -- a routine and
 *    recommended operation -- makes every stored credential permanently
 *    undecryptable, with no error message that points at the cause.
 * 2. HKDF-derived subkeys per purpose, so the key used for peer secrets is not
 *    the key used for cloud credentials.
 * 3. AAD binding the ciphertext to its purpose and record id, so a ciphertext
 *    copied into a different row fails to decrypt instead of silently
 *    authenticating as that row's value.
 *
 * A canary record lets the plugin say "the encryption key changed" instead of
 * "decryption failed".
 */
final class Fsync_Crypto
{
    const ENVELOPE_VERSION = 2;
    const CIPHER = 'aes-256-gcm';
    const KEY_BYTES = 32;
    const IV_BYTES = 12;
    const SALT_BYTES = 16;

    const OPTION_CANARY = 'fsync_crypto_canary';
    const CANARY_PLAINTEXT = 'flares-sync-canary-v2';

    /** Filename of the fallback key inside private storage. */
    const KEYFILE = '.keyfile';

    /** @var array{key: string, ref: string, source: string}|WP_Error|null */
    private static $master = null;

    /**
     * Resolve the master key.
     *
     * Order matters: an explicit constant is preferred because it survives salt
     * rotation and can be kept out of the database entirely. The salt-derived
     * key remains last so that existing installs keep working, but it is
     * reported as a warning by the health panel.
     *
     * @return array{key: string, ref: string, source: string}|WP_Error
     */
    public static function master()
    {
        if (self::$master !== null) {
            return self::$master;
        }

        self::$master = self::resolve_master();

        return self::$master;
    }

    /**
     * @return array{key: string, ref: string, source: string}|WP_Error
     */
    private static function resolve_master()
    {
        if (defined('FSYNC_ENCRYPTION_KEY')) {
            $key = self::decode_key((string) constant('FSYNC_ENCRYPTION_KEY'));
            if (is_wp_error($key)) {
                return $key;
            }

            return self::wrap($key, 'constant');
        }

        $keyfile = Fsync_Fs::private_dir(self::KEYFILE);
        if (is_readable($keyfile)) {
            $raw = trim((string) file_get_contents($keyfile));
            $key = self::decode_key($raw);
            if (! is_wp_error($key)) {
                return self::wrap($key, 'keyfile');
            }
        }

        if (defined('AUTH_KEY') && defined('SECURE_AUTH_KEY') && defined('LOGGED_IN_KEY')) {
            $key = hash('sha256', AUTH_KEY . SECURE_AUTH_KEY . LOGGED_IN_KEY, true);

            return self::wrap($key, 'salts');
        }

        return new WP_Error(
            'fsync_no_encryption_key',
            '暗号化キーを解決できません。wp-config.php に FSYNC_ENCRYPTION_KEY を定義してください。'
        );
    }

    /**
     * @param string $key Raw 32 bytes.
     * @param string $source
     * @return array{key: string, ref: string, source: string}
     */
    private static function wrap($key, $source)
    {
        return array(
            'key' => $key,
            // Identifies the key without revealing it, so a mismatch can be
            // reported precisely.
            'ref' => substr(hash('sha256', 'fsync-key-ref|' . $key), 0, 16),
            'source' => $source,
        );
    }

    /**
     * Accept base64 or hex, require exactly 32 bytes.
     *
     * @param string $raw
     * @return string|WP_Error
     */
    private static function decode_key($raw)
    {
        $raw = trim($raw);
        if ($raw === '') {
            return new WP_Error('fsync_key_empty', '暗号化キーが空です。');
        }

        if (preg_match('/^[0-9a-fA-F]{64}$/', $raw)) {
            return (string) hex2bin($raw);
        }

        $decoded = base64_decode(strtr($raw, '-_', '+/'), true);
        if ($decoded !== false && strlen($decoded) === self::KEY_BYTES) {
            return $decoded;
        }

        return new WP_Error(
            'fsync_key_invalid',
            '暗号化キーの形式が不正です。32バイトをbase64またはhexで指定してください。'
        );
    }

    /**
     * Generate a value suitable for FSYNC_ENCRYPTION_KEY.
     *
     * @return string|WP_Error base64 of 32 random bytes.
     */
    public static function generate_key()
    {
        try {
            return base64_encode(random_bytes(self::KEY_BYTES));
        } catch (Exception $exception) {
            return new WP_Error('fsync_random_failed', '安全な乱数を生成できません。');
        }
    }

    /**
     * Encrypt a value.
     *
     * @param string $plaintext
     * @param string $purpose Domain separator, e.g. "credential" or "key".
     * @param string $record_id Binds the ciphertext to one row.
     * @return string|WP_Error Base64 envelope.
     */
    public static function encrypt($plaintext, $purpose, $record_id = '')
    {
        if ((string) $plaintext === '') {
            return '';
        }

        $master = self::master();
        if (is_wp_error($master)) {
            return $master;
        }

        if (! function_exists('openssl_encrypt')) {
            return new WP_Error('fsync_crypto_unavailable', 'OpenSSL暗号化を利用できません。');
        }

        try {
            $salt = random_bytes(self::SALT_BYTES);
            $iv = random_bytes(self::IV_BYTES);
        } catch (Exception $exception) {
            return new WP_Error('fsync_random_failed', '安全な乱数を生成できません。');
        }

        $subkey = self::subkey($master['key'], $purpose, $salt);
        if (is_wp_error($subkey)) {
            return $subkey;
        }

        $tag = '';
        $ciphertext = openssl_encrypt(
            (string) $plaintext,
            self::CIPHER,
            $subkey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::aad($purpose, $record_id)
        );

        if ($ciphertext === false) {
            return new WP_Error('fsync_encrypt_failed', '認証情報を暗号化できません。');
        }

        $envelope = Fsync_Utils::encode(
            array(
                'v' => self::ENVELOPE_VERSION,
                'k' => $master['ref'],
                's' => base64_encode($salt),
                'i' => base64_encode($iv),
                't' => base64_encode($tag),
                'd' => base64_encode($ciphertext),
            )
        );

        if (is_wp_error($envelope)) {
            return $envelope;
        }

        return base64_encode($envelope);
    }

    /**
     * Decrypt a value.
     *
     * @param string $payload
     * @param string $purpose Must match the value used at encryption time.
     * @param string $record_id Must match the value used at encryption time.
     * @return string|WP_Error
     */
    public static function decrypt($payload, $purpose, $record_id = '')
    {
        if ((string) $payload === '') {
            return '';
        }

        $master = self::master();
        if (is_wp_error($master)) {
            return $master;
        }

        $decoded = base64_decode((string) $payload, true);
        $envelope = $decoded !== false ? json_decode($decoded, true) : null;

        if (! is_array($envelope)) {
            return new WP_Error('fsync_envelope_invalid', '保存された認証情報を読み取れません。');
        }

        $version = (int) ($envelope['v'] ?? 0);
        if ($version === 1) {
            return self::decrypt_v1($envelope, $master);
        }

        if ($version !== self::ENVELOPE_VERSION) {
            return new WP_Error('fsync_envelope_version', '保存された認証情報の形式に対応していません。');
        }

        if (! empty($envelope['k']) && ! hash_equals((string) $envelope['k'], $master['ref'])) {
            return new WP_Error(
                'fsync_key_changed',
                '暗号化キーが変わっています。wp-config.php の FSYNC_ENCRYPTION_KEY を確認するか、WordPress のソルトを再生成していないか確認してください。'
            );
        }

        $salt = base64_decode((string) ($envelope['s'] ?? ''), true);
        $iv = base64_decode((string) ($envelope['i'] ?? ''), true);
        $tag = base64_decode((string) ($envelope['t'] ?? ''), true);
        $ciphertext = base64_decode((string) ($envelope['d'] ?? ''), true);

        if ($salt === false || $iv === false || $tag === false || $ciphertext === false) {
            return new WP_Error('fsync_envelope_invalid', '保存された認証情報を読み取れません。');
        }

        $subkey = self::subkey($master['key'], $purpose, $salt);
        if (is_wp_error($subkey)) {
            return $subkey;
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $subkey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::aad($purpose, $record_id)
        );

        if ($plaintext === false) {
            return new WP_Error(
                'fsync_decrypt_failed',
                '認証情報を復号できません。保存し直してください。'
            );
        }

        return $plaintext;
    }

    /**
     * Read an envelope written by the shusei-deploy style v1 format, so an
     * upgrade path exists for sites migrating from it.
     *
     * @param array $envelope
     * @param array $master
     * @return string|WP_Error
     */
    private static function decrypt_v1($envelope, $master)
    {
        $iv = base64_decode((string) ($envelope['iv'] ?? ''), true);
        $tag = base64_decode((string) ($envelope['tag'] ?? ''), true);
        $ciphertext = base64_decode((string) ($envelope['data'] ?? ''), true);

        if ($iv === false || $tag === false || $ciphertext === false) {
            return new WP_Error('fsync_envelope_invalid', '保存された認証情報を読み取れません。');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $master['key'],
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            return new WP_Error('fsync_decrypt_failed', '認証情報を復号できません。');
        }

        return $plaintext;
    }

    /**
     * Derive a purpose-scoped subkey.
     *
     * @param string $master
     * @param string $purpose
     * @param string $salt
     * @return string|WP_Error
     */
    private static function subkey($master, $purpose, $salt)
    {
        if (! function_exists('hash_hkdf')) {
            return new WP_Error('fsync_hkdf_unavailable', 'hash_hkdf を利用できません。');
        }

        return hash_hkdf('sha256', $master, self::KEY_BYTES, 'fsync:' . $purpose, $salt);
    }

    /**
     * @param string $purpose
     * @param string $record_id
     * @return string
     */
    private static function aad($purpose, $record_id)
    {
        return 'fsync|v' . self::ENVELOPE_VERSION . '|' . $purpose . '|' . $record_id;
    }

    /**
     * Write the canary. Called after the key is first resolved successfully.
     *
     * @return true|WP_Error
     */
    public static function write_canary()
    {
        $envelope = self::encrypt(self::CANARY_PLAINTEXT, 'canary', 'global');
        if (is_wp_error($envelope)) {
            return $envelope;
        }

        update_option(self::OPTION_CANARY, $envelope, false);

        return true;
    }

    /**
     * Verify that the current key still decrypts what it wrote.
     *
     * @return array{ok: bool, code: string, message: string, source: string}
     */
    public static function check()
    {
        $master = self::master();
        if (is_wp_error($master)) {
            return array(
                'ok' => false,
                'code' => $master->get_error_code(),
                'message' => $master->get_error_message(),
                'source' => 'none',
            );
        }

        $stored = (string) get_option(self::OPTION_CANARY, '');
        if ($stored === '') {
            self::write_canary();

            return array(
                'ok' => true,
                'code' => 'initialized',
                'message' => '暗号化キーを初期化しました。',
                'source' => $master['source'],
            );
        }

        $plaintext = self::decrypt($stored, 'canary', 'global');
        if (is_wp_error($plaintext)) {
            return array(
                'ok' => false,
                'code' => $plaintext->get_error_code(),
                'message' => $plaintext->get_error_message(),
                'source' => $master['source'],
            );
        }

        if (! hash_equals(self::CANARY_PLAINTEXT, $plaintext)) {
            return array(
                'ok' => false,
                'code' => 'fsync_canary_mismatch',
                'message' => '暗号化キーの検証に失敗しました。',
                'source' => $master['source'],
            );
        }

        return array(
            'ok' => true,
            'code' => 'ok',
            'message' => '暗号化キーは正常です。',
            'source' => $master['source'],
        );
    }

    /**
     * Reset the memoized key. Used after the keyfile is written.
     *
     * @return void
     */
    public static function flush()
    {
        self::$master = null;
    }

    /**
     * Create the fallback keyfile when no constant is defined.
     *
     * Deliberately not called automatically: silently generating a key that
     * lives next to the data it protects would make the constant look optional.
     * The admin screen offers it as an explicit choice.
     *
     * @return true|WP_Error
     */
    public static function create_keyfile()
    {
        $path = Fsync_Fs::private_dir(self::KEYFILE);
        if (file_exists($path)) {
            return new WP_Error('fsync_keyfile_exists', 'キーファイルは既に存在します。');
        }

        $key = self::generate_key();
        if (is_wp_error($key)) {
            return $key;
        }

        $written = Fsync_Fs::write_atomic($path, $key . "\n");
        if (is_wp_error($written)) {
            return $written;
        }

        @chmod($path, 0600);
        self::flush();

        return true;
    }
}
