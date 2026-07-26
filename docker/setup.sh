#!/bin/sh
# Install and configure all three test sites.
#
#   docker compose --profile setup run --rm setup
#
# Idempotent: safe to re-run after `docker compose down` without -v, and after
# changing the plugin. Only the pairing step is left to the operator, because
# that is the flow being tested.

set -e

install_site() {
    site_dir="$1"
    site_url="$2"
    title="$3"
    role="$4"

    # wp-config.php resolves both of these through getenv() at runtime, so each
    # site needs its own values exported before wp-cli touches it. Getting the
    # config extra wrong here means this container would derive a different
    # encryption key than the web container for the same site.
    WORDPRESS_DB_HOST="$5"
    WORDPRESS_CONFIG_EXTRA="$6"
    export WORDPRESS_DB_HOST WORDPRESS_CONFIG_EXTRA

    echo "=== ${title} (${site_url}) via ${WORDPRESS_DB_HOST} ==="

    if ! wp core is-installed --path="${site_dir}" --allow-root 2>/dev/null; then
        wp core install \
            --path="${site_dir}" \
            --url="${site_url}" \
            --title="${title}" \
            --admin_user=admin \
            --admin_password=admin \
            --admin_email="admin@example.test" \
            --skip-email \
            --allow-root
    else
        echo "already installed"
    fi

    # Pretty permalinks are on so that both endpoint styles get exercised; the
    # plugin addresses the REST API with ?rest_route= regardless, which is what
    # makes it work on hosts without mod_rewrite.
    wp rewrite structure '/%postname%/' --path="${site_dir}" --allow-root
    wp option update blogdescription "${role} environment" --path="${site_dir}" --allow-root

    wp plugin activate flares-sync --path="${site_dir}" --allow-root

    # Which environment this installation believes it is. The local site is the
    # release source; the other two are targets that must be explicitly opted in
    # to receiving before any request is accepted.
    wp option update fsync_active_env "${role}" --path="${site_dir}" --allow-root

    if [ "${role}" != "local" ]; then
        wp option update fsync_receiver_enabled 1 --path="${site_dir}" --allow-root
        echo "receiver enabled"
    fi

    wp option update fsync_site_role "${role}" --path="${site_dir}" --allow-root
}

seed_content() {
    site_dir="$1"

    if [ "$(wp post list --post_type=page --name=about --format=count --path="${site_dir}" --allow-root)" = "0" ]; then
        wp post create \
            --post_type=page \
            --post_title='会社概要' \
            --post_name=about \
            --post_status=publish \
            --post_content='<p>ローカルで編集して差分同期を試すためのページです。</p>' \
            --path="${site_dir}" \
            --allow-root
    fi
}

install_site /sites/local      http://localhost:8091 "Yamashin WP Migration ローカル"     local      db_local "${FSYNC_CONFIG_LOCAL}"
install_site /sites/staging    http://localhost:8092 "Yamashin WP Migration ステージング" staging    db_stg   "${FSYNC_CONFIG_STG}"
install_site /sites/production http://localhost:8093 "Yamashin WP Migration 本番"         production db_prod  "${FSYNC_CONFIG_PROD}"

# Only the source gets seed content; the others start empty so that the first
# promotion has something real to create.
WORDPRESS_DB_HOST=db_local
WORDPRESS_CONFIG_EXTRA="${FSYNC_CONFIG_LOCAL}"
export WORDPRESS_DB_HOST WORDPRESS_CONFIG_EXTRA
seed_content /sites/local

cat <<'EOF'

=====================================================================
Sites are ready.

  local       http://localhost:8091/wp-admin/   (admin / admin)
  staging     http://localhost:8092/wp-admin/   (admin / admin)
  production  http://localhost:8093/wp-admin/   (admin / admin)
  mailpit     http://localhost:8094

Pairing, from the local site:

  1. On staging, issue a connection key. Set its connection URL to
       http://fsync_stg
     rather than the localhost address -- containers reach each other by
     service name, and the browser reaches them by published port.
  2. Paste the blob into the local site's connection screen.
  3. Repeat for production using
       http://fsync_prod

Production runs with max_execution_time=20 and upload_max_filesize=2M on
purpose, so resumption and chunk negotiation are actually tested.
=====================================================================
EOF
