#!/bin/sh
# End-to-end verification of pairing and the authenticated request path.
#
#   docker compose up -d
#   docker compose --profile setup run --rm setup
#   ./docker/verify-pairing.sh [staging|production]
#
# Pairs the local site with the chosen target over real HTTP and asserts the
# security properties that only show up with two live installations: single-use
# pairing, nonce replay rejection, signature tampering, and clock skew.

set -e

cd "$(dirname "$0")/.."

TARGET="${1:-staging}"

case "$TARGET" in
    staging)
        CLI_SERVICE=wpcli_stg
        # Containers reach each other by service name; the published localhost
        # port only exists from the host's point of view.
        CONNECT_URL=http://fsync_stg
        ;;
    production)
        CLI_SERVICE=wpcli_prod
        CONNECT_URL=http://fsync_prod
        ;;
    *)
        echo "usage: $0 [staging|production]" >&2
        exit 2
        ;;
esac

PLUGIN=/var/www/html/wp-content/plugins/flares-sync

echo "=== issuing a pairing blob on ${TARGET} ==="

ISSUE_OUTPUT=$(docker compose --profile tools run --rm -T "$CLI_SERVICE" \
    wp eval-file "$PLUGIN/tests/integration/issue-pairing.php" \
    local "$CONNECT_URL" deploy --allow-root | tr -d '\r')
BLOB=$(printf '%s\n' "$ISSUE_OUTPUT" | tail -1)
ISSUED_KEY=$(printf '%s\n' "$ISSUE_OUTPUT" | sed -n 's/^key_id=\([a-f0-9]*\) .*/\1/p' | tail -1)

case "$ISSUED_KEY" in
    ''|*[!a-f0-9]*)
        echo "failed to obtain issued key id" >&2
        exit 1
        ;;
esac

if [ -z "$BLOB" ]; then
    echo "failed to obtain a pairing blob" >&2
    exit 1
fi

echo "blob length: ${#BLOB}"
echo

echo "=== connecting from local ==="

# Run the initiating CLI as root because the file-backed configuration test
# must create a short-lived file directly under wp-content. The official
# wp-cli image otherwise uses www-data while the named volume's wp-content
# directory is not group-writable.
docker compose --profile tools run --rm -T --user root wpcli_local \
    wp eval-file "$PLUGIN/tests/integration/connect.php" \
    "$BLOB" "$TARGET" --allow-root

docker compose --profile tools run --rm -T "$CLI_SERVICE" \
    wp eval-file "$PLUGIN/tests/integration/verify-target-key.php" \
    "$ISSUED_KEY" --allow-root

echo
echo "=== verifying pairing IP allowlist on ${TARGET} ==="

DENIED_ENV="${TARGET}-ip-denied"
DENIED_OUTPUT=$(docker compose --profile tools run --rm -T "$CLI_SERVICE" \
    wp eval-file "$PLUGIN/tests/integration/issue-pairing.php" \
    local "$CONNECT_URL" readonly 203.0.113.0/24 --allow-root | tr -d '\r')
DENIED_BLOB=$(printf '%s\n' "$DENIED_OUTPUT" | tail -1)
DENIED_KEY=$(printf '%s\n' "$DENIED_OUTPUT" | sed -n 's/^key_id=\([a-f0-9]*\) .*/\1/p' | tail -1)

case "$DENIED_KEY" in
    ''|*[!a-f0-9]*)
        echo "failed to obtain denied-test key id" >&2
        exit 1
        ;;
esac

docker compose --profile tools run --rm -T wpcli_local \
    wp eval-file "$PLUGIN/tests/integration/ip-denied.php" \
    "$DENIED_BLOB" "$DENIED_ENV" --allow-root

# The rejected confirmation deliberately leaves the remote pending key usable
# from an allowed address. Retire it first to exercise the normal lifecycle,
# then remove this test-only row so repeated runs do not pollute the key ledger.
docker compose --profile tools run --rm -T "$CLI_SERVICE" \
    wp eval-file "$PLUGIN/tests/integration/cleanup-test-key.php" \
    "$DENIED_KEY" --allow-root >/dev/null
