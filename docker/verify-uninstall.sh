#!/bin/sh
# Exercise uninstall.php against one disposable Docker WordPress installation,
# then reactivate it so the verification environment remains usable.

set -eu

cd "$(dirname "$0")/.."

TARGET="${1:-production}"
case "$TARGET" in
    local)
        CLI_SERVICE=wpcli_local
        ;;
    staging)
        CLI_SERVICE=wpcli_stg
        ;;
    production)
        CLI_SERVICE=wpcli_prod
        ;;
    *)
        echo "usage: $0 [local|staging|production]" >&2
        exit 2
        ;;
esac

PLUGIN=/var/www/html/wp-content/plugins/flares-sync

restore_plugin() {
    set +e
    docker compose --profile tools run --rm -T --user root "$CLI_SERVICE" \
        wp plugin activate flares-sync --allow-root >/dev/null 2>&1
    docker compose --profile tools run --rm -T --user root "$CLI_SERVICE" \
        wp eval "
            Fsync_Config_Io::set_active_env('${TARGET}');
            update_option('fsync_site_role', '${TARGET}', false);
            Fsync_Auth::set_receiver_enabled(true);
            Fsync_Fs::ensure_private_storage();
            @unlink(Fsync_Fs::private_dir('uninstall-sentinel'));
        " --allow-root >/dev/null 2>&1
}
trap restore_plugin EXIT HUP INT TERM

echo "=== preparing uninstall fixtures on ${TARGET} ==="

docker compose --profile tools run --rm -T --user root "$CLI_SERVICE" \
    wp eval '
        $ready = Fsync_Fs::ensure_private_storage();
        if (is_wp_error($ready)) {
            WP_CLI::error($ready->get_error_message());
        }
        $written = Fsync_Fs::write_atomic(Fsync_Fs::private_dir("uninstall-sentinel"), "keep\n");
        if (is_wp_error($written)) {
            WP_CLI::error($written->get_error_message());
        }

        set_transient("fsync_supports_get_lock", "yes", HOUR_IN_SECONDS);
        set_transient("fsync_notice_999999", array("message" => "test"), HOUR_IN_SECONDS);
        set_transient("fsync_pairing_blob_999999", array("blob" => "test"), HOUR_IN_SECONDS);
        set_transient("fsync_config_draft_999999", "{}", HOUR_IN_SECONDS);
        set_transient("fsync_config_result_999999", array("errors" => array()), HOUR_IN_SECONDS);
        set_transient("fsync_apply_confirm_999999_test", "test", HOUR_IN_SECONDS);
        set_transient("fsync_mcp_token_999999", "test", HOUR_IN_SECONDS);
        update_option("fsync_apply_lock", array("release_id" => "test"), false);
        update_option("fsync_runtime_guard", array("expires" => time() + 60), false);
        update_post_meta(1, "_fsync_uid", "00000000-0000-4000-8000-000000000000");
        wp_schedule_single_event(time() + HOUR_IN_SECONDS, "fsync_tick");
        wp_schedule_single_event(time() + HOUR_IN_SECONDS, "fsync_run_now");
    ' --allow-root

echo "=== running uninstall.php on ${TARGET} ==="

docker compose --profile tools run --rm -T --user root "$CLI_SERVICE" \
    wp plugin uninstall flares-sync --deactivate --skip-delete --allow-root

echo "=== verifying uninstall results on ${TARGET} ==="

docker compose --profile tools run --rm -T --user root "$CLI_SERVICE" \
    wp eval '
        global $wpdb;
        $failures = array();

        foreach (array("credentials", "keys", "nonces", "peers", "audit", "config_history", "entities", "manifests", "releases", "release_items", "jobs", "snapshots", "receipts", "mcp_tokens") as $name) {
            $table = $wpdb->prefix . "fsync_" . $name;
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table) {
                $failures[] = "table remains: " . $table;
            }
        }

        foreach (
            array(
                "fsync_config", "fsync_active_env", "fsync_site_role",
                "fsync_receiver_enabled", "fsync_trusted_proxies",
                "fsync_crypto_canary", "fsync_schema_version", "fsync_apply_lock", "fsync_runtime_guard",
            ) as $option
        ) {
            if (get_option($option, null) !== null) {
                $failures[] = "option remains: " . $option;
            }
        }

        $transient_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE \"\\_transient\\_fsync\\_%\"
                OR option_name LIKE \"\\_transient\\_timeout\\_fsync\\_%\""
        );
        if ($transient_count !== 0) {
            $failures[] = "plugin transients remain: " . $transient_count;
        }

        if (wp_next_scheduled("fsync_tick") !== false || wp_next_scheduled("fsync_run_now") !== false) {
            $failures[] = "scheduled hooks remain";
        }

        if (get_post_meta(1, "_fsync_uid", true) !== "") {
            $failures[] = "portable UID meta remains";
        }

        if (! file_exists(WP_CONTENT_DIR . "/.flares-sync/uninstall-sentinel")) {
            $failures[] = "private backup storage was removed without opt-in";
        }

        if (in_array("flares-sync/flares-sync.php", (array) get_option("active_plugins", array()), true)) {
            $failures[] = "plugin is still active";
        }

        if ($failures !== array()) {
            WP_CLI::error(implode(" | ", $failures));
        }

        WP_CLI::success("tables, options, transients and schedules were removed; private storage was preserved");
    ' --allow-root

restore_plugin
trap - EXIT HUP INT TERM

docker compose --profile tools run --rm -T --user root "$CLI_SERVICE" \
    wp eval '
        global $wpdb;
        $tables = 0;
        foreach (array("credentials", "keys", "nonces", "peers", "audit", "config_history", "entities", "manifests", "releases", "release_items", "jobs", "snapshots", "receipts", "mcp_tokens") as $name) {
            $table = $wpdb->prefix . "fsync_" . $name;
            $tables += $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table ? 1 : 0;
        }
        if ($tables !== 14 || ! Fsync_Auth::receiver_enabled()) {
            WP_CLI::error("reactivation did not restore the schema and receiver fixture");
        }
        WP_CLI::success("plugin reactivated and the Docker receiver fixture was restored");
    ' --allow-root
