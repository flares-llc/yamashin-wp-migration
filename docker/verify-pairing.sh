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

BLOB=$(docker compose --profile tools run --rm -T "$CLI_SERVICE" \
    wp eval-file "$PLUGIN/tests/integration/issue-pairing.php" \
    local "$CONNECT_URL" deploy --allow-root | tr -d '\r' | tail -1)

if [ -z "$BLOB" ]; then
    echo "failed to obtain a pairing blob" >&2
    exit 1
fi

echo "blob length: ${#BLOB}"
echo

echo "=== connecting from local ==="

docker compose --profile tools run --rm -T wpcli_local \
    wp eval-file "$PLUGIN/tests/integration/connect.php" \
    "$BLOB" "$TARGET" --allow-root
