#!/usr/bin/env bash
set -euo pipefail

: "${WP_ROOT:?Set WP_ROOT to a disposable WordPress directory}"
: "${WP_VERSION:?Set WP_VERSION}"
: "${WC_VERSION:?Set WC_VERSION}"
: "${SWS_SOFT79_FILE:?Set SWS_SOFT79_FILE to the separate fork checkout}"
sws_source_dir="$(cd "$(dirname "$0")/.." && pwd)"

# The caller provisions a dedicated database. Never point this script at a store.
mkdir -p "$WP_ROOT"
wp core download --path="$WP_ROOT" --version="$WP_VERSION" --quiet
wp config create --path="$WP_ROOT" --dbname=wordpress --dbuser=root --dbpass=wordpress --dbhost=127.0.0.1:3306 --skip-check --quiet
wp config set DISABLE_WP_CRON true --raw --path="$WP_ROOT" --quiet
wp core install --path="$WP_ROOT" --url=http://storewide-sale.test --title='Disposable Storewide Sale tests' --admin_user=admin --admin_password=disposable-test-password --admin_email=tests@example.org --skip-email --quiet
if [ "$WC_VERSION" = latest ]; then
    wp plugin install woocommerce --activate --path="$WP_ROOT" --quiet
else
    wp plugin install woocommerce --version="$WC_VERSION" --activate --path="$WP_ROOT" --quiet
fi
ln -s "$sws_source_dir" "$WP_ROOT/wp-content/plugins/storewide-sale"
wp plugin activate storewide-sale --path="$WP_ROOT" --quiet
wp option update sws_test_environment disposable --path="$WP_ROOT" --quiet
SWS_TEST_RUN=1 wp eval-file "$sws_source_dir/tests/integration.php" --path="$WP_ROOT"
