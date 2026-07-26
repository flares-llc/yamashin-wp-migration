<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The connection screen: receiver opt-in, key issuing, pairing, credentials.
 */
final class Fsync_Admin_Connection
{
    /**
     * @return array<string, callable>
     */
    public static function handlers()
    {
        return array(
            'toggle_receiver' => [self::class, 'handle_toggle_receiver'],
            'set_env' => [self::class, 'handle_set_env'],
            'issue_key' => [self::class, 'handle_issue_key'],
            'retire_key' => [self::class, 'handle_retire_key'],
            'connect_peer' => [self::class, 'handle_connect_peer'],
            'update_peer_url' => [self::class, 'handle_update_peer_url'],
            'forget_peer' => [self::class, 'handle_forget_peer'],
            'save_credential' => [self::class, 'handle_save_credential'],
            'clear_credential' => [self::class, 'handle_clear_credential'],
            'test_peer' => [self::class, 'handle_test_peer'],
        );
    }

    /**
     * @return void
     */
    public static function render()
    {
        $blob = get_transient('fsync_pairing_blob_' . get_current_user_id());

        print '<div class="wrap fsync">';
        print '<h1>Yamashin WP Migration — 接続</h1>';

        Fsync_Admin::render_notice();

        self::render_environment();
        self::render_receiver();

        if ($blob !== false) {
            self::render_blob($blob);
        }

        self::render_issue_key();
        self::render_keys();
        self::render_connect();
        self::render_peers();
        self::render_credentials();

        print '</div>';
    }

    /**
     * @return void
     */
    private static function render_environment()
    {
        $active = Fsync_Config_Io::active_env();

        print '<h2>この環境</h2>';
        print '<table class="form-table"><tbody><tr>';
        print '<th scope="row">環境名</th><td>';

        Fsync_Admin::form_open('set_env');
        printf(
            '<input type="text" name="env_name" value="%s" class="regular-text" placeholder="local / staging / production">',
            esc_attr($active)
        );
        print ' <button type="submit" class="button">保存</button>';
        print '<p class="description">このインストールがどの環境かを表します。設定ファイルの environment_overrides の選択にも使われます。</p>';
        Fsync_Admin::form_close();

        print '</td></tr></tbody></table>';
    }

    /**
     * @return void
     */
    private static function render_receiver()
    {
        $enabled = Fsync_Auth::receiver_enabled();

        print '<h2>受信</h2>';
        printf(
            '<p>現在の状態: <strong>%s</strong></p>',
            $enabled ? '受信を許可しています' : '受信を許可していません'
        );
        print '<p class="description">受信の可否は設定ファイルではなくこの画面でのみ切り替えられます。'
            . 'リポジトリにコミットされた設定が、別の環境を書き込み先に変えてしまわないようにするためです。</p>';

        Fsync_Admin::form_open('toggle_receiver');
        printf('<input type="hidden" name="enabled" value="%d">', $enabled ? 0 : 1);
        printf(
            '<button type="submit" class="button %s">%s</button>',
            $enabled ? '' : 'button-primary',
            $enabled ? '受信を停止する' : '受信を許可する'
        );
        Fsync_Admin::form_close();
    }

    /**
     * @param array $blob
     * @return void
     */
    private static function render_blob(array $blob)
    {
        delete_transient('fsync_pairing_blob_' . get_current_user_id());

        print '<div class="notice notice-warning"><h2>接続情報（この画面を離れると二度と表示されません）</h2>';
        print '<p>接続元サイトの「接続情報を貼り付け」欄にそのまま貼り付けてください。</p>';
        printf(
            '<textarea class="large-text code" rows="5" readonly onclick="this.select()">%s</textarea>',
            esc_textarea($blob['blob'])
        );
        printf(
            '<p class="description">キーID <code>%s</code> / 有効期限 %s</p>',
            esc_html($blob['key_id']),
            esc_html(wp_date('Y-m-d H:i', $blob['expires_at']))
        );
        print '</div>';
    }

    /**
     * @return void
     */
    private static function render_issue_key()
    {
        print '<h2>接続キーを発行</h2>';
        print '<p class="description">接続を受ける側（ステージングや本番）で発行し、表示された文字列を接続元に貼り付けます。'
            . '共有シークレットが通信経路に乗るのはこの一度だけで、貼り付けが完了すると無効になります。</p>';

        Fsync_Admin::form_open('issue_key');
        print '<table class="form-table"><tbody>';

        print '<tr><th scope="row">接続元の環境名</th><td>';
        print '<input type="text" name="env_name" value="local" class="regular-text">';
        print '<p class="description">このキーを使う相手の名前です。</p></td></tr>';

        print '<tr><th scope="row">権限</th><td>';
        foreach (Fsync_Keys::PRESETS as $preset => $capabilities) {
            printf(
                '<label style="margin-right:1.5em"><input type="radio" name="preset" value="%s" %s> %s <code>%s</code></label>',
                esc_attr($preset),
                $preset === 'deploy' ? 'checked' : '',
                esc_html(self::preset_label($preset)),
                esc_html(implode(', ', $capabilities))
            );
        }
        print '<p class="description">定期的なドリフト監視には「読み取り専用」を使ってください。書き込み権限を持たない鍵は、'
            . '万一漏れても本番を書き換えられません。</p></td></tr>';

        print '<tr><th scope="row">接続用URL</th><td>';
        printf(
            '<input type="url" name="connect_url" value="" class="regular-text" placeholder="%s">',
            esc_attr(home_url('/'))
        );
        print '<p class="description">相手がこのサイトへ実際に接続できるURL。空欄ならサイトURLを使います。'
            . 'ロードバランサー配下や内部ホスト名の場合、あるいは Docker のサービス名で通信する場合に指定します。</p></td></tr>';

        print '<tr><th scope="row">接続元IPの制限</th><td>';
        print '<input type="text" name="ip_allowlist" value="" class="regular-text" placeholder="203.0.113.4, 10.0.0.0/8">';
        print '<p class="description">任意。カンマ区切り。空欄なら制限しません。</p></td></tr>';

        print '</tbody></table>';
        print '<button type="submit" class="button button-primary">接続キーを発行</button>';
        Fsync_Admin::form_close();
    }

    /**
     * @param string $preset
     * @return string
     */
    private static function preset_label($preset)
    {
        $labels = array(
            'readonly' => '読み取り専用',
            'deploy' => '同期・昇格',
            'full' => 'すべて（復元を含む）',
        );

        return $labels[$preset] ?? $preset;
    }

    /**
     * @return void
     */
    private static function render_keys()
    {
        $keys = Fsync_Keys::all();
        if ($keys === array()) {
            return;
        }

        print '<h2>発行済みの接続キー</h2>';
        print '<table class="widefat striped"><thead><tr>';
        print '<th>キーID</th><th>ラベル</th><th>状態</th><th>権限</th><th>最終使用</th><th></th>';
        print '</tr></thead><tbody>';

        foreach ($keys as $key) {
            print '<tr>';
            printf('<td><code>%s</code></td>', esc_html($key['key_id']));
            printf('<td>%s</td>', esc_html($key['label']));
            printf('<td>%s</td>', esc_html(self::key_status_label($key)));
            printf('<td><code>%s</code></td>', esc_html(implode(', ', $key['capabilities'])));
            printf(
                '<td>%s</td>',
                esc_html($key['last_used_at'] > 0 ? wp_date('Y-m-d H:i', $key['last_used_at']) : '未使用')
            );

            print '<td>';
            if ($key['status'] !== Fsync_Keys::STATUS_RETIRED) {
                Fsync_Admin::form_open('retire_key');
                printf('<input type="hidden" name="key_id" value="%s">', esc_attr($key['key_id']));
                print '<button type="submit" class="button button-small">失効</button>';
                Fsync_Admin::form_close();
            }
            print '</td>';

            print '</tr>';
        }

        print '</tbody></table>';
    }

    /**
     * @param array $key
     * @return string
     */
    private static function key_status_label(array $key)
    {
        $labels = array(
            Fsync_Keys::STATUS_PENDING => 'ペアリング待ち',
            Fsync_Keys::STATUS_ACTIVE => '有効',
            Fsync_Keys::STATUS_RETIRED => '失効',
        );

        $label = $labels[$key['status']] ?? $key['status'];

        if ($key['grace_until'] > 0) {
            $label .= sprintf('（猶予期間 %s まで）', wp_date('Y-m-d H:i', $key['grace_until']));
        }

        return $label;
    }

    /**
     * @return void
     */
    private static function render_connect()
    {
        print '<h2>接続情報を貼り付け</h2>';
        print '<p class="description">接続先で発行された文字列を貼り付けます。改行が入っていても構いません。</p>';

        Fsync_Admin::form_open('connect_peer');
        print '<textarea name="blob" class="large-text code" rows="4" required></textarea>';
        print '<p><input type="text" name="env_name" class="regular-text" placeholder="環境名（省略時は接続先の申告値）"></p>';
        print '<button type="submit" class="button button-primary">接続する</button>';
        Fsync_Admin::form_close();
    }

    /**
     * @return void
     */
    private static function render_peers()
    {
        $peers = Fsync_Peer::all();
        if ($peers === array()) {
            return;
        }

        print '<h2>接続済みの環境</h2>';
        print '<table class="widefat striped"><thead><tr>';
        print '<th>環境</th><th>接続先URL</th><th>最終通信</th><th>時刻差</th><th></th>';
        print '</tr></thead><tbody>';

        foreach ($peers as $peer) {
            print '<tr>';
            printf('<td><strong>%s</strong><br><code>%s</code></td>', esc_html($peer['env_name']), esc_html($peer['peer_id']));

            print '<td>';
            Fsync_Admin::form_open('update_peer_url');
            printf('<input type="hidden" name="peer_id" value="%s">', esc_attr($peer['peer_id']));
            printf('<input type="url" name="url" value="%s" class="regular-text">', esc_attr($peer['url']));
            print ' <button type="submit" class="button button-small">保存</button>';
            Fsync_Admin::form_close();
            print '</td>';

            printf(
                '<td>%s</td>',
                esc_html($peer['last_contact_at'] > 0 ? wp_date('Y-m-d H:i', $peer['last_contact_at']) : '未通信')
            );

            printf(
                '<td>%s</td>',
                esc_html($peer['clock_skew'] === 0 ? '-' : sprintf('%+d 秒', $peer['clock_skew']))
            );

            print '<td>';
            Fsync_Admin::form_open('test_peer');
            printf('<input type="hidden" name="peer_id" value="%s">', esc_attr($peer['peer_id']));
            print '<button type="submit" class="button button-small">接続診断</button>';
            Fsync_Admin::form_close();

            Fsync_Admin::form_open('forget_peer');
            printf('<input type="hidden" name="peer_id" value="%s">', esc_attr($peer['peer_id']));
            print '<button type="submit" class="button button-small">削除</button>';
            Fsync_Admin::form_close();
            print '</td>';

            print '</tr>';
        }

        print '</tbody></table>';
    }

    /**
     * @return void
     */
    private static function render_credentials()
    {
        print '<h2>認証情報</h2>';
        print '<p class="description">設定ファイルにはここで登録したIDだけを書きます。値そのものは暗号化して保存され、'
            . '画面にも API にも二度と現れません。</p>';

        $existing = Fsync_Credentials::all();
        $by_id = array();
        foreach ($existing as $meta) {
            $by_id[$meta['credential_id']] = $meta;
        }

        if ($existing !== array()) {
            print '<table class="widefat striped"><thead><tr>';
            print '<th>ID</th><th>種類</th><th>指紋</th><th>更新</th><th></th>';
            print '</tr></thead><tbody>';

            foreach ($existing as $meta) {
                print '<tr>';
                printf('<td><code>%s</code></td>', esc_html($meta['credential_id']));
                printf('<td>%s</td>', esc_html($meta['kind_label']));
                printf('<td><code>%s</code></td>', esc_html($meta['fingerprint']));
                printf('<td>%s</td>', esc_html(wp_date('Y-m-d H:i', $meta['updated_at'])));

                print '<td>';
                Fsync_Admin::form_open('clear_credential');
                printf('<input type="hidden" name="credential_id" value="%s">', esc_attr($meta['credential_id']));
                print '<button type="submit" class="button button-small">クリア</button>';
                Fsync_Admin::form_close();
                print '</td>';

                print '</tr>';
            }

            print '</tbody></table>';
        }

        print '<h3>登録・更新</h3>';
        Fsync_Admin::form_open('save_credential');
        print '<table class="form-table"><tbody>';

        print '<tr><th scope="row">ID</th><td>';
        print '<input type="text" name="credential_id" class="regular-text" required placeholder="ops-webhook">';
        print '</td></tr>';

        print '<tr><th scope="row">種類</th><td><select name="kind">';
        foreach (Fsync_Credentials::KINDS as $kind => $label) {
            printf('<option value="%s">%s</option>', esc_attr($kind), esc_html($label));
        }
        print '</select></td></tr>';

        print '<tr><th scope="row">値</th><td>';
        print '<textarea name="value" class="large-text code" rows="4" required '
            . 'placeholder="共有シークレットやWebhook URL"></textarea>';
        print '<p class="description">保存後は表示されません。</p></td></tr>';

        print '</tbody></table>';
        print '<button type="submit" class="button button-primary">保存</button>';
        Fsync_Admin::form_close();
    }

    // -----------------------------------------------------------------------
    // Handlers
    // -----------------------------------------------------------------------

    /**
     * @return string|WP_Error
     */
    public static function handle_toggle_receiver()
    {
        $enabled = (bool) (int) ($_POST['enabled'] ?? 0);
        Fsync_Auth::set_receiver_enabled($enabled);

        return $enabled ? '受信を許可しました。' : '受信を停止しました。';
    }

    /**
     * @return string|WP_Error
     */
    public static function handle_set_env()
    {
        $env_name = Fsync_Peer::normalize_env_name(wp_unslash($_POST['env_name'] ?? ''));
        if (is_wp_error($env_name)) {
            return $env_name;
        }

        Fsync_Config_Io::set_active_env($env_name);
        update_option('fsync_site_role', $env_name, false);
        Fsync_Config::flush();

        return sprintf('この環境を「%s」として設定しました。', $env_name);
    }

    /**
     * @return string|WP_Error
     */
    public static function handle_issue_key()
    {
        $preset = (string) ($_POST['preset'] ?? 'deploy');
        $capabilities = Fsync_Keys::PRESETS[$preset] ?? Fsync_Keys::PRESETS['readonly'];

        $ips = array_filter(array_map('trim', explode(',', (string) wp_unslash($_POST['ip_allowlist'] ?? ''))));

        $result = Fsync_Pairing::create(
            array(
                'env_name' => wp_unslash($_POST['env_name'] ?? 'local'),
                'label' => wp_unslash($_POST['env_name'] ?? 'local'),
                'capabilities' => $capabilities,
                'ip_allowlist' => $ips,
                'connect_url' => wp_unslash($_POST['connect_url'] ?? ''),
            )
        );

        if (is_wp_error($result)) {
            return $result;
        }

        // Held in a transient rather than a redirect parameter so the secret
        // never appears in a URL, a browser history entry or a server log.
        set_transient('fsync_pairing_blob_' . get_current_user_id(), $result, 300);

        return '接続情報を発行しました。次の画面に一度だけ表示されます。';
    }

    /**
     * @return string|WP_Error
     */
    public static function handle_retire_key()
    {
        $key_id = (string) wp_unslash($_POST['key_id'] ?? '');
        if (Fsync_Keys::find($key_id) === null) {
            return new WP_Error('fsync_key_missing', '接続キーが見つかりません。');
        }

        $retired = Fsync_Keys::retire($key_id);

        return is_wp_error($retired) ? $retired : '接続キーを失効しました。';
    }

    /**
     * @return string|WP_Error
     */
    public static function handle_connect_peer()
    {
        $result = Fsync_Pairing::connect(
            (string) wp_unslash($_POST['blob'] ?? ''),
            (string) wp_unslash($_POST['env_name'] ?? '')
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return sprintf(
            '「%s」と接続しました。権限: %s',
            $result['env_name'],
            implode(', ', $result['capabilities'])
        );
    }

    /**
     * @return string|WP_Error
     */
    public static function handle_update_peer_url()
    {
        $peer = Fsync_Peer::find((string) wp_unslash($_POST['peer_id'] ?? ''));
        if ($peer === null) {
            return new WP_Error('fsync_peer_missing', 'ピアが見つかりません。');
        }

        $url = Fsync_Pairing::normalize_url(wp_unslash($_POST['url'] ?? ''));
        if (is_wp_error($url)) {
            return $url;
        }

        $updated = Fsync_Peer::upsert(
            array(
                'peer_id' => $peer['peer_id'],
                'env_name' => $peer['env_name'],
                'site_role' => $peer['site_role'],
                'url' => $url,
                'outbound_key_id' => $peer['outbound_key_id'],
            )
        );

        if (is_wp_error($updated)) {
            return $updated;
        }

        return '接続先URLを更新しました。';
    }

    /**
     * @return string|WP_Error
     */
    public static function handle_forget_peer()
    {
        $peer = Fsync_Peer::find((string) wp_unslash($_POST['peer_id'] ?? ''));
        if ($peer === null) {
            return new WP_Error('fsync_peer_missing', 'ピアが見つかりません。');
        }

        $forgotten = Fsync_Peer::forget($peer['peer_id']);
        if (is_wp_error($forgotten)) {
            return $forgotten;
        }

        $credential_id = 'peer-' . $peer['env_name'];
        $credential = Fsync_Credentials::meta($credential_id);
        if (is_array($credential) && $credential['kind'] === 'peer') {
            $cleared = Fsync_Credentials::clear($credential_id);
            if (is_wp_error($cleared)) {
                return $cleared;
            }
        }

        return 'ピアと接続用の認証情報を削除しました。';
    }

    /**
     * @return string|WP_Error
     */
    public static function handle_save_credential()
    {
        $result = Fsync_Credentials::put(
            (string) wp_unslash($_POST['credential_id'] ?? ''),
            (string) wp_unslash($_POST['kind'] ?? ''),
            (string) wp_unslash($_POST['value'] ?? '')
        );

        return is_wp_error($result) ? $result : '認証情報を保存しました。';
    }

    /**
     * @return string|WP_Error
     */
    public static function handle_clear_credential()
    {
        $cleared = Fsync_Credentials::clear((string) wp_unslash($_POST['credential_id'] ?? ''));

        return is_wp_error($cleared) ? $cleared : '認証情報を削除しました。';
    }

    /**
     * @return string|WP_Error
     */
    public static function handle_test_peer()
    {
        $peer = Fsync_Peer::find((string) wp_unslash($_POST['peer_id'] ?? ''));
        if ($peer === null) {
            return new WP_Error('fsync_peer_missing', 'ピアが見つかりません。');
        }

        $client = Fsync_Client::for_peer($peer);
        if (is_wp_error($client)) {
            return $client;
        }

        // The unauthenticated echo runs first: when signing fails because a
        // host is stripping headers, the signed call cannot tell us that.
        $echo = $client->echo_test();
        if (is_wp_error($echo)) {
            return new WP_Error(
                'fsync_test_failed',
                sprintf('接続できません: %s', $echo->get_error_message())
            );
        }

        if (! empty($echo['missing_headers'])) {
            return new WP_Error(
                'fsync_headers_stripped',
                sprintf(
                    '次のヘッダーが接続先に届いていません: %s。WAFやセキュリティプラグインの設定を確認してください。',
                    implode(', ', (array) $echo['missing_headers'])
                )
            );
        }

        $handshake = $client->get('handshake');
        if (is_wp_error($handshake)) {
            return new WP_Error(
                'fsync_handshake_failed',
                sprintf('ハンドシェイクに失敗しました: %s', $handshake->get_error_message())
            );
        }

        $local_fingerprint = Fsync_Config::scope_fingerprint($peer['env_name']);
        $remote_fingerprint = (string) ($handshake['scope_fingerprint'] ?? '');
        $fingerprint_note = '';

        if (! is_wp_error($local_fingerprint) && $remote_fingerprint !== '' && $local_fingerprint !== $remote_fingerprint) {
            $fingerprint_note = ' ただし同期スコープの設定が一致していません。両サイトの設定を揃えてください。';
        }

        return sprintf(
            '接続成功: %s (プラグイン %s / 実行制限 %s秒) 権限 %s / チャンク %s%s',
            (string) ($handshake['env_name'] ?? '?'),
            (string) ($handshake['plugin_version'] ?? '?'),
            (string) ($handshake['limits']['max_execution_time'] ?? '?'),
            implode(', ', (array) ($handshake['capabilities'] ?? array())),
            size_format((int) ($handshake['limits']['suggested_chunk_bytes'] ?? 0)),
            $fingerprint_note
        );
    }
}
