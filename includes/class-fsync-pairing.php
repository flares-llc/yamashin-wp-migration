<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pairing: turning "two WordPress sites" into "two sites that trust each other"
 * with a single copy and paste.
 *
 * The receiver issues a key and emits a blob containing it. The blob is the
 * only time the shared secret is ever transmitted, and it is consumed by the
 * first successful confirmation, so a blob left in a chat log is inert.
 *
 * The confirmation itself is a signed request: possession of the secret is
 * proven by using it, which means a blob that was copied incorrectly fails
 * immediately and visibly rather than half-working.
 */
final class Fsync_Pairing
{
    const BLOB_VERSION = 1;

    /** How long an unconsumed pairing blob stays valid. */
    const TTL = 1800;

    /**
     * Issue a pairing blob. Runs on the receiving site.
     *
     * connect_url exists because the address a peer must dial is not always
     * home_url(): a site behind a load balancer, on an internal hostname, or in
     * a container network is reached at a different address than the one it
     * renders links with. Defaults to home_url().
     *
     * @param array $args env_name, label, capabilities, ip_allowlist, connect_url
     * @return array{blob: string, key_id: string, expires_at: int}|WP_Error
     */
    public static function create(array $args = array())
    {
        $env_name = Fsync_Peer::normalize_env_name($args['env_name'] ?? 'local');
        if (is_wp_error($env_name)) {
            return $env_name;
        }

        $issued = Fsync_Keys::issue(
            array(
                'label' => (string) ($args['label'] ?? $env_name),
                'capabilities' => $args['capabilities'] ?? Fsync_Keys::PRESETS['deploy'],
                'ip_allowlist' => $args['ip_allowlist'] ?? array(),
                'direction' => Fsync_Keys::DIRECTION_INBOUND,
                'status' => Fsync_Keys::STATUS_PENDING,
            )
        );

        if (is_wp_error($issued)) {
            return $issued;
        }

        $expires_at = Fsync_Utils::now() + self::TTL;

        $connect_url = trim((string) ($args['connect_url'] ?? ''));
        if ($connect_url !== '') {
            $connect_url = esc_url_raw($connect_url);
            if ($connect_url === '') {
                return new WP_Error('fsync_pairing_connect_url', '接続用URLの形式が不正です。');
            }
            $connect_url = trailingslashit($connect_url);
        }

        $payload = array(
            'v' => self::BLOB_VERSION,
            'site' => $connect_url === '' ? home_url('/') : $connect_url,
            'home' => home_url('/'),
            'role' => (string) get_option('fsync_site_role', ''),
            'env' => $env_name,
            'key_id' => $issued['key_id'],
            'secret' => $issued['secret'],
            'caps' => Fsync_Keys::sanitize_capabilities($args['capabilities'] ?? Fsync_Keys::PRESETS['deploy']),
            'alg' => Fsync_Signer_Hmac::ALGORITHM,
            'protocol' => FSYNC_PROTOCOL,
            'hash_algo_version' => FSYNC_HASH_ALGO_VERSION,
            'plugin_version' => FSYNC_VERSION,
            'expires_at' => $expires_at,
        );

        $encoded = Fsync_Utils::encode($payload);
        if (is_wp_error($encoded)) {
            return $encoded;
        }

        return array(
            'blob' => Fsync_Utils::base64url_encode($encoded),
            'key_id' => $issued['key_id'],
            'expires_at' => $expires_at,
        );
    }

    /**
     * Decode and validate a blob. Runs on the initiating site.
     *
     * @param string $blob
     * @return array|WP_Error
     */
    public static function parse($blob)
    {
        $blob = trim((string) $blob);
        if ($blob === '') {
            return new WP_Error('fsync_pairing_empty', '接続情報が空です。');
        }

        // Tolerate the whitespace and line breaks that survive a copy/paste
        // through a chat client or an email.
        $blob = (string) preg_replace('/\s+/', '', $blob);

        $decoded = Fsync_Utils::base64url_decode($blob);
        if ($decoded === false) {
            return new WP_Error('fsync_pairing_malformed', '接続情報を解読できません。貼り付け内容を確認してください。');
        }

        $payload = json_decode($decoded, true);
        if (! is_array($payload)) {
            return new WP_Error('fsync_pairing_malformed', '接続情報の形式が不正です。');
        }

        if ((int) ($payload['v'] ?? 0) !== self::BLOB_VERSION) {
            return new WP_Error('fsync_pairing_version', 'この接続情報の形式には対応していません。');
        }

        foreach (array('site', 'env', 'key_id', 'secret') as $required) {
            if (empty($payload[$required])) {
                return new WP_Error(
                    'fsync_pairing_incomplete',
                    sprintf('接続情報に %s が含まれていません。', $required)
                );
            }
        }

        if (! empty($payload['expires_at']) && (int) $payload['expires_at'] < Fsync_Utils::now()) {
            return new WP_Error(
                'fsync_pairing_expired',
                '接続情報の有効期限が切れています。接続先で再発行してください。'
            );
        }

        if (! empty($payload['protocol']) && (string) $payload['protocol'] !== FSYNC_PROTOCOL) {
            return new WP_Error(
                'fsync_pairing_protocol',
                sprintf(
                    'プロトコルのバージョンが一致しません（相手: %s / このサイト: %s）。両方のプラグインを同じバージョンに更新してください。',
                    (string) $payload['protocol'],
                    FSYNC_PROTOCOL
                )
            );
        }

        if (
            ! empty($payload['hash_algo_version'])
            && (int) $payload['hash_algo_version'] !== FSYNC_HASH_ALGO_VERSION
        ) {
            return new WP_Error(
                'fsync_pairing_hash_algo',
                'ハッシュ方式のバージョンが一致しません。両方のプラグインを同じバージョンに更新してください。'
            );
        }

        $url = esc_url_raw((string) $payload['site']);
        if ($url === '') {
            return new WP_Error('fsync_pairing_url', '接続先URLが不正です。');
        }

        if (strpos($url, 'https://') !== 0 && ! self::is_local_url($url)) {
            return new WP_Error(
                'fsync_pairing_insecure',
                '接続先がHTTPSではありません。署名は保護されますが、通信内容が平文になるため許可していません。'
            );
        }

        return array(
            'site' => trailingslashit($url),
            'home' => (string) ($payload['home'] ?? $payload['site']),
            'role' => (string) ($payload['role'] ?? ''),
            'env' => (string) $payload['env'],
            'key_id' => (string) $payload['key_id'],
            'secret' => (string) $payload['secret'],
            'caps' => (array) ($payload['caps'] ?? array()),
            'algorithm' => (string) ($payload['alg'] ?? Fsync_Signer_Hmac::ALGORITHM),
            'plugin_version' => (string) ($payload['plugin_version'] ?? ''),
            'expires_at' => (int) ($payload['expires_at'] ?? 0),
        );
    }

    /**
     * Store the peer and its secret locally. Runs on the initiating site.
     *
     * The signed confirmation call is made by the caller afterwards, so that a
     * network failure leaves a peer record that can be retried rather than
     * losing the pasted blob.
     *
     * @param string $blob
     * @param string $env_name Optional local name for the environment.
     * @return array{peer_id: string, credential_id: string, parsed: array}|WP_Error
     */
    public static function import($blob, $env_name = '')
    {
        $parsed = self::parse($blob);
        if (is_wp_error($parsed)) {
            return $parsed;
        }

        $env_name = $env_name === '' ? $parsed['env'] : $env_name;
        $env_name = Fsync_Peer::normalize_env_name($env_name);
        if (is_wp_error($env_name)) {
            return $env_name;
        }

        $credential_id = 'peer-' . $env_name;
        $stored = Fsync_Credentials::put($credential_id, 'peer', $parsed['secret']);
        if (is_wp_error($stored)) {
            return $stored;
        }

        $existing = Fsync_Peer::by_env($env_name);

        $peer_id = Fsync_Peer::upsert(
            array(
                'peer_id' => $existing === null ? '' : $existing['peer_id'],
                'env_name' => $env_name,
                'site_role' => $parsed['role'],
                'url' => $parsed['site'],
                'outbound_key_id' => $parsed['key_id'],
            )
        );

        if (is_wp_error($peer_id)) {
            return $peer_id;
        }

        Fsync_Log::info(
            'pairing_imported',
            sprintf('接続情報を取り込みました: %s (%s)', $env_name, $parsed['site']),
            array('peer_id' => $peer_id, 'key_id' => $parsed['key_id'])
        );

        return array(
            'peer_id' => $peer_id,
            'credential_id' => $credential_id,
            'parsed' => array_diff_key($parsed, array('secret' => null)),
        );
    }

    /**
     * Import a blob and complete the handshake in one step.
     *
     * Import is committed before the network call, so a confirmation that fails
     * for a transient reason leaves a peer record that can be retried instead
     * of discarding a blob the operator may no longer have.
     *
     * @param string $blob
     * @param string $env_name
     * @return array|WP_Error
     */
    public static function connect($blob, $env_name = '')
    {
        $imported = self::import($blob, $env_name);
        if (is_wp_error($imported)) {
            return $imported;
        }

        $peer = Fsync_Peer::find($imported['peer_id']);
        if ($peer === null) {
            return new WP_Error('fsync_peer_missing', 'ピア情報を保存できませんでした。');
        }

        $client = Fsync_Client::for_peer($peer);
        if (is_wp_error($client)) {
            return $client;
        }

        $response = $client->post(
            'pair/confirm',
            array(
                'env_name' => Fsync_Config_Io::active_env() ?: 'local',
                'site_role' => (string) get_option('fsync_site_role', ''),
                'url' => home_url('/'),
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        // The peer tells us what it calls itself; record that rather than what
        // the blob claimed, so the two sides agree on naming.
        Fsync_Peer::upsert(
            array(
                'peer_id' => $peer['peer_id'],
                'env_name' => $peer['env_name'],
                'site_role' => (string) ($response['site_role'] ?? $peer['site_role']),
                'url' => $peer['url'],
                'outbound_key_id' => $peer['outbound_key_id'],
                'last_contact_at' => Fsync_Utils::now(),
            )
        );

        Fsync_Log::info(
            'pairing_completed',
            sprintf('接続を確立しました: %s', $peer['env_name']),
            array('peer_id' => $peer['peer_id'], 'key_id' => $peer['outbound_key_id'])
        );

        return array(
            'peer_id' => $peer['peer_id'],
            'env_name' => $peer['env_name'],
            'capabilities' => (array) ($response['capabilities'] ?? array()),
            'remote' => $response,
        );
    }

    /**
     * Consume the pairing. Runs on the receiving site, from /pair/confirm.
     *
     * By the time this is reached the request has already been verified as
     * signed by the key being confirmed, so possession of the secret is proven.
     *
     * @param string $key_id
     * @param array $peer Details reported by the initiator.
     * @return array|WP_Error
     */
    public static function confirm($key_id, array $peer = array())
    {
        $key = Fsync_Keys::find($key_id);
        if ($key === null) {
            return new WP_Error('fsync_key_unknown', '接続キーが見つかりません。', array('status' => 404));
        }

        if ($key['status'] !== Fsync_Keys::STATUS_PENDING) {
            return new WP_Error(
                'fsync_pairing_consumed',
                'このペアリングは既に完了しています。',
                array('status' => 409)
            );
        }

        if ($key['created_at'] + self::TTL < Fsync_Utils::now()) {
            Fsync_Keys::retire($key_id);

            return new WP_Error(
                'fsync_pairing_expired',
                'ペアリングの有効期限が切れています。接続情報を再発行してください。',
                array('status' => 410)
            );
        }

        $env_name = Fsync_Peer::normalize_env_name($peer['env_name'] ?? 'local');
        if (is_wp_error($env_name)) {
            return $env_name;
        }

        $existing = Fsync_Peer::by_env($env_name);

        $peer_id = Fsync_Peer::upsert(
            array(
                'peer_id' => $existing === null ? '' : $existing['peer_id'],
                'env_name' => $env_name,
                'site_role' => (string) ($peer['site_role'] ?? ''),
                'url' => (string) ($peer['url'] ?? ''),
                'last_contact_at' => Fsync_Utils::now(),
            )
        );

        if (is_wp_error($peer_id)) {
            return $peer_id;
        }

        $activated = Fsync_Keys::activate($key_id, $peer_id);
        if (is_wp_error($activated)) {
            return $activated;
        }

        return array(
            'peer_id' => $peer_id,
            'env_name' => $env_name,
            'capabilities' => $key['capabilities'],
        );
    }

    /**
     * Whether a URL points at a development host, where plain HTTP is normal.
     *
     * @param string $url
     * @return bool
     */
    public static function is_local_url($url)
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return false;
        }

        $host = strtolower($host);

        if (in_array($host, array('localhost', '127.0.0.1', '::1', 'host.docker.internal'), true)) {
            return true;
        }

        foreach (array('.local', '.test', '.localhost', '.internal', '.lan') as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        // A single-label hostname cannot be a public DNS name -- every
        // internet-reachable host has at least one dot. So a bare name is a
        // container service, a LAN host or an /etc/hosts entry, all of which
        // legitimately speak plain HTTP.
        if (strpos($host, '.') === false) {
            return true;
        }

        // RFC1918 ranges, which is what a docker-compose or LAN setup uses.
        return Fsync_Auth::ip_matches($host, '10.0.0.0/8')
            || Fsync_Auth::ip_matches($host, '172.16.0.0/12')
            || Fsync_Auth::ip_matches($host, '192.168.0.0/16');
    }
}
