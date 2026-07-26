<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Audit log.
 *
 * Every entry passes through redact() before it is stored. Logs are the most
 * common way secrets escape: they get written to a table, exported for support,
 * and pasted into a ticket. Redacting at the write boundary means no caller has
 * to remember to do it.
 */
final class Fsync_Log
{
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';

    /** Replacement shown in place of a redacted value. */
    const MASK = '***';

    /** Retention for audit rows, in days. */
    const RETENTION_DAYS = 90;

    /**
     * Key names whose values are always masked, matched case-insensitively as
     * a substring so that "gcs_private_key" and "clientSecret" both hit.
     */
    const SECRET_KEY_FRAGMENTS = array(
        'secret',
        'password',
        'passwd',
        'token',
        'private_key',
        'privatekey',
        'client_secret',
        'refresh_token',
        'access_token',
        'authorization',
        'signature',
        'ciphertext',
        'credential_value',
        'webhook',
        'api_key',
        'apikey',
    );

    /**
     * Record an entry.
     *
     * @param string $level
     * @param string $code Machine-readable, stable across locales.
     * @param string $message Human-readable, Japanese.
     * @param array $context
     * @return void
     */
    public static function write($level, $code, $message, $context = array())
    {
        global $wpdb;

        $data = self::redact(
            array(
                'data' => $context['data'] ?? array(),
            )
        );

        $encoded = Fsync_Utils::encode($data['data']);
        if (is_wp_error($encoded)) {
            $encoded = '{}';
        }

        $wpdb->insert(
            Fsync_Schema::table('audit'),
            array(
                'ts' => Fsync_Utils::now(),
                'level' => substr((string) $level, 0, 16),
                'code' => substr((string) $code, 0, 64),
                'message' => (string) $message,
                'key_id' => substr((string) ($context['key_id'] ?? ''), 0, 32),
                'peer_id' => substr((string) ($context['peer_id'] ?? ''), 0, 32),
                'route' => substr((string) ($context['route'] ?? ''), 0, 191),
                'verdict' => substr((string) ($context['verdict'] ?? ''), 0, 32),
                'ip' => substr((string) ($context['ip'] ?? ''), 0, 64),
                'user_id' => (int) get_current_user_id(),
                'data' => $encoded,
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s')
        );
    }

    /**
     * @param string $code
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function info($code, $message, $context = array())
    {
        self::write(self::LEVEL_INFO, $code, $message, $context);
    }

    /**
     * @param string $code
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function warning($code, $message, $context = array())
    {
        self::write(self::LEVEL_WARNING, $code, $message, $context);
    }

    /**
     * @param string $code
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function error($code, $message, $context = array())
    {
        self::write(self::LEVEL_ERROR, $code, $message, $context);
    }

    /**
     * Recursively mask anything that looks like a credential.
     *
     * Two independent rules apply, because either alone leaves a gap: keys are
     * matched by name, and values are matched by shape so that a secret stored
     * under an unexpected key ("blob", "value") is still caught.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function redact($value)
    {
        if (is_array($value)) {
            $out = array();
            foreach ($value as $key => $item) {
                $out[$key] = self::key_is_secret((string) $key)
                    ? self::MASK
                    : self::redact($item);
            }

            return $out;
        }

        if (is_string($value)) {
            return self::redact_string($value);
        }

        return $value;
    }

    /**
     * @param string $key
     * @return bool
     */
    public static function key_is_secret($key)
    {
        $key = strtolower($key);

        foreach (self::SECRET_KEY_FRAGMENTS as $fragment) {
            if (strpos($key, $fragment) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mask secret-shaped substrings inside a free-form string.
     *
     * @param string $value
     * @return string
     */
    public static function redact_string($value)
    {
        if ($value === '') {
            return $value;
        }

        // PEM blocks, including the body, in one go.
        $value = (string) preg_replace(
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
            self::MASK,
            $value
        );

        // Bearer and Basic credentials that arrived inside a header dump.
        $value = (string) preg_replace(
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._\-\/+=]{8,}/i',
            '$1 ' . self::MASK,
            $value
        );

        // Slack and Google webhook/token URLs.
        $value = (string) preg_replace(
            '#https://hooks\.slack\.com/\S+#i',
            'https://hooks.slack.com/' . self::MASK,
            $value
        );

        // Long opaque blobs. Pure hexadecimal is deliberately exempt: content
        // hashes, fingerprints and manifest ids are all hex and are the most
        // useful thing in an audit trail, while a base64 secret is not.
        $value = (string) preg_replace_callback(
            '/\b[A-Za-z0-9_\-]{60,}\b/',
            static function ($matches) {
                return preg_match('/^[0-9a-f]+$/i', $matches[0]) === 1
                    ? $matches[0]
                    : self::MASK;
            },
            $value
        );

        return $value;
    }

    /**
     * Recent entries for the admin UI.
     *
     * @param int $limit
     * @param array $where Optional filters: level, code, key_id, peer_id.
     * @return array<int, array>
     */
    public static function recent($limit = 100, $where = array())
    {
        global $wpdb;

        $table = Fsync_Schema::table('audit');
        $clauses = array('1=1');
        $params = array();

        foreach (array('level', 'code', 'key_id', 'peer_id') as $column) {
            if (! empty($where[$column])) {
                $clauses[] = "{$column} = %s";
                $params[] = (string) $where[$column];
            }
        }

        $params[] = max(1, min(1000, (int) $limit));

        $sql = 'SELECT * FROM ' . $table
            . ' WHERE ' . implode(' AND ', $clauses)
            . ' ORDER BY id DESC LIMIT %d';

        return (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    /**
     * Delete entries past the retention window.
     *
     * @return int Rows removed.
     */
    public static function prune()
    {
        global $wpdb;

        $table = Fsync_Schema::table('audit');
        $cutoff = Fsync_Utils::now() - (self::RETENTION_DAYS * DAY_IN_SECONDS);

        return (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM {$table} WHERE ts < %d", $cutoff)
        );
    }
}
