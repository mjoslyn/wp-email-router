#!/usr/bin/env bash
#
# In-container entrypoint used by bin/docker-test.sh. Not meant to be run on the
# host (it assumes the PHP 8.3 test image, the cached /tmp/wp-test volume, and
# network access to the db-test service). Any arguments are forwarded to phpunit.
#
set -euo pipefail

# db-test service (see ../docker-compose.yml). root can create an isolated DB.
DB_NAME="${EIR_TEST_DB_NAME:-wp_eir_tests}"
DB_USER="root"
DB_PASS="root_password"
DB_HOST="db-test:3306"
WP_VERSION="${EIR_WP_VERSION:-latest}"

if [ ! -d vendor ]; then
  echo "--> composer install"
  composer install --no-interaction --no-progress --quiet
fi

DB_HOSTNAME="${DB_HOST%%:*}"
DB_PORT="${DB_HOST##*:}"

# MySQL 8 serves an auto-generated self-signed cert, which the bundled MariaDB
# CLI rejects. PHP's mysqli negotiates TLS without verifying it (same as the
# running site), so we manage the database through mysqli and tell
# install-wp-tests.sh to skip the CLI-based DB creation entirely.
db_query() {
  php -r '
    $m = mysqli_init();
    if (!@mysqli_real_connect($m, $argv[1], $argv[2], $argv[3], null, (int) $argv[4])) {
      fwrite(STDERR, "DB connect failed: " . mysqli_connect_error() . "\n");
      exit(1);
    }
    if (!mysqli_query($m, $argv[5])) {
      fwrite(STDERR, "DB query failed: " . mysqli_error($m) . "\n");
      exit(1);
    }
  ' "$DB_HOSTNAME" "$DB_USER" "$DB_PASS" "$DB_PORT" "$1"
}

if [ "${EIR_REINSTALL:-0}" = "1" ]; then
  echo "--> EIR_REINSTALL=1: wiping cached WP test library and test database"
  rm -rf "${WP_TESTS_DIR}" "${WP_CORE_DIR}" "${WP_TESTS_DIR}/../wp-tests-config.php"
  db_query "DROP DATABASE IF EXISTS \`${DB_NAME}\`"
fi

echo "--> ensuring test database ${DB_NAME} exists"
db_query "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`"

if [ ! -f "${WP_TESTS_DIR}/includes/functions.php" ]; then
  echo "--> installing WordPress test library (${WP_VERSION})"
  # 6th arg "true" = skip DB creation (we created it above via mysqli).
  bash bin/install-wp-tests.sh "$DB_NAME" "$DB_USER" "$DB_PASS" "$DB_HOST" "$WP_VERSION" true
fi

# WordPress excludes the ajax/ms-files/external-http groups from a default run.
# With no extra args, run the standard suite and then the ajax group so a bare
# `bin/docker-test.sh` exercises everything. Any explicit args (e.g. --filter,
# --group) are forwarded verbatim as a single pass instead.
if [ "$#" -eq 0 ]; then
  echo "--> phpunit (default suite)"
  vendor/bin/phpunit
  echo "--> phpunit (ajax group)"
  exec vendor/bin/phpunit --group ajax
fi

echo "--> phpunit"
exec vendor/bin/phpunit "$@"
