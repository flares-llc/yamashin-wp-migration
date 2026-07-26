<?php

if (! defined('ABSPATH')) {
    exit;
}

/** Migration wizard, release review, rollback and MCP token management. */
final class Fsync_Admin_Migration
{
    public static function handlers()
    {
        return array(
            'migration_create' => [self::class, 'handle_create'],
            'migration_continue_job' => [self::class, 'handle_continue_job'],
            'migration_resolve_job' => [self::class, 'handle_resolve_job'],
            'migration_confirm_job_deletes' => [self::class, 'handle_confirm_job_deletes'],
            'migration_confirm_job' => [self::class, 'handle_confirm_job'],
            'migration_cancel_job' => [self::class, 'handle_cancel_job'],
            'migration_dry_run' => [self::class, 'handle_dry_run'],
            'migration_resolve' => [self::class, 'handle_resolve'],
            'migration_confirm_deletes' => [self::class, 'handle_confirm_deletes'],
            'migration_apply' => [self::class, 'handle_apply'],
            'migration_rollback' => [self::class, 'handle_rollback'],
            'mcp_token_issue' => [self::class, 'handle_mcp_issue'],
            'mcp_token_retire' => [self::class, 'handle_mcp_retire'],
        );
    }

    public static function render()
    {
        if (! current_user_can(Fsync_Admin::CAPABILITY)) {
            return;
        }
        print '<div class="wrap fsync-admin">';
        Fsync_Admin::render_brand_header('移行');
        Fsync_Admin::render_notice();
        self::render_one_time_token();
        self::render_create();
        self::render_jobs();
        self::render_releases();
        self::render_snapshots();
        self::render_mcp();
        print '</div>';
    }

    private static function render_create()
    {
        $peers = Fsync_Peer::all();
        print '<section class="fsync-panel"><h2>1. 移行先と範囲</h2>';
        if ($peers === array()) {
            print '<p>先に「接続」で移行先をペアリングしてください。</p></section>';

            return;
        }
        print '<p>リリース作成は読み取り専用です。接続先へ転送した後も、ドライランの確認なしに適用されません。</p>';
        Fsync_Admin::form_open('migration_create');
        print '<table class="form-table"><tbody><tr><th scope="row">移行先</th><td><select name="peer_id" required>';
        foreach ($peers as $peer) {
            printf('<option value="%s">%s — %s</option>', esc_attr($peer['peer_id']), esc_html($peer['env_name']), esc_html($peer['url']));
        }
        print '</select></td></tr><tr><th scope="row">方向</th><td>';
        print '<label><input type="radio" name="direction" value="push" checked> このサイト → 接続先（push）</label><br>';
        print '<label><input type="radio" name="direction" value="pull"> 接続先 → このサイト（pull）</label>';
        print '<p class="description">pullは接続先から戻る方向にもペアリングされている必要があります。方向はリリース作成後に変更できません。</p>';
        print '</td></tr><tr><th scope="row">プロファイル</th><td>';
        print '<label><input type="radio" name="profile" value="full" checked> サイト全体</label> ';
        print '<label><input type="radio" name="profile" value="content"> コンテンツのみ</label>';
        print '<p class="description">サイト全体でも接続先URL、秘密情報、接続状態は保持されます。</p>';
        print '</td></tr></tbody></table><button class="button button-primary" type="submit">差分リリースを作成</button>';
        Fsync_Admin::form_close();
        print '</section>';
    }

    private static function render_jobs()
    {
        $jobs = Fsync_Job::all(20);
        print '<section class="fsync-panel"><h2>2. 転送と進捗</h2>';
        if ($jobs === array()) {
            print '<p>ジョブはまだありません。</p></section>';

            return;
        }
        print '<table class="widefat striped"><thead><tr><th>ジョブ</th><th>状態</th><th>進捗</th><th>操作</th></tr></thead><tbody>';
        foreach ($jobs as $job) {
            print '<tr>';
            printf('<td><code>%s</code><br>%s</td>', esc_html($job['job_id']), esc_html($job['operation']));
            printf('<td><strong>%s</strong><br>%s%s</td>', esc_html($job['status']), esc_html($job['phase']), $job['error'] === '' ? '' : '<br><span class="fsync-error">' . esc_html($job['error']) . '</span>');
            printf('<td>%d / %d</td>', (int) $job['progress'], (int) $job['total']);
            print '<td>';
            if (in_array($job['status'], array(Fsync_Job::STATUS_QUEUED, Fsync_Job::STATUS_RUNNING, Fsync_Job::STATUS_FAILED), true)) {
                Fsync_Admin::form_open('migration_continue_job');
                printf('<input type="hidden" name="job_id" value="%s">', esc_attr($job['job_id']));
                print '<button class="button" type="submit">次の処理を実行</button>';
                Fsync_Admin::form_close();
            }
            if ($job['status'] === Fsync_Job::STATUS_AWAITING_CONFIRMATION) {
                $summary = (array) ($job['result']['summary'] ?? array());
                self::render_counts((array) ($summary['counts'] ?? array()));
                $items = (array) ($job['result']['items'] ?? array());
                $conflicts = array_values(array_filter($items, static function ($item) {
                    return ($item['action'] ?? '') === Fsync_Diff::ACTION_CONFLICT && empty($item['resolution']);
                }));
                $deletes = array_values(array_filter($items, static function ($item) {
                    return ($item['action'] ?? '') === Fsync_Diff::ACTION_DELETE && ($item['resolution'] ?? '') !== 'source';
                }));
                if ($conflicts !== array()) {
                    Fsync_Admin::form_open('migration_resolve_job');
                    printf('<input type="hidden" name="job_id" value="%s"><input type="hidden" name="plan_hash" value="%s">', esc_attr($job['job_id']), esc_attr((string) ($job['result']['plan_hash'] ?? '')));
                    print '<p><strong>競合を解決してください</strong></p><table class="widefat"><tbody>';
                    foreach ($conflicts as $item) {
                        printf('<tr><td><code>%s</code></td><td><select required name="resolutions[%s]"><option value="">選択してください</option><option value="source">移行元を採用</option><option value="target">移行先を維持</option><option value="skip">今回はスキップ</option></select></td></tr>', esc_html($item['item_key']), esc_attr($item['item_key']));
                    }
                    print '</tbody></table><button class="button" type="submit">競合解決を接続先へ保存</button>';
                    Fsync_Admin::form_close();
                } elseif ($deletes !== array()) {
                    Fsync_Admin::form_open('migration_confirm_job_deletes');
                    printf('<input type="hidden" name="job_id" value="%s"><input type="hidden" name="plan_hash" value="%s">', esc_attr($job['job_id']), esc_attr((string) ($job['result']['plan_hash'] ?? '')));
                    printf('<label><input type="checkbox" name="confirm" value="1" required> 接続先で削除される%d件を確認しました</label><br>', count($deletes));
                    print '<button class="button button-link-delete" type="submit">削除対象を確定</button>';
                    Fsync_Admin::form_close();
                } else {
                    Fsync_Admin::form_open('migration_confirm_job');
                    printf('<input type="hidden" name="job_id" value="%s">', esc_attr($job['job_id']));
                    printf('<input type="hidden" name="plan_hash" value="%s">', esc_attr((string) ($job['result']['plan_hash'] ?? '')));
                    print '<label><input type="checkbox" name="confirm" value="1" required> このplan_hashの変更内容を適用する</label><br>';
                    print '<button class="button button-primary" type="submit">接続先へ適用</button>';
                    Fsync_Admin::form_close();
                }
            }
            if (! in_array($job['status'], array(Fsync_Job::STATUS_COMPLETE, Fsync_Job::STATUS_CANCELLED), true)) {
                Fsync_Admin::form_open('migration_cancel_job');
                printf('<input type="hidden" name="job_id" value="%s">', esc_attr($job['job_id']));
                print '<button class="button button-link-delete" type="submit">中止</button>';
                Fsync_Admin::form_close();
            }
            print '</td></tr>';
        }
        print '</tbody></table></section>';
    }

    private static function render_releases()
    {
        $releases = Fsync_Release::all(20);
        print '<section class="fsync-panel"><h2>3. ドライランと適用</h2>';
        if ($releases === array()) {
            print '<p>リリースはまだありません。</p></section>';

            return;
        }
        foreach ($releases as $release) {
            $items = Fsync_Release::items($release['release_id']);
            printf('<details class="fsync-release"%s><summary><code>%s</code> <strong>%s</strong> — %s</summary>', $release === reset($releases) ? ' open' : '', esc_html($release['release_id']), esc_html($release['status']), esc_html(wp_date('Y-m-d H:i', $release['created_at'])));
            self::render_counts((array) ($release['summary']['counts'] ?? array()));
            printf('<p>plan_hash <code>%s</code></p>', esc_html($release['plan_hash'] ?: '未確定'));
            $preflight = (array) ($release['summary']['preflight'] ?? array());
            foreach ((array) ($preflight['blockers'] ?? array()) as $blocker) {
                printf('<p class="fsync-error">阻害: %s</p>', esc_html(is_array($blocker) ? ($blocker['message'] ?? '') : $blocker));
            }
            foreach ((array) ($preflight['warnings'] ?? array()) as $warning) {
                printf('<p class="fsync-warning">注意: %s</p>', esc_html(is_array($warning) ? ($warning['message'] ?? '') : $warning));
            }

            if ($release['status'] === Fsync_Release::STATUS_AWAITING_OBJECTS) {
                Fsync_Admin::form_open('migration_dry_run');
                printf('<input type="hidden" name="release_id" value="%s">', esc_attr($release['release_id']));
                print '<button class="button" type="submit">オブジェクトを再確認</button>';
                Fsync_Admin::form_close();
            }

            $conflicts = array_filter($items, static function ($item) {
                return $item['action'] === Fsync_Diff::ACTION_CONFLICT;
            });
            if ($conflicts !== array()) {
                print '<h3>競合</h3>';
                Fsync_Admin::form_open('migration_resolve');
                printf('<input type="hidden" name="release_id" value="%s"><input type="hidden" name="plan_hash" value="%s">', esc_attr($release['release_id']), esc_attr($release['plan_hash']));
                print '<table class="widefat"><thead><tr><th>対象</th><th>解決</th></tr></thead><tbody>';
                foreach ($conflicts as $item) {
                    printf('<tr><td><code>%s</code></td><td><select name="resolutions[%s]">', esc_html($item['item_key']), esc_attr($item['item_key']));
                    foreach (array('' => '選択してください', 'source' => '移行元を採用', 'target' => '移行先を維持', 'skip' => '今回はスキップ') as $value => $label) {
                        printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($item['resolution'], $value, false), esc_html($label));
                    }
                    print '</select></td></tr>';
                }
                print '</tbody></table><button class="button" type="submit">競合解決を保存して再確認</button>';
                Fsync_Admin::form_close();
            }

            $deletes = array_filter($items, static function ($item) {
                return $item['action'] === Fsync_Diff::ACTION_DELETE && $item['resolution'] !== 'source';
            });
            if ($deletes !== array()) {
                Fsync_Admin::form_open('migration_confirm_deletes');
                printf('<input type="hidden" name="release_id" value="%s"><input type="hidden" name="plan_hash" value="%s">', esc_attr($release['release_id']), esc_attr($release['plan_hash']));
                printf('<label><input type="checkbox" name="confirm" value="1" required> %d件の削除対象を確認しました</label> ', count($deletes));
                print '<button class="button button-link-delete" type="submit">削除を計画へ含める</button>';
                Fsync_Admin::form_close();
            }

            if ($release['status'] === Fsync_Release::STATUS_DRY_RUN_READY && self::has_confirmation($release['release_id'])) {
                Fsync_Admin::form_open('migration_apply');
                printf('<input type="hidden" name="release_id" value="%s"><input type="hidden" name="plan_hash" value="%s">', esc_attr($release['release_id']), esc_attr($release['plan_hash']));
                print '<label><input type="checkbox" name="confirm" value="1" required> このplan_hashを適用し、失敗時は自動ロールバックする</label><br>';
                print '<button class="button button-primary" type="submit">このサイトへ適用</button>';
                Fsync_Admin::form_close();
            }
            print '</details>';
        }
        print '</section>';
    }

    private static function render_snapshots()
    {
        $snapshots = Fsync_Snapshot::all(20);
        print '<section class="fsync-panel"><h2>4. ロールバック</h2>';
        if ($snapshots === array()) {
            print '<p>利用できるスナップショットはありません。</p></section>';

            return;
        }
        print '<table class="widefat striped"><thead><tr><th>スナップショット</th><th>状態</th><th>期限</th><th>操作</th></tr></thead><tbody>';
        foreach ($snapshots as $snapshot) {
            $release = Fsync_Release::get((string) $snapshot['release_id']);
            $plan_hash = is_wp_error($release) ? '' : (string) $release['plan_hash'];
            printf('<tr><td><code>%s</code><br>release <code>%s</code></td><td>%s</td><td>%s</td><td>', esc_html($snapshot['snapshot_id']), esc_html($snapshot['release_id']), esc_html($snapshot['status']), esc_html(wp_date('Y-m-d H:i', $snapshot['expires_at'])));
            Fsync_Admin::form_open('migration_rollback');
            printf('<input type="hidden" name="snapshot_id" value="%s"><input type="hidden" name="plan_hash" value="%s">', esc_attr($snapshot['snapshot_id']), esc_attr($plan_hash));
            print '<label><input type="checkbox" name="confirm" value="1" required> 復元内容を確認した</label> ';
            print '<button class="button button-link-delete" type="submit">ロールバック</button>';
            Fsync_Admin::form_close();
            print '</td></tr>';
        }
        print '</tbody></table></section>';
    }

    private static function render_mcp()
    {
        print '<section class="fsync-panel"><h2>AI / MCP</h2>';
        printf('<p>Streamable HTTP endpoint: <code>%s</code></p>', esc_html(rest_url(FSYNC_REST_NAMESPACE . '/mcp')));
        print '<p>トークンは発行時に一度だけ表示されます。AIの役割に必要な最小権限を選んでください。</p>';
        Fsync_Admin::form_open('mcp_token_issue');
        print '<table class="form-table"><tbody><tr><th scope="row">ラベル</th><td><input name="label" class="regular-text" required></td></tr>';
        print '<tr><th scope="row">権限</th><td><select name="preset"><option value="readonly">読み取り専用</option><option value="deploy" selected>移行</option><option value="full">ロールバックを含む</option></select></td></tr>';
        print '<tr><th scope="row">ブラウザOrigin</th><td><textarea name="origins" class="large-text code" rows="2" placeholder="https://ai.example.com"></textarea><p class="description">任意、1行1件。stdio/CLIはOriginを送らないため空欄で利用できます。</p></td></tr></tbody></table>';
        print '<button class="button" type="submit">MCPトークンを発行</button>';
        Fsync_Admin::form_close();

        $tokens = Fsync_Mcp_Token::all();
        if ($tokens !== array()) {
            print '<table class="widefat striped"><thead><tr><th>ID</th><th>ラベル</th><th>権限</th><th>最終使用</th><th></th></tr></thead><tbody>';
            foreach ($tokens as $token) {
                printf('<tr><td><code>%s</code></td><td>%s</td><td><code>%s</code></td><td>%s</td><td>', esc_html($token['token_id']), esc_html($token['label']), esc_html(implode(', ', $token['capabilities'])), esc_html($token['last_used_at'] ? wp_date('Y-m-d H:i', $token['last_used_at']) : '未使用'));
                if ($token['status'] === 'active') {
                    Fsync_Admin::form_open('mcp_token_retire');
                    printf('<input type="hidden" name="token_id" value="%s"><button class="button button-small" type="submit">失効</button>', esc_attr($token['token_id']));
                    Fsync_Admin::form_close();
                }
                print '</td></tr>';
            }
            print '</tbody></table>';
        }
        print '</section>';
    }

    public static function handle_create()
    {
        $peer_id = sanitize_text_field(wp_unslash($_POST['peer_id'] ?? ''));
        $profile = sanitize_key(wp_unslash($_POST['profile'] ?? 'full'));
        $direction = sanitize_key(wp_unslash($_POST['direction'] ?? 'push'));
        $idempotency_key = Fsync_Utils::random_hex(16);
        if (is_wp_error($idempotency_key)) {
            return $idempotency_key;
        }
        if ($direction === 'pull') {
            $job = Fsync_Job::create_pull($peer_id, $profile, $idempotency_key);

            return is_wp_error($job) ? $job : 'pullジョブを開始しました。接続先の差分をこのサイトへ取得します。';
        }
        if ($direction !== 'push') {
            return new WP_Error('fsync_direction_invalid', '方向はpushまたはpullです。');
        }
        $created = Fsync_Release::create($peer_id, $profile, 'push', $idempotency_key);
        if (is_wp_error($created)) {
            return $created;
        }
        $job = Fsync_Job::create('push_release', array('peer_id' => $created['release']['peer_id']), $created['release']['release_id']);

        return is_wp_error($job) ? $job : '差分リリースを作成し、転送ジョブを開始しました。';
    }

    public static function handle_continue_job()
    {
        $job = Fsync_Job::run(sanitize_text_field(wp_unslash($_POST['job_id'] ?? '')));

        return is_wp_error($job) ? $job : sprintf('ジョブを進めました。現在: %s / %s', $job['status'], $job['phase']);
    }

    public static function handle_confirm_job()
    {
        if (empty($_POST['confirm'])) {
            return new WP_Error('fsync_confirmation_required', '適用確認が必要です。');
        }
        $job = Fsync_Job::confirm_remote_apply(sanitize_text_field(wp_unslash($_POST['job_id'] ?? '')), sanitize_text_field(wp_unslash($_POST['plan_hash'] ?? '')));

        return is_wp_error($job) ? $job : '接続先へリリースを適用し、検証しました。';
    }

    public static function handle_resolve_job()
    {
        $resolutions = array_map('sanitize_key', (array) wp_unslash($_POST['resolutions'] ?? array()));
        $job = Fsync_Job::resolve_remote(
            sanitize_text_field(wp_unslash($_POST['job_id'] ?? '')),
            sanitize_text_field(wp_unslash($_POST['plan_hash'] ?? '')),
            $resolutions
        );

        return is_wp_error($job) ? $job : '接続先の競合解決を更新し、新しいplan_hashを発行しました。';
    }

    public static function handle_confirm_job_deletes()
    {
        if (empty($_POST['confirm'])) {
            return new WP_Error('fsync_confirmation_required', '削除対象の確認が必要です。');
        }
        $job = Fsync_Job::confirm_remote_deletes(
            sanitize_text_field(wp_unslash($_POST['job_id'] ?? '')),
            sanitize_text_field(wp_unslash($_POST['plan_hash'] ?? ''))
        );

        return is_wp_error($job) ? $job : '接続先の削除対象を確定し、新しいplan_hashを発行しました。';
    }

    public static function handle_cancel_job()
    {
        $job = Fsync_Job::cancel(sanitize_text_field(wp_unslash($_POST['job_id'] ?? '')));

        return is_wp_error($job) ? $job : 'ジョブを中止しました。';
    }

    public static function handle_dry_run()
    {
        $release = Fsync_Release::finalize_dry_run(sanitize_text_field(wp_unslash($_POST['release_id'] ?? '')));

        return self::remember_confirmation($release, 'ドライランが完了しました。');
    }

    public static function handle_resolve()
    {
        $resolutions = array_map('sanitize_key', (array) wp_unslash($_POST['resolutions'] ?? array()));
        $release = Fsync_Release::resolve(
            sanitize_text_field(wp_unslash($_POST['release_id'] ?? '')),
            sanitize_text_field(wp_unslash($_POST['plan_hash'] ?? '')),
            $resolutions
        );

        return self::remember_confirmation($release, '競合解決を計画へ反映しました。');
    }

    public static function handle_confirm_deletes()
    {
        if (empty($_POST['confirm'])) {
            return new WP_Error('fsync_confirmation_required', '削除対象の確認が必要です。');
        }
        $release = Fsync_Release::confirm_deletes(
            sanitize_text_field(wp_unslash($_POST['release_id'] ?? '')),
            sanitize_text_field(wp_unslash($_POST['plan_hash'] ?? ''))
        );

        return self::remember_confirmation($release, '削除対象を確定しました。');
    }

    public static function handle_apply()
    {
        if (empty($_POST['confirm'])) {
            return new WP_Error('fsync_confirmation_required', '適用確認が必要です。');
        }
        $release_id = sanitize_text_field(wp_unslash($_POST['release_id'] ?? ''));
        $ciphertext = get_transient(self::confirmation_key($release_id));
        if (! is_string($ciphertext) || $ciphertext === '') {
            return new WP_Error('fsync_confirmation_expired', '適用確認の有効期限が切れました。ドライランをやり直してください。');
        }
        $confirmation = Fsync_Crypto::decrypt($ciphertext, 'admin-confirmation', $release_id . ':' . get_current_user_id());
        if (is_wp_error($confirmation)) {
            return $confirmation;
        }
        delete_transient(self::confirmation_key($release_id));
        $idempotency_key = Fsync_Utils::random_hex(16);
        if (is_wp_error($idempotency_key)) {
            return $idempotency_key;
        }
        $result = Fsync_Job::queue_apply($release_id, sanitize_text_field(wp_unslash($_POST['plan_hash'] ?? '')), $confirmation, $idempotency_key);

        return is_wp_error($result) ? $result : '適用ジョブを開始しました。進捗欄から継続・確認できます。';
    }

    public static function handle_rollback()
    {
        if (empty($_POST['confirm'])) {
            return new WP_Error('fsync_confirmation_required', 'ロールバック確認が必要です。');
        }
        $snapshot_id = sanitize_text_field(wp_unslash($_POST['snapshot_id'] ?? ''));
        $authorized = Fsync_Snapshot::authorize_rollback($snapshot_id, sanitize_text_field(wp_unslash($_POST['plan_hash'] ?? '')));
        $result = is_wp_error($authorized) ? $authorized : Fsync_Snapshot::restore($snapshot_id);

        return is_wp_error($result) ? $result : 'スナップショットから復元しました。';
    }

    public static function handle_mcp_issue()
    {
        $preset = sanitize_key(wp_unslash($_POST['preset'] ?? 'readonly'));
        $capabilities = Fsync_Keys::PRESETS[$preset] ?? Fsync_Keys::PRESETS['readonly'];
        $origins = preg_split('/\r\n|\r|\n/', (string) wp_unslash($_POST['origins'] ?? ''));
        $issued = Fsync_Mcp_Token::issue(sanitize_text_field(wp_unslash($_POST['label'] ?? '')), $capabilities, array_filter(array_map('trim', (array) $origins)));
        if (is_wp_error($issued)) {
            return $issued;
        }
        set_transient('fsync_mcp_token_' . get_current_user_id(), $issued, 300);

        return 'MCPトークンを発行しました。今だけ表示される値を安全な場所へ保存してください。';
    }

    public static function handle_mcp_retire()
    {
        $result = Fsync_Mcp_Token::retire(sanitize_text_field(wp_unslash($_POST['token_id'] ?? '')));

        return is_wp_error($result) ? $result : 'MCPトークンを失効しました。';
    }

    private static function remember_confirmation($release, $message)
    {
        if (is_wp_error($release)) {
            return $release;
        }
        $confirmation = (string) ($release['confirmation'] ?? '');
        if ($confirmation === '') {
            return new WP_Error('fsync_confirmation_missing', '適用確認を生成できません。');
        }
        $id = (string) $release['release_id'];
        $ciphertext = Fsync_Crypto::encrypt($confirmation, 'admin-confirmation', $id . ':' . get_current_user_id());
        if (is_wp_error($ciphertext)) {
            return $ciphertext;
        }
        set_transient(self::confirmation_key($id), $ciphertext, 1800);

        return $message;
    }

    private static function confirmation_key($release_id)
    {
        return 'fsync_apply_confirm_' . get_current_user_id() . '_' . substr(hash('sha256', $release_id), 0, 12);
    }

    private static function has_confirmation($release_id)
    {
        return is_string(get_transient(self::confirmation_key($release_id)));
    }

    private static function render_counts(array $counts)
    {
        if ($counts === array()) {
            return;
        }
        print '<ul class="fsync-counts">';
        foreach ($counts as $name => $count) {
            printf('<li><strong>%d</strong> %s</li>', (int) $count, esc_html($name));
        }
        print '</ul>';
    }

    private static function render_one_time_token()
    {
        $key = 'fsync_mcp_token_' . get_current_user_id();
        $issued = get_transient($key);
        if (! is_array($issued)) {
            return;
        }
        delete_transient($key);
        print '<div class="notice notice-warning"><p><strong>MCPトークンは再表示できません。</strong></p>';
        printf('<textarea class="large-text code" rows="2" readonly onclick="this.select()">%s</textarea>', esc_textarea($issued['token']));
        print '</div>';
    }
}
