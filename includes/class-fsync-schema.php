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
        add_action('admin_init', [self::class, 'maybe_upgrade']);
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
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach (self::definitions() as $sql) {
            dbDelta($sql);
        }

        update_option(self::OPTION_SCHEMA_VERSION, FSYNC_SCHEMA_VERSION, false);
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

        return $definitions;
    }
}
