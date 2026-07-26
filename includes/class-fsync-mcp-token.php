<?php

if (! defined('ABSPATH')) {
    exit;
}

/** One-time-issued, hashed bearer tokens dedicated to MCP clients. */
final class Fsync_Mcp_Token
{
    private static $current = null;

    /** @return array|WP_Error */
    public static function issue($label, array $capabilities, array $origins = array())
    {
        global $wpdb;
        $token_id = Fsync_Utils::random_hex(8);
        if (is_wp_error($token_id)) {
            return $token_id;
        }
        try {
            $secret = Fsync_Utils::base64url_encode(random_bytes(32));
        } catch (Exception $exception) {
            return new WP_Error('fsync_random_failed', 'MCPトークンを生成できません。');
        }
        $safe_origins = array();
        foreach ($origins as $origin) {
            $origin = self::normalize_origin($origin);
            if (is_wp_error($origin)) {
                return $origin;
            }
            $safe_origins[] = $origin;
        }
        $caps = Fsync_Keys::sanitize_capabilities($capabilities);
        $encoded_caps = Fsync_Utils::encode($caps);
        $encoded_origins = Fsync_Utils::encode(array_values(array_unique($safe_origins)));
        if (is_wp_error($encoded_caps) || is_wp_error($encoded_origins)) {
            return new WP_Error('fsync_mcp_token_encode_failed', 'MCPトークン設定を保存できません。');
        }
        $token = $token_id . '.' . $secret;
        $saved = $wpdb->insert(
            Fsync_Schema::table('mcp_tokens'),
            array(
                'token_id' => $token_id,
                'label' => substr(sanitize_text_field((string) $label), 0, 191),
                'token_hash' => hash('sha256', $token),
                'capabilities' => $encoded_caps,
                'origin_allowlist' => $encoded_origins,
                'status' => 'active',
                'last_used_at' => 0,
                'created_at' => Fsync_Utils::now(),
            )
        );
        if ($saved === false) {
            return new WP_Error('fsync_mcp_token_save_failed', 'MCPトークンを保存できません。');
        }
        Fsync_Log::warning('mcp_token_issued', 'MCPトークンを発行しました。', array('data' => array('token_id' => $token_id, 'capabilities' => $caps)));

        return array('token_id' => $token_id, 'token' => $token, 'capabilities' => $caps, 'origins' => $safe_origins);
    }

    /** @return true|WP_Error */
    public static function authenticate($request)
    {
        global $wpdb;
        self::$current = null;
        $token = trim((string) $request->get_header('X-Fsync-MCP-Token'));
        if ($token === '') {
            $authorization = trim((string) $request->get_header('Authorization'));
            if (stripos($authorization, 'Bearer ') === 0) {
                $token = trim(substr($authorization, 7));
            }
        }
        if (preg_match('/^([a-f0-9]{16})\.([A-Za-z0-9_-]{40,60})$/', $token, $matches) !== 1) {
            return new WP_Error('fsync_mcp_token_missing', '有効なMCPトークンがありません。', array('status' => 401));
        }
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . Fsync_Schema::table('mcp_tokens') . ' WHERE token_id = %s', $matches[1]),
            ARRAY_A
        );
        if ($row === null || $row['status'] !== 'active' || ! hash_equals((string) $row['token_hash'], hash('sha256', $token))) {
            return new WP_Error('fsync_mcp_token_invalid', 'MCPトークンが無効です。', array('status' => 401));
        }

        $origin = trim((string) $request->get_header('Origin'));
        $origins = json_decode((string) $row['origin_allowlist'], true);
        $origins = is_array($origins) ? $origins : array();
        if ($origin !== '') {
            $normalized = self::normalize_origin($origin);
            if (is_wp_error($normalized)) {
                return new WP_Error('fsync_mcp_origin_denied', 'MCP Originが不正です。', array('status' => 403));
            }
            $site_origin = self::normalize_origin(home_url('/'));
            if (! in_array($normalized, $origins, true) && (is_wp_error($site_origin) || $normalized !== $site_origin)) {
                return new WP_Error('fsync_mcp_origin_denied', 'このMCP Originは許可されていません。', array('status' => 403));
            }
        }

        if (! is_ssl() && ! Fsync_Pairing::is_local_url(home_url('/'))) {
            return new WP_Error('fsync_mcp_https_required', '公開ホストのMCP接続にはHTTPSが必要です。', array('status' => 403));
        }

        $caps = json_decode((string) $row['capabilities'], true);
        self::$current = array(
            'token_id' => (string) $row['token_id'],
            'label' => (string) $row['label'],
            'capabilities' => is_array($caps) ? $caps : array(),
            'origins' => $origins,
        );
        $wpdb->update(Fsync_Schema::table('mcp_tokens'), array('last_used_at' => Fsync_Utils::now()), array('token_id' => $row['token_id']));

        return true;
    }

    public static function can($capability)
    {
        return self::$current !== null && in_array((string) $capability, self::$current['capabilities'], true);
    }

    public static function require_capability($capability)
    {
        return self::can($capability)
            ? true
            : new WP_Error('fsync_mcp_capability_missing', sprintf('MCPトークンに「%s」権限がありません。', $capability), array('status' => 403));
    }

    public static function all()
    {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . Fsync_Schema::table('mcp_tokens') . ' ORDER BY created_at DESC', ARRAY_A);
        foreach ((array) $rows as &$row) {
            unset($row['token_hash']);
            $row['capabilities'] = (array) json_decode((string) $row['capabilities'], true);
            $row['origin_allowlist'] = (array) json_decode((string) $row['origin_allowlist'], true);
        }

        return (array) $rows;
    }

    public static function retire($token_id)
    {
        global $wpdb;
        $updated = $wpdb->update(Fsync_Schema::table('mcp_tokens'), array('status' => 'retired'), array('token_id' => (string) $token_id));

        return $updated === false ? new WP_Error('fsync_mcp_token_retire_failed', 'MCPトークンを失効できません。') : true;
    }

    private static function normalize_origin($value)
    {
        $parts = wp_parse_url(trim((string) $value));
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), array('http', 'https'), true) || empty($parts['host'])) {
            return new WP_Error('fsync_mcp_origin_invalid', 'Originはhttpまたはhttpsのオリジンで指定してください。');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && ! in_array((string) $parts['path'], array('', '/'), true))) {
            return new WP_Error('fsync_mcp_origin_invalid', 'Originにはパス、認証情報、クエリ、フラグメントを含められません。');
        }
        $origin = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }

        return $origin;
    }
}
