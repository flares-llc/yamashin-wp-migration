<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Diagnostics.
 *
 * "It is not running" and "it cannot decrypt anything" are the two support
 * questions this plugin will generate, and both have causes that are invisible
 * from anywhere else. This screen exists so that the answer is on one page.
 */
final class Fsync_Admin_Health
{
    /**
     * @return void
     */
    public static function render()
    {
        print '<div class="wrap fsync">';
        print '<h1>Flares Sync — 診断</h1>';

        Fsync_Admin::render_notice();

        self::render_crypto();
        self::render_blockers();
        self::render_storage();
        self::render_environment();
        self::render_log();

        print '</div>';
    }

    /**
     * @return void
     */
    private static function render_crypto()
    {
        $check = Fsync_Crypto::check();
        $stale = Fsync_Credentials::stale();

        print '<h2>暗号化キー</h2>';

        $sources = array(
            'constant' => 'wp-config.php の FSYNC_ENCRYPTION_KEY（推奨）',
            'keyfile' => '保存領域のキーファイル',
            'salts' => 'WordPress のソルト由来（非推奨）',
            'none' => '解決できません',
        );

        printf(
            '<div class="notice notice-%s inline"><p><strong>%s</strong></p><p>キーの取得元: %s</p></div>',
            $check['ok'] ? 'success' : 'error',
            esc_html($check['message']),
            esc_html($sources[$check['source']] ?? $check['source'])
        );

        if ($check['source'] === 'salts') {
            print '<div class="notice notice-warning inline"><p>ソルト由来のキーを使っています。'
                . '<strong>WordPress のソルトを再生成すると、保存済みの認証情報がすべて復号できなくなります。</strong>'
                . '次の行を wp-config.php に追加して、専用キーに切り替えることを強く推奨します。</p>';

            $generated = Fsync_Crypto::generate_key();
            if (! is_wp_error($generated)) {
                printf(
                    '<textarea class="large-text code" rows="1" readonly onclick="this.select()">%s</textarea>',
                    esc_textarea(sprintf("define('FSYNC_ENCRYPTION_KEY', '%s');", $generated))
                );
                print '<p class="description">この値は表示のたびに新しく生成されます。'
                    . '切り替える際は、既存の認証情報を登録し直してください。</p>';
            }

            print '</div>';
        }

        if ($stale !== array()) {
            printf(
                '<div class="notice notice-error inline"><p>次の認証情報は現在のキーで復号できません: <code>%s</code></p>'
                . '<p>登録し直してください。</p></div>',
                esc_html(implode(', ', $stale))
            );
        }
    }

    /**
     * @return void
     */
    private static function render_blockers()
    {
        $blockers = Fsync_Env::blockers();
        $warnings = Fsync_Env::warnings();

        if ($blockers === array() && $warnings === array()) {
            print '<h2>実行環境</h2>';
            print '<div class="notice notice-success inline"><p>実行環境に問題はありません。</p></div>';

            return;
        }

        print '<h2>実行環境</h2>';

        foreach ($blockers as $blocker) {
            printf(
                '<div class="notice notice-error inline"><p><code>%s</code> %s</p></div>',
                esc_html($blocker['code']),
                esc_html($blocker['message'])
            );
        }

        foreach ($warnings as $warning) {
            printf(
                '<div class="notice notice-warning inline"><p><code>%s</code> %s</p></div>',
                esc_html($warning['code']),
                esc_html($warning['message'])
            );
        }
    }

    /**
     * @return void
     */
    private static function render_storage()
    {
        print '<h2>保存領域</h2>';

        $dir = Fsync_Fs::private_dir();
        $exists = is_dir($dir);
        $writable = $exists && is_writable($dir);

        printf(
            '<p><code>%s</code> — %s</p>',
            esc_html($dir),
            esc_html($exists ? ($writable ? '書き込み可' : '書き込み不可') : '未作成')
        );

        if (! $writable) {
            print '<div class="notice notice-error inline"><p>保存領域に書き込めません。'
                . 'バックアップとスナップショットを作成できないため、同期は実行できません。</p></div>';
        }

        $exposed = Fsync_Fs::private_storage_is_exposed();
        if ($exposed === true) {
            print '<div class="notice notice-error inline"><p><strong>保存領域が Web からアクセスできる状態です。</strong>'
                . 'nginx など .htaccess が効かない環境では、この領域を公開ディレクトリの外へ移すか、'
                . 'サーバー設定でアクセスを拒否してください。</p></div>';
        } elseif ($exposed === false) {
            print '<p class="description">Web からのアクセスは拒否されています。</p>';
        }

        $free = Fsync_Env::free_disk_bytes();
        if ($free > 0) {
            printf('<p>ディスク空き容量: %s</p>', esc_html(size_format($free)));
        }
    }

    /**
     * @return void
     */
    private static function render_environment()
    {
        $report = Fsync_Env::report();

        print '<h2>この環境の制限</h2>';
        print '<p class="description">同期はこの値に合わせてチャンクサイズとバッチを自動調整します。'
            . '設定は不要です。</p>';

        print '<table class="widefat striped" style="max-width:50em"><tbody>';

        $rows = array(
            'PHP' => $report['php_version'],
            'WordPress' => $report['wp_version'],
            '実行時間の上限' => $report['limits']['max_execution_time'] . ' 秒',
            'メモリ上限' => $report['limits']['memory_limit'] === PHP_INT_MAX
                ? '無制限'
                : size_format($report['limits']['memory_limit']),
            'アップロード上限' => size_format(max(1, $report['limits']['upload_max_filesize'])),
            '交渉されるチャンクサイズ' => size_format($report['limits']['suggested_chunk_bytes']),
            'データベース文字セット' => $report['db']['charset'],
            'WP-Cron' => $report['caps']['disable_wp_cron']
                ? 'DISABLE_WP_CRON 有効（外部cronが必要）'
                : '既定（アクセス時に実行）',
        );

        foreach ($rows as $label => $value) {
            printf('<tr><td><strong>%s</strong></td><td>%s</td></tr>', esc_html($label), esc_html((string) $value));
        }

        print '</tbody></table>';
    }

    /**
     * @return void
     */
    private static function render_log()
    {
        $entries = Fsync_Log::recent(30);
        if ($entries === array()) {
            return;
        }

        print '<h2>監査ログ</h2>';
        print '<table class="widefat striped"><thead><tr>';
        print '<th>日時</th><th>種別</th><th>コード</th><th>内容</th><th>キー</th><th>IP</th>';
        print '</tr></thead><tbody>';

        foreach ($entries as $entry) {
            printf(
                '<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td><td><code>%s</code></td><td>%s</td></tr>',
                esc_html(wp_date('Y-m-d H:i:s', (int) $entry['ts'])),
                esc_html((string) $entry['level']),
                esc_html((string) $entry['code']),
                esc_html((string) $entry['message']),
                esc_html((string) $entry['key_id']),
                esc_html((string) $entry['ip'])
            );
        }

        print '</tbody></table>';
    }
}
