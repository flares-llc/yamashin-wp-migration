<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Write-only credential store.
 *
 * The public surface is deliberately asymmetric: anything that a screen or an
 * API response can reach returns metadata only (kind, fingerprint, whether a
 * value is set). Plaintext is available through get(), which exists for the
 * transport layer and is never wired to a response.
 *
 * Configuration references credentials by id, never by value, so that a config
 * document can be committed to a repository safely.
 */
final class Fsync_Credentials
{
    /** Purpose passed to the crypto layer for domain separation. */
    const PURPOSE = 'credential';

    /** Known credential kinds, used for validation and UI labelling. */
    const KINDS = array(
        'peer' => 'ピア接続の共有シークレット',
        'gcs' => 'Google Cloud Storage サービスアカウント JSON',
        'gdrive' => 'Google Drive サービスアカウント JSON',
        'gdrive_oauth' => 'Google Drive OAuth クライアント/リフレッシュトークン',
        'slack' => 'Slack Incoming Webhook URL',
        'webhook' => '汎用 Webhook の署名シークレット',
        'tick' => '外部トリガー用トークン',
    );

    /**
     * Store or replace a credential value.
     *
     * @param string $credential_id Referenced from the config document.
     * @param string $kind
     * @param string $plaintext
     * @return true|WP_Error
     */
    public static function put($credential_id, $kind, $plaintext)
    {
        global $wpdb;

        $credential_id = self::normalize_id($credential_id);
        if (is_wp_error($credential_id)) {
            return $credential_id;
        }

        if ((string) $plaintext === '') {
            return new WP_Error('fsync_credential_empty', '値が空です。削除する場合は「クリア」を実行してください。');
        }

        $ciphertext = Fsync_Crypto::encrypt($plaintext, self::PURPOSE, $credential_id);
        if (is_wp_error($ciphertext)) {
            return $ciphertext;
        }

        $master = Fsync_Crypto::master();
        $now = Fsync_Utils::now();
        $existing = self::meta($credential_id);

        $row = array(
            'credential_id' => $credential_id,
            'kind' => substr((string) $kind, 0, 32),
            'ciphertext' => $ciphertext,
            'fingerprint' => Fsync_Utils::fingerprint($plaintext),
            'key_ref' => is_wp_error($master) ? '' : $master['ref'],
            'updated_at' => $now,
            'updated_by' => (int) get_current_user_id(),
        );

        if ($existing === null) {
            $row['created_at'] = $now;
            $result = $wpdb->insert(Fsync_Schema::table('credentials'), $row);
        } else {
            $result = $wpdb->update(
                Fsync_Schema::table('credentials'),
                $row,
                array('credential_id' => $credential_id)
            );
        }

        if ($result === false) {
            return new WP_Error('fsync_credential_write_failed', '認証情報を保存できませんでした。');
        }

        Fsync_Log::info(
            'credential_updated',
            sprintf('認証情報を更新しました: %s', $credential_id),
            array('data' => array('credential_id' => $credential_id, 'kind' => $kind))
        );

        return true;
    }

    /**
     * Retrieve the plaintext.
     *
     * Internal use only. Never return the result of this from a REST callback
     * or render it into a page.
     *
     * @param string $credential_id
     * @return string|WP_Error
     */
    public static function get($credential_id)
    {
        global $wpdb;

        $credential_id = self::normalize_id($credential_id);
        if (is_wp_error($credential_id)) {
            return $credential_id;
        }

        $table = Fsync_Schema::table('credentials');
        $ciphertext = $wpdb->get_var(
            $wpdb->prepare("SELECT ciphertext FROM {$table} WHERE credential_id = %s", $credential_id)
        );

        if ($ciphertext === null) {
            return new WP_Error(
                'fsync_credential_missing',
                sprintf('認証情報が設定されていません: %s', $credential_id)
            );
        }

        return Fsync_Crypto::decrypt((string) $ciphertext, self::PURPOSE, $credential_id);
    }

    /**
     * Metadata for one credential. Safe to expose.
     *
     * @param string $credential_id
     * @return array|null
     */
    public static function meta($credential_id)
    {
        global $wpdb;

        $credential_id = self::normalize_id($credential_id);
        if (is_wp_error($credential_id)) {
            return null;
        }

        $table = Fsync_Schema::table('credentials');
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT credential_id, kind, fingerprint, key_ref, created_at, updated_at, updated_by
                 FROM {$table} WHERE credential_id = %s",
                $credential_id
            ),
            ARRAY_A
        );

        return $row === null ? null : self::shape($row);
    }

    /**
     * Metadata for every stored credential. Safe to expose.
     *
     * @return array<int, array>
     */
    public static function all()
    {
        global $wpdb;

        $table = Fsync_Schema::table('credentials');
        $rows = (array) $wpdb->get_results(
            "SELECT credential_id, kind, fingerprint, key_ref, created_at, updated_at, updated_by
             FROM {$table} ORDER BY credential_id ASC",
            ARRAY_A
        );

        return array_map([self::class, 'shape'], $rows);
    }

    /**
     * Whether a value is present, without touching the ciphertext.
     *
     * @param string $credential_id
     * @return bool
     */
    public static function has($credential_id)
    {
        return self::meta($credential_id) !== null;
    }

    /**
     * Remove a credential.
     *
     * @param string $credential_id
     * @return true|WP_Error
     */
    public static function clear($credential_id)
    {
        global $wpdb;

        $credential_id = self::normalize_id($credential_id);
        if (is_wp_error($credential_id)) {
            return $credential_id;
        }

        $wpdb->delete(Fsync_Schema::table('credentials'), array('credential_id' => $credential_id));

        Fsync_Log::warning(
            'credential_cleared',
            sprintf('認証情報を削除しました: %s', $credential_id),
            array('data' => array('credential_id' => $credential_id))
        );

        return true;
    }

    /**
     * Credentials that can no longer be decrypted because the master key
     * changed. Surfaced on the health panel so the cause is obvious rather
     * than appearing as a series of unrelated failures.
     *
     * @return array<int, string>
     */
    public static function stale()
    {
        $master = Fsync_Crypto::master();
        if (is_wp_error($master)) {
            return array_column(self::all(), 'credential_id');
        }

        $stale = array();
        foreach (self::all() as $meta) {
            if ($meta['key_ref'] !== '' && $meta['key_ref'] !== $master['ref']) {
                $stale[] = $meta['credential_id'];
            }
        }

        return $stale;
    }

    /**
     * Ids referenced by a config document that have no stored value.
     *
     * @param array<int, string> $referenced
     * @return array<int, string>
     */
    public static function missing(array $referenced)
    {
        $known = array_column(self::all(), 'credential_id');

        return array_values(array_diff(array_unique($referenced), $known));
    }

    /**
     * @param array $row
     * @return array
     */
    private static function shape($row)
    {
        return array(
            'credential_id' => (string) $row['credential_id'],
            'kind' => (string) $row['kind'],
            'kind_label' => self::KINDS[$row['kind']] ?? (string) $row['kind'],
            'fingerprint' => (string) $row['fingerprint'],
            'key_ref' => (string) $row['key_ref'],
            'is_set' => true,
            'created_at' => (int) $row['created_at'],
            'updated_at' => (int) $row['updated_at'],
            'updated_by' => (int) $row['updated_by'],
        );
    }

    /**
     * @param string $credential_id
     * @return string|WP_Error
     */
    private static function normalize_id($credential_id)
    {
        $credential_id = strtolower(trim((string) $credential_id));

        if ($credential_id === '' || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $credential_id)) {
            return new WP_Error(
                'fsync_credential_id_invalid',
                '認証情報IDは英数字・ハイフン・アンダースコア・ドットのみ使用できます。'
            );
        }

        return $credential_id;
    }
}
