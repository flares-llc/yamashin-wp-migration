<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Admin menu and shared rendering helpers.
 */
final class Fsync_Admin
{
    const SLUG = 'flares-sync';
    const CAPABILITY = 'manage_options';

    /**
     * @return void
     */
    public static function register_hooks()
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_post_fsync_action', [self::class, 'handle_post']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    /**
     * @return void
     */
    public static function register_menu()
    {
        add_menu_page(
            'Yamashin WP Migration',
            'Yamashin WP Migration',
            self::CAPABILITY,
            self::SLUG,
            [Fsync_Admin_Connection::class, 'render'],
            'dashicons-update',
            76
        );

        add_submenu_page(
            self::SLUG,
            '接続',
            '接続',
            self::CAPABILITY,
            self::SLUG,
            [Fsync_Admin_Connection::class, 'render']
        );

        add_submenu_page(
            self::SLUG,
            '移行',
            '移行',
            self::CAPABILITY,
            self::SLUG . '-migration',
            [Fsync_Admin_Migration::class, 'render']
        );

        add_submenu_page(
            self::SLUG,
            '設定',
            '設定',
            self::CAPABILITY,
            self::SLUG . '-config',
            [Fsync_Admin_Config::class, 'render']
        );

        add_submenu_page(
            self::SLUG,
            '診断',
            '診断',
            self::CAPABILITY,
            self::SLUG . '-health',
            [Fsync_Admin_Health::class, 'render']
        );
    }

    /**
     * @param string $hook
     * @return void
     */
    public static function enqueue($hook)
    {
        if (strpos((string) $hook, self::SLUG) === false) {
            return;
        }

        wp_enqueue_style(
            'fsync-admin',
            FSYNC_URL . 'assets/admin.css',
            array(),
            FSYNC_VERSION
        );
    }

    /**
     * Single entry point for form submissions, dispatching on an action name.
     *
     * @return void
     */
    public static function handle_post()
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die('権限がありません。');
        }

        $action = (string) ($_POST['fsync_action'] ?? '');
        check_admin_referer('fsync_' . $action);

        $handlers = array_merge(
            Fsync_Admin_Connection::handlers(),
            Fsync_Admin_Config::handlers(),
            Fsync_Admin_Migration::handlers()
        );

        if (! isset($handlers[$action])) {
            self::redirect_back(new WP_Error('fsync_unknown_action', '不明な操作です。'));
        }

        $result = call_user_func($handlers[$action]);

        self::redirect_back($result);
    }

    /**
     * @param mixed $result
     * @return void
     */
    public static function redirect_back($result)
    {
        $referer = wp_get_referer();
        $url = $referer === false ? admin_url('admin.php?page=' . self::SLUG) : $referer;

        if (is_wp_error($result)) {
            set_transient(self::notice_key(), array('type' => 'error', 'message' => $result->get_error_message()), 60);
        } elseif (is_string($result) && $result !== '') {
            set_transient(self::notice_key(), array('type' => 'success', 'message' => $result), 60);
        }

        wp_safe_redirect(remove_query_arg('fsync_notice', $url));
        exit;
    }

    /**
     * @return string
     */
    private static function notice_key()
    {
        return 'fsync_notice_' . get_current_user_id();
    }

    /**
     * Render and clear any pending notice.
     *
     * @return void
     */
    public static function render_notice()
    {
        $notice = get_transient(self::notice_key());
        if (! is_array($notice)) {
            return;
        }

        delete_transient(self::notice_key());

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($notice['type'] === 'error' ? 'error' : 'success'),
            esc_html((string) $notice['message'])
        );
    }

    /**
     * Render the shared product lockup and the current screen label.
     *
     * @param string $section
     * @return void
     */
    public static function render_brand_header($section)
    {
        printf(
            '<div class="fsync-brand"><img class="fsync-brand__logo" src="%s" width="1237" height="276" alt=""><span class="fsync-brand__section" aria-hidden="true">/ %s</span><h1 class="screen-reader-text">Yamashin WP Migration — %s</h1></div>',
            esc_url(FSYNC_URL . 'assets/brand/yamashin-wp-migration-horizontal-transparent.svg'),
            esc_html($section),
            esc_html($section)
        );
    }

    /**
     * Open a form that posts to the shared dispatcher.
     *
     * @param string $action
     * @return void
     */
    public static function form_open($action)
    {
        printf(
            '<form method="post" action="%s">',
            esc_url(admin_url('admin-post.php'))
        );
        printf('<input type="hidden" name="action" value="fsync_action">');
        printf('<input type="hidden" name="fsync_action" value="%s">', esc_attr($action));
        wp_nonce_field('fsync_' . $action);
    }

    /**
     * @return void
     */
    public static function form_close()
    {
        print '</form>';
    }

    /**
     * A write-only secret field.
     *
     * Renders the fingerprint and the date it was set, never the value. An
     * empty submission means "leave unchanged", so that saving an unrelated
     * setting cannot wipe a credential.
     *
     * @param string $name
     * @param array|null $meta
     * @param string $placeholder
     * @return void
     */
    public static function secret_field($name, $meta, $placeholder = '')
    {
        print '<div class="fsync-secret">';

        if (is_array($meta)) {
            printf(
                '<p class="fsync-secret-state">設定済み <code>••••••••</code> 指紋 <code>%s</code> / 更新 %s</p>',
                esc_html($meta['fingerprint']),
                esc_html(
                    $meta['updated_at'] > 0
                        ? wp_date('Y-m-d H:i', $meta['updated_at'])
                        : '-'
                )
            );
        } else {
            print '<p class="fsync-secret-state">未設定</p>';
        }

        printf(
            '<input type="password" name="%s" class="regular-text" autocomplete="new-password" placeholder="%s">',
            esc_attr($name),
            esc_attr($placeholder === '' ? '変更する場合のみ入力' : $placeholder)
        );

        print '<p class="description">保存済みの値は表示されません。空欄のまま保存すると変更されません。</p>';
        print '</div>';
    }
}
