#!/usr/bin/env bash
# Set up a WordPress test environment with WooCommerce for PHPUnit.
#
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-creation]
#
# Environment variables (override defaults):
#   WP_CORE_DIR   — where WordPress core is installed  (default: /tmp/wordpress/src)
#   WP_TESTS_DIR  — where the WP PHPUnit suite lives   (default: /tmp/wordpress/tests/phpunit)

set -euo pipefail

if [ $# -lt 3 ]; then
    echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-creation]"
    exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4:-localhost}
WP_VERSION=${5:-latest}
SKIP_DB_CREATE=${6:-false}

WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress/src}
WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress/tests/phpunit}
PLUGIN_DIR="$WP_CORE_DIR/wp-content/plugins"
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SOURCE_DIR="$REPO_DIR/plugins/woocommerce-for-claude"

# Resolve "latest" to a concrete semver string (needed for the SVN tag path).
if [ "$WP_VERSION" = "latest" ]; then
    WP_VERSION=$(curl -s https://api.wordpress.org/core/version-check/1.7/ \
        | grep -o '"version":"[^"]*"' | head -1 | cut -d'"' -f4)
fi

# ── WordPress core ─────────────────────────────────────────────────────────────
if [ ! -d "$WP_CORE_DIR/wp-includes" ]; then
    mkdir -p "$WP_CORE_DIR"
    curl -sL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" \
        | tar xz -C "$WP_CORE_DIR" --strip-components=1
fi

# ── WordPress PHPUnit test suite ───────────────────────────────────────────────
if [ ! -d "$WP_TESTS_DIR/includes" ]; then
    mkdir -p "$WP_TESTS_DIR"
    svn co --quiet \
        "https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit" \
        "$WP_TESTS_DIR"
fi

# ── wp-tests-config.php ────────────────────────────────────────────────────────
# The WP test bootstrap at $WP_TESTS_DIR/includes/bootstrap.php detects the
# tests/phpunit directory structure and looks for the config two levels up from
# $WP_TESTS_DIR (i.e. dirname(dirname($WP_TESTS_DIR))/wp-tests-config.php).
CONFIG_FILE="$(dirname "$(dirname "$WP_TESTS_DIR")")/wp-tests-config.php"
if [ ! -f "$CONFIG_FILE" ]; then
    cat > "$CONFIG_FILE" <<PHP
<?php
// phpcs:disable
\$table_prefix = 'wptests_';
define( 'ABSPATH',              '${WP_CORE_DIR}/' );
define( 'DB_NAME',              '${DB_NAME}' );
define( 'DB_USER',              '${DB_USER}' );
define( 'DB_PASSWORD',          '${DB_PASS}' );
define( 'DB_HOST',              '${DB_HOST}' );
define( 'DB_CHARSET',           'utf8' );
define( 'DB_COLLATE',           '' );
define( 'WP_TESTS_DOMAIN',      'example.org' );
define( 'WP_TESTS_EMAIL',       'admin@example.org' );
define( 'WP_TESTS_TITLE',       'Test Blog' );
define( 'WP_PHP_BINARY',        '$(command -v php)' );
PHP
fi

# ── Test database ──────────────────────────────────────────────────────────────
if [ "$SKIP_DB_CREATE" = "false" ]; then
    # DB_HOST may be "host:port"; split so mysqladmin gets separate flags.
    MYSQL_HOST="${DB_HOST%%:*}"
    MYSQL_PORT="${DB_HOST##*:}"
    [ "$MYSQL_PORT" = "$MYSQL_HOST" ] && MYSQL_PORT="3306"
    mysqladmin create "$DB_NAME" \
        --user="$DB_USER" --password="$DB_PASS" \
        --host="$MYSQL_HOST" --port="$MYSQL_PORT" 2>/dev/null || true
fi

# ── WooCommerce ────────────────────────────────────────────────────────────────
if [ ! -f "$PLUGIN_DIR/woocommerce/woocommerce.php" ]; then
    mkdir -p "$PLUGIN_DIR"
    curl -sL "https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip" \
        -o /tmp/woocommerce.zip
    unzip -q /tmp/woocommerce.zip -d "$PLUGIN_DIR"
    rm /tmp/woocommerce.zip
fi

# ── Plugin under test ──────────────────────────────────────────────────────────
ln -sfn "$PLUGIN_SOURCE_DIR" "$PLUGIN_DIR/woocommerce-claude"
