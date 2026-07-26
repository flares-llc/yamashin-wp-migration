<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Table definitions and schema upgrades.
 *
 * Everything that is written concurrently lives in a real table rather than an
 * option. Options are a read-modify-write of a single row, so two overlapping
 * requests silently lose one another's updates -- unacceptable for locks, job
 * state, nonces and audit trails.
 *
 * dbDelta is picky about formatting: two spaces after PRIMARY KEY, one field
 * per line, lowercase types, and KEY rather than INDEX. Edit with care.
 */
final class Fsync_Schema
{
    const OPTION_SCHEMA_VERSION = 'fsync_schema_version';

    /**
     * @return void
     */
    public static function register_hooks()
    {
        // Plugin updates do not run the activation hook. Upgrade on every
        // request type (REST, cron, CLI and admin), with the stored version as
        // the cheap fast-path, so a newly added migration route never reaches
        // a database that still has the previous schema.
        add_action('init', [self::class, 'maybe_upgrade'], 1);
    }

    /**
     * Run dbDelta when the stored schema version is behind the code.
     *
     * @return void
     */
    public static function maybe_upgrade()
    {
        if ((int) get_option(self::OPTION_SCHEMA_VERSION, 0) === FSYNC_SCHEMA_VERSION) {
            return;
        }

        self::install();
    }

    /**
     * Create or update every table.
     *
     * @return void
     */
    public static function install()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach (self::definitions() as $sql) {
            dbDelta($sql);
        }

        foreach (array_keys(self::tables()) as $name) {
            $table = self::table($name);
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($exists !== $table) {
                return new WP_Error(
                    'fsync_schema_install_failed',
                    sprintf('必要なテーブルを作成できません: %s', $table),
                    array('database_error' => (string) $wpdb->last_error)
                );
            }
        }

        update_option(self::OPTION_SCHEMA_VERSION, FSYNC_SCHEMA_VERSION, false);

        return true;
    }

    /**
     * Drop every table. Only called from uninstall.php.
     *
     * @return void
     */
    public static function drop()
    {
        global $wpdb;

        foreach (array_keys(self::tables()) as $name) {
            $table = self::table($name);
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }

        delete_option(self::OPTION_SCHEMA_VERSION);
    }

    /**
     * Logical table name to fully qualified name.
     *
     * @param string $name
     * @return string
     */
    public static function table($name)
    {
        global $wpdb;

        return $wpdb->prefix . 'fsync_' . $name;
    }

    /**
     * @return array<string, string> Logical name => purpose.
     */
    public static function tables()
    {
        return array(
            'credentials' => '暗号化された認証情報',
            'keys' => '接続キーと権限スコープ',
            'nonces' => 'リプレイ防止用のnonce',
            'peers' => 'ピア（環境）台帳',
            'audit' => '監査ログ',
            'config_history' => '設定の変更履歴',
            'entities' => '可搬UIDとローカルIDの対応',
            'manifests' => 'サイト状態のMerkleマニフェスト',
            'releases' => '不変な移行リリース',
            'release_items' => 'リリース内の差分項目',
            'jobs' => '再開可能なバックグラウンド処理',
            'snapshots' => '適用前スナップショット',
            'receipts' => '適用・検証済みリリースの受領証',
            'mcp_tokens' => 'AIクライアント用アクセストークン',
        );
    }

    /**
     * @return array<int, string>
     */
    private static function definitions()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $definitions = array();

        // Encrypted credential values. The ciphertext never leaves this table
        // and is never rendered; only the fingerprint is shown in the UI.
        $table = self::table('credentials');
        $definitions[] = "CREATE TABLE {$table} (
            credential_id varchar(64) NOT NULL,
            kind varchar(32) NOT NULL DEFAULT '',
            ciphertext longtext NOT NULL,
            fingerprint varchar(16) NOT NULL DEFAULT '',
            key_ref varchar(32) NOT NULL DEFAULT '',
            created_at bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (credential_id),
            KEY kind (kind)
        ) {$charset_collate};";

        // Signing keys. secret_ciphertext holds the shared secret for HMAC;
        // capabilities is a JSON array so a key can be scoped to read-only
        // drift checks without any ability to apply changes.
        $table = self::table('keys');
        $definitions[] = "CREATE TABLE {$table} (
            key_id varchar(32) NOT NULL,
            peer_id varchar(32) NOT NULL DEFAULT '',
            direction varchar(16) NOT NULL DEFAULT 'inbound',
            label varchar(191) NOT NULL DEFAULT '',
            secret_ciphertext longtext NOT NULL,
            algorithm varchar(32) NOT NULL DEFAULT 'hmac-sha256',
            capabilities longtext NOT NULL,
            ip_allowlist longtext NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'active',
            supersedes varchar(32) NOT NULL DEFAULT '',
            grace_until bigint(20) unsigned NOT NULL DEFAULT 0,
            expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
            last_used_at bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (key_id),
            KEY peer_id (peer_id),
            KEY status (status)
        ) {$charset_collate};";

        // Replay protection. The unique constraint on (key_id, nonce) is the
        // enforcement mechanism: a duplicate INSERT fails rather than being
        // detected by a racy SELECT-then-INSERT.
        $table = self::table('nonces');
        $definitions[] = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            key_id varchar(32) NOT NULL,
            nonce varchar(64) NOT NULL,
            expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY key_nonce (key_id, nonce),
            KEY expires_at (expires_at)
        ) {$charset_collate};";

        // Peer ledger. peer_id is assigned at pairing and stored on both sides,
        // because a site URL can change and must not be used as an identity.
        $table = self::table('peers');
        $definitions[] = "CREATE TABLE {$table} (
            peer_id varchar(32) NOT NULL,
            env_name varchar(64) NOT NULL DEFAULT '',
            site_role varchar(32) NOT NULL DEFAULT '',
            url varchar(255) NOT NULL DEFAULT '',
            outbound_key_id varchar(32) NOT NULL DEFAULT '',
            scope_fingerprint varchar(64) NOT NULL DEFAULT '',
            handshake longtext NOT NULL,
            last_contact_at bigint(20) unsigned NOT NULL DEFAULT 0,
            clock_skew int(11) NOT NULL DEFAULT 0,
            status varchar(16) NOT NULL DEFAULT 'active',
            created_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (peer_id),
            UNIQUE KEY env_name (env_name),
            KEY status (status)
        ) {$charset_collate};";

        // Audit trail. Written on every authenticated request and every state
        // change, and never from an option, so concurrent writes all survive.
        $table = self::table('audit');
        $definitions[] = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ts bigint(20) unsigned NOT NULL DEFAULT 0,
            level varchar(16) NOT NULL DEFAULT 'info',
            code varchar(64) NOT NULL DEFAULT '',
            message text NOT NULL,
            key_id varchar(32) NOT NULL DEFAULT '',
            peer_id varchar(32) NOT NULL DEFAULT '',
            route varchar(191) NOT NULL DEFAULT '',
            verdict varchar(32) NOT NULL DEFAULT '',
            ip varchar(64) NOT NULL DEFAULT '',
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            data longtext NOT NULL,
            PRIMARY KEY  (id),
            KEY ts (ts),
            KEY code (code),
            KEY key_id (key_id)
        ) {$charset_collate};";

        // Config history, so an applied configuration can always be rolled back
        // to the previous one in a single click.
        $table = self::table('config_history');
        $definitions[] = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ts bigint(20) unsigned NOT NULL DEFAULT 0,
            source varchar(16) NOT NULL DEFAULT 'db',
            config_hash varchar(64) NOT NULL DEFAULT '',
            document longtext NOT NULL,
            note varchar(255) NOT NULL DEFAULT '',
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY ts (ts)
        ) {$charset_collate};";

        $table = self::table('entities');
        $definitions[] = "CREATE TABLE {$table} (
            entity_kind varchar(32) NOT NULL,
            entity_uid varchar(36) NOT NULL,
            local_id bigint(20) unsigned NOT NULL DEFAULT 0,
            identity_key varchar(191) NOT NULL DEFAULT '',
            peer_id varchar(32) NOT NULL DEFAULT '',
            current_hash varchar(64) NOT NULL DEFAULT '',
            base_hash varchar(64) NOT NULL DEFAULT '',
            updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (entity_kind, entity_uid),
            KEY local_entity (entity_kind, local_id),
            KEY identity_key (identity_key),
            KEY peer_id (peer_id)
        ) {$charset_collate};";

        $table = self::table('manifests');
        $definitions[] = "CREATE TABLE {$table} (
            manifest_id varchar(32) NOT NULL,
            peer_id varchar(32) NOT NULL DEFAULT '',
            scope_fingerprint varchar(64) NOT NULL DEFAULT '',
            root_hash varchar(64) NOT NULL DEFAULT '',
            item_count bigint(20) unsigned NOT NULL DEFAULT 0,
            total_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
            path varchar(255) NOT NULL DEFAULT '',
            created_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (manifest_id),
            KEY root_hash (root_hash),
            KEY peer_id (peer_id)
        ) {$charset_collate};";

        $table = self::table('releases');
        $definitions[] = "CREATE TABLE {$table} (
            release_id varchar(32) NOT NULL,
            peer_id varchar(32) NOT NULL DEFAULT '',
            direction varchar(16) NOT NULL DEFAULT 'push',
            status varchar(24) NOT NULL DEFAULT 'draft',
            manifest_id varchar(32) NOT NULL DEFAULT '',
            base_receipt_id varchar(32) NOT NULL DEFAULT '',
            scope_fingerprint varchar(64) NOT NULL DEFAULT '',
            config_hash varchar(64) NOT NULL DEFAULT '',
            plan_hash varchar(64) NOT NULL DEFAULT '',
            confirmation_hash varchar(64) NOT NULL DEFAULT '',
            idempotency_hash varchar(64) NOT NULL DEFAULT '',
            summary longtext NOT NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (release_id),
            KEY peer_id (peer_id),
            KEY idempotency_hash (idempotency_hash),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        $table = self::table('release_items');
        $definitions[] = "CREATE TABLE {$table} (
            release_id varchar(32) NOT NULL,
            item_key varchar(191) NOT NULL,
            target_item_key varchar(191) NOT NULL DEFAULT '',
            entity_kind varchar(32) NOT NULL DEFAULT '',
            entity_uid varchar(36) NOT NULL DEFAULT '',
            action varchar(16) NOT NULL DEFAULT 'unchanged',
            source_hash varchar(64) NOT NULL DEFAULT '',
            target_hash varchar(64) NOT NULL DEFAULT '',
            base_hash varchar(64) NOT NULL DEFAULT '',
            payload_hash varchar(64) NOT NULL DEFAULT '',
            has_relationships tinyint(1) unsigned NOT NULL DEFAULT 0,
            resolution varchar(16) NOT NULL DEFAULT '',
            status varchar(16) NOT NULL DEFAULT 'pending',
            error text NOT NULL,
            PRIMARY KEY  (release_id, item_key),
            KEY release_action (release_id, action),
            KEY release_status (release_id, status)
        ) {$charset_collate};";

        $table = self::table('jobs');
        $definitions[] = "CREATE TABLE {$table} (
            job_id varchar(32) NOT NULL,
            release_id varchar(32) NOT NULL DEFAULT '',
            operation varchar(32) NOT NULL DEFAULT '',
            status varchar(24) NOT NULL DEFAULT 'queued',
            phase varchar(32) NOT NULL DEFAULT '',
            cursor_pos bigint(20) unsigned NOT NULL DEFAULT 0,
            attempts int(10) unsigned NOT NULL DEFAULT 0,
            progress bigint(20) unsigned NOT NULL DEFAULT 0,
            total bigint(20) unsigned NOT NULL DEFAULT 0,
            payload longtext NOT NULL,
            result longtext NOT NULL,
            error text NOT NULL,
            heartbeat_at bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (job_id),
            KEY release_id (release_id),
            KEY status (status),
            KEY heartbeat_at (heartbeat_at)
        ) {$charset_collate};";

        $table = self::table('snapshots');
        $definitions[] = "CREATE TABLE {$table} (
            snapshot_id varchar(32) NOT NULL,
            release_id varchar(32) NOT NULL DEFAULT '',
            status varchar(16) NOT NULL DEFAULT 'ready',
            manifest_hash varchar(64) NOT NULL DEFAULT '',
            path varchar(255) NOT NULL DEFAULT '',
            size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at bigint(20) unsigned NOT NULL DEFAULT 0,
            expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
            restored_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (snapshot_id),
            KEY release_id (release_id),
            KEY expires_at (expires_at)
        ) {$charset_collate};";

        $table = self::table('receipts');
        $definitions[] = "CREATE TABLE {$table} (
            receipt_id varchar(32) NOT NULL,
            release_id varchar(32) NOT NULL DEFAULT '',
            peer_id varchar(32) NOT NULL DEFAULT '',
            source_env varchar(64) NOT NULL DEFAULT '',
            target_env varchar(64) NOT NULL DEFAULT '',
            manifest_root varchar(64) NOT NULL DEFAULT '',
            plan_hash varchar(64) NOT NULL DEFAULT '',
            status varchar(16) NOT NULL DEFAULT 'verified',
            data longtext NOT NULL,
            applied_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (receipt_id),
            UNIQUE KEY release_id (release_id),
            KEY peer_id (peer_id),
            KEY applied_at (applied_at)
        ) {$charset_collate};";

        $table = self::table('mcp_tokens');
        $definitions[] = "CREATE TABLE {$table} (
            token_id varchar(32) NOT NULL,
            label varchar(191) NOT NULL DEFAULT '',
            token_hash varchar(64) NOT NULL DEFAULT '',
            capabilities longtext NOT NULL,
            origin_allowlist longtext NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'active',
            last_used_at bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (token_id),
            UNIQUE KEY token_hash (token_hash),
            KEY status (status)
        ) {$charset_collate};";

        return $definitions;
    }
}
