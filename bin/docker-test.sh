#!/usr/bin/env bash
#
# Run the Email Router PHPUnit suite in Docker.
#
# The host does not ship `svn` or a `mysql` client (both required by
# install-wp-tests.sh), so the suite runs inside a small PHP 8.3 container that
# does. The container joins the site's compose network and talks to the existing
# `db-test` MySQL service, installing the WordPress test library into a cached
# named volume so repeat runs are fast.
#
# Usage:
#   bin/docker-test.sh                 # run the whole suite
#   bin/docker-test.sh --filter Foo    # extra args pass straight through to phpunit
#   EIR_REINSTALL=1 bin/docker-test.sh # force a fresh WP test-library install
#
set -euo pipefail

cd "$(dirname "$0")/.."

NETWORK="${EIR_TEST_NETWORK:-fentonmobility_wordpress-network}"
IMAGE="eir-phpunit:php8.3"
CACHE_VOLUME="eir-wp-test-cache"

echo "==> Building test image ($IMAGE)"
docker build -q -t "$IMAGE" -f bin/Dockerfile.test bin >/dev/null

echo "==> Running PHPUnit (network: $NETWORK)"
docker run --rm -i \
  --network "$NETWORK" \
  -v "$PWD":/plugin \
  -v "$CACHE_VOLUME":/tmp/wp-test \
  -w /plugin \
  -e WP_TESTS_DIR=/tmp/wp-test/wordpress-tests-lib \
  -e WP_CORE_DIR=/tmp/wp-test/wordpress \
  -e TMPDIR=/tmp/wp-test \
  -e EIR_TEST_DB_NAME="${EIR_TEST_DB_NAME:-wp_eir_tests}" \
  -e EIR_WP_VERSION="${EIR_WP_VERSION:-latest}" \
  -e EIR_REINSTALL="${EIR_REINSTALL:-0}" \
  "$IMAGE" \
  bash bin/run-in-container.sh "$@"
