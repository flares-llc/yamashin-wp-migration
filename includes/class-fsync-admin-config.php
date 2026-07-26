<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The configuration screen: a builder that generates the JSON document, and an
 * editor for the document itself.
 *
 * The JSON is the artefact. The builder is an input aid that produces it, not a
 * separate source of truth -- which is why it renders its output into the same
 * editor the document is applied from, instead of saving directly.
 */
final class Fsync_Admin_Config
{
    const DRAFT_TRANSIENT = 'fsync_config_draft_';

    /**
     * @return array<string, callable>
     */
    public static function handlers()
    {
        return array(
            'build_config' => [self::class, 'handle_build'],
            // Validate and apply share one action, and therefore one nonce,
            // dispatching on the button that was pressed. Two nonces in one
            // form would have to be swapped by script, which breaks whenever
            // the browser submits by keyboard.
            'submit_config' => [self::class, 'handle_submit'],
        );
    }

    /**
     * @return void
     */
    public static function render()
    {
        $loaded = Fsync_Config_Io::load();
        $file_backed = Fsync_Config_Io::is_file_backed();

        print '<div class="wrap fsync">';
        Fsync_Admin::render_brand_header('設定');

        Fsync_Admin::render_notice();

        self::render_source($loaded, $file_backed);
        self::render_agent_hint();

        if (! $file_backed) {
            self::render_builder();
        }

        self::render_editor($loaded, $file_backed);
        self::render_history();

        print '</div>';
    }

    /**
     * @param array $loaded
     * @param bool $file_backed
     * @return void
     */
    private static function render_source(array $loaded, $file_backed)
    {
        print '<h2>現在の設定</h2>';

        $labels = array(
            Fsync_Config_Io::SOURCE_FILE => '設定ファイル',
            Fsync_Config_Io::SOURCE_DB => 'データベース',
            Fsync_Config_Io::SOURCE_DEFAULT => '未設定（既定値）',
        );

        printf(
            '<p>読み込み元: <strong>%s</strong>%s</p>',
            esc_html($labels[$loaded['source']] ?? $loaded['source']),
            $loaded['path'] === '' ? '' : ' <code>' . esc_html($loaded['path']) . '</code>'
        );

        if ($loaded['error'] !== null) {
            printf(
                '<div class="notice notice-error inline"><p>設定ファイルを読み込めません: %s</p>'
                . '<p>データベース側の設定にはフォールバックしません。編集中の内容と実際に動く内容が食い違うためです。</p></div>',
                esc_html($loaded['error']->get_error_message())
            );
        }

        if ($file_backed) {
            print '<div class="notice notice-info inline"><p>設定ファイルが存在するため、この画面からは保存できません。'
                . 'ファイルを編集してください。ビルダーで生成した内容はファイルに貼り付けて使います。</p></div>';
        }

        $fingerprints = array();
        foreach (array_keys(Fsync_Config::environments()) as $env_name) {
            $fingerprint = Fsync_Config::scope_fingerprint((string) $env_name);
            $fingerprints[(string) $env_name] = is_wp_error($fingerprint) ? '—' : substr($fingerprint, 0, 16);
        }

        if ($fingerprints !== array()) {
            print '<h3>スコープ指紋</h3>';
            print '<p class="description">相手環境ごとに別々に計算されます。接続先と一致していない場合、同期は実行されません。</p>';
            print '<table class="widefat striped" style="max-width:40em"><tbody>';
            foreach ($fingerprints as $env_name => $fingerprint) {
                printf(
                    '<tr><td><strong>%s</strong></td><td><code>%s</code></td></tr>',
                    esc_html($env_name),
                    esc_html($fingerprint)
                );
            }
            print '</tbody></table>';
        }
    }

    /**
     * @return void
     */
    private static function render_agent_hint()
    {
        print '<h2>AI で設定を書く場合</h2>';
        print '<p class="description">次の3つのエンドポイントで完結します。管理画面を開く必要はありません。</p>';
        print '<table class="widefat striped" style="max-width:60em"><tbody>';

        foreach (
            array(
                array('GET', 'config/introspect', 'このサイトの投稿タイプ・オプション一覧などを返します。メタキー統計は必要な場合だけ include_meta_keys=true で取得します。'),
                array('GET', 'config/schema', 'このサイト専用の JSON Schema。実在する投稿タイプだけが候補に入るので、存在しない名前を書けません。'),
                array('POST', 'config/validate', '検証結果を JSON Pointer 付きで返します。修正箇所が機械的に分かります。'),
            ) as $endpoint
        ) {
            printf(
                '<tr><td style="width:30em"><code>%s %s</code></td><td>%s</td></tr>',
                esc_html($endpoint[0]),
                esc_html(rest_url(FSYNC_REST_NAMESPACE . '/' . $endpoint[1])),
                esc_html($endpoint[2])
            );
        }

        print '</tbody></table>';
        print '<p class="description">設定ファイルには認証情報の値を書けません。'
            . '秘密鍵らしき文字列が含まれていると保存が拒否されます。値は「接続」画面で登録し、IDで参照してください。</p>';
    }

    /**
     * A form that generates a document rather than saving one.
     *
     * @return void
     */
    private static function render_builder()
    {
        $introspect = Fsync_Introspect::report(
            array('include_meta_keys' => false, 'include_users' => false)
        );

        $scope = Fsync_Config::scope();
        $selected_types = array_keys((array) ($scope['post_types'] ?? array()));
        $selected_taxonomies = array_keys((array) ($scope['taxonomies'] ?? array()));
        $selected_options = (array) ($scope['options']['allow'] ?? array());

        print '<h2>ビルダー</h2>';
        print '<p class="description">このサイトに実際に登録されているものだけが候補に出ます。'
            . '生成すると下のエディタに JSON が入ります。保存はエディタから行います。</p>';

        Fsync_Admin::form_open('build_config');

        print '<h3>投稿タイプ</h3><div class="fsync-checks">';
        foreach ((array) $introspect['post_types'] as $slug => $info) {
            printf(
                '<label><input type="checkbox" name="post_types[]" value="%s" %s> %s <code>%s</code> '
                . '<span class="description">%d件</span></label>',
                esc_attr($slug),
                in_array($slug, $selected_types, true) ? 'checked' : '',
                esc_html($info['label']),
                esc_html($slug),
                (int) $info['count']
            );
        }
        print '</div>';

        print '<h3>タクソノミー</h3><div class="fsync-checks">';
        foreach ((array) $introspect['taxonomies'] as $slug => $info) {
            printf(
                '<label><input type="checkbox" name="taxonomies[]" value="%s" %s> %s <code>%s</code> '
                . '<span class="description">%d件</span></label>',
                esc_attr($slug),
                in_array($slug, $selected_taxonomies, true) ? 'checked' : '',
                esc_html($info['label']),
                esc_html($slug),
                (int) $info['count']
            );
        }
        print '</div>';

        print '<h3>オプション</h3>';
        print '<p class="description">許可リスト方式です。保護対象のオプションはそもそも一覧に出ません。'
            . '<strong>自動読み込みが有効で容量の大きいもの</strong>は本番の全ページ表示に影響するため印を付けています。</p>';
        print '<div class="fsync-checks fsync-checks-scroll">';
        foreach ((array) $introspect['options'] as $option) {
            printf(
                '<label><input type="checkbox" name="options[]" value="%s" %s> <code>%s</code> '
                . '<span class="description">%s%s%s</span></label>',
                esc_attr($option['name']),
                in_array($option['name'], $selected_options, true) ? 'checked' : '',
                esc_html($option['name']),
                esc_html(size_format(max(1, (int) $option['bytes']))),
                $option['autoload'] ? ' / autoload' : '',
                $option['heavy'] ? ' ⚠ 大きい' : ($option['warn'] ? ' ⚠ 環境差が出やすい' : '')
            );
        }
        print '</div>';

        print '<h3>テーマ</h3><div class="fsync-checks">';
        $selected_themes = (array) ($scope['files']['theme'] ?? array());
        foreach ((array) $introspect['themes'] as $theme) {
            printf(
                '<label><input type="checkbox" name="themes[]" value="%s" %s> %s <code>%s</code>%s</label>',
                esc_attr($theme['slug']),
                in_array($theme['slug'], $selected_themes, true) ? 'checked' : '',
                esc_html($theme['name']),
                esc_html($theme['slug']),
                $theme['active'] ? ' <span class="description">（有効）</span>' : ''
            );
        }
        print '</div>';

        print '<p><button type="submit" class="button button-primary">JSON を生成</button></p>';
        Fsync_Admin::form_close();
    }

    /**
     * @param array $loaded
     * @param bool $file_backed
     * @return void
     */
    private static function render_editor(array $loaded, $file_backed)
    {
        $draft = get_transient(self::DRAFT_TRANSIENT . get_current_user_id());

        if ($draft === false) {
            $document = $loaded['document'] === array() ? Fsync_Config::defaults() : $loaded['document'];
            $encoded = Fsync_Config_Io::pretty($document);
            $draft = is_wp_error($encoded) ? '{}' : $encoded;
        }

        print '<h2>設定 JSON</h2>';
        print '<p class="description">JSONC（<code>//</code> コメントと末尾カンマ）が使えます。</p>';

        Fsync_Admin::form_open('submit_config');
        printf('<textarea name="raw" class="large-text code" rows="24">%s</textarea>', esc_textarea((string) $draft));
        print '<p>';
        print '<button type="submit" name="mode" value="validate" class="button">検証する</button> ';

        if (! $file_backed) {
            print '<button type="submit" name="mode" value="apply" class="button button-primary">検証して適用</button>';
        }

        print '</p>';
        Fsync_Admin::form_close();

        $result = get_transient('fsync_config_result_' . get_current_user_id());
        if (is_array($result)) {
            delete_transient('fsync_config_result_' . get_current_user_id());
            self::render_issues($result);
        }
    }

    /**
     * @param array $result
     * @return void
     */
    private static function render_issues(array $result)
    {
        if ($result['errors'] === array() && $result['warnings'] === array()) {
            print '<div class="notice notice-success inline"><p>問題は見つかりませんでした。</p></div>';

            return;
        }

        foreach (array('errors' => 'error', 'warnings' => 'warning') as $bucket => $class) {
            if ($result[$bucket] === array()) {
                continue;
            }

            printf('<div class="notice notice-%s inline"><ul class="fsync-issues">', esc_attr($class));

            foreach ($result[$bucket] as $issue) {
                printf(
                    '<li><code>%s</code> <strong>%s</strong> — %s</li>',
                    esc_html($issue['pointer']),
                    esc_html($issue['code']),
                    esc_html($issue['message'])
                );
            }

            print '</ul></div>';
        }
    }

    /**
     * @return void
     */
    private static function render_history()
    {
        $history = Fsync_Config_Io::history(10);
        if ($history === array()) {
            return;
        }

        print '<h2>変更履歴</h2>';
        print '<table class="widefat striped"><thead><tr>';
        print '<th>日時</th><th>読み込み元</th><th>ハッシュ</th><th>メモ</th>';
        print '</tr></thead><tbody>';

        foreach ($history as $entry) {
            printf(
                '<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td></tr>',
                esc_html(wp_date('Y-m-d H:i', (int) $entry['ts'])),
                esc_html((string) $entry['source']),
                esc_html(substr((string) $entry['config_hash'], 0, 16)),
                esc_html((string) $entry['note'])
            );
        }

        print '</tbody></table>';
    }

    // -----------------------------------------------------------------------
    // Handlers
    // -----------------------------------------------------------------------

    /**
     * Turn the builder's checkboxes into a document and hand it to the editor.
     *
     * @return string|WP_Error
     */
    public static function handle_build()
    {
        $document = Fsync_Config_Io::load()['document'];
        $document = $document === array() ? Fsync_Config::defaults() : $document;

        $post_types = array();
        foreach ((array) ($_POST['post_types'] ?? array()) as $slug) {
            $slug = sanitize_key(wp_unslash($slug));

            // Preserve any hand-written detail for a type that is still
            // selected; the builder must not silently discard meta rules the
            // operator wrote in the editor.
            $post_types[$slug] = (array) ($document['sync']['scope']['post_types'][$slug] ?? array());

            if ($post_types[$slug] === array()) {
                $post_types[$slug] = array(
                    'statuses' => $slug === 'attachment' ? array('inherit') : array('publish', 'draft'),
                    'meta' => array('mode' => 'all'),
                    'delete' => false,
                );
            }
        }

        $taxonomies = array();
        foreach ((array) ($_POST['taxonomies'] ?? array()) as $slug) {
            $slug = sanitize_key(wp_unslash($slug));
            $taxonomies[$slug] = (array) ($document['sync']['scope']['taxonomies'][$slug] ?? array('meta' => array('mode' => 'all')));
        }

        $options = array();
        foreach ((array) ($_POST['options'] ?? array()) as $name) {
            $name = sanitize_text_field(wp_unslash($name));
            if ($name !== '' && ! Fsync_Config::is_protected_option($name)) {
                $options[] = $name;
            }
        }

        $themes = array();
        foreach ((array) ($_POST['themes'] ?? array()) as $slug) {
            $themes[] = sanitize_text_field(wp_unslash($slug));
        }

        $document['sync']['scope']['post_types'] = $post_types;
        $document['sync']['scope']['taxonomies'] = $taxonomies;
        $document['sync']['scope']['options']['allow'] = array_values(array_unique($options));
        $document['sync']['scope']['files']['theme'] = array_values(array_unique($themes));

        $encoded = Fsync_Config_Io::pretty($document);
        if (is_wp_error($encoded)) {
            return $encoded;
        }

        set_transient(self::DRAFT_TRANSIENT . get_current_user_id(), $encoded, 600);

        return 'JSON を生成しました。内容を確認して適用してください。';
    }

    /**
     * @return string|WP_Error
     */
    public static function handle_submit()
    {
        $apply = (string) ($_POST['mode'] ?? 'validate') === 'apply';

        $raw = (string) wp_unslash($_POST['raw'] ?? '');
        set_transient(self::DRAFT_TRANSIENT . get_current_user_id(), $raw, 600);

        $document = Fsync_Config_Io::parse($raw);
        if (is_wp_error($document)) {
            return $document;
        }

        $result = Fsync_Config_Validate::check($document, self::context());
        set_transient('fsync_config_result_' . get_current_user_id(), $result, 60);

        if (! $result['ok']) {
            return new WP_Error(
                'fsync_config_invalid',
                $apply
                    ? sprintf('エラーがあるため適用しませんでした（%d件）。', count($result['errors']))
                    : sprintf('エラー %d件 / 警告 %d件', count($result['errors']), count($result['warnings']))
            );
        }

        if (! $apply) {
            return sprintf('検証OK（警告 %d件）', count($result['warnings']));
        }

        $saved = Fsync_Config_Io::save($document, '管理画面から適用');
        if (is_wp_error($saved)) {
            return $saved;
        }

        Fsync_Config::flush();
        delete_transient(self::DRAFT_TRANSIENT . get_current_user_id());

        return sprintf('設定を適用しました（警告 %d件）。', count($result['warnings']));
    }

    /**
     * @return array
     */
    private static function context()
    {
        return array(
            'introspect' => Fsync_Introspect::report(
                array('include_meta_keys' => false, 'include_options' => false, 'include_users' => false)
            ),
            'credentials' => array_column(Fsync_Credentials::all(), 'credential_id'),
        );
    }
}
