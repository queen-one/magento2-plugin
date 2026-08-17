#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
LAB_DIR="${MAGENTO_LAB_DIR:-${REPOSITORY_ROOT}/.local/magento}"
MAGENTO_DOMAIN="${MAGENTO_DOMAIN:-queenone-magento.test}"
RUN_COMPILE="${MAGENTO_RUN_COMPILE:-0}"

if [ ! -f "${LAB_DIR}/src/app/etc/env.php" ]; then
    echo "Magento is not installed. Run: make magento-install" >&2
    exit 1
fi

cd "${LAB_DIR}"
bin/start

echo "Checking runtime versions..."
bin/cli php -v | sed -n '1p'
bin/magento --version

echo "Checking module and Magento schema status..."
bin/magento module:status Rejoiner_Acr
bin/magento setup:db:status

assert_schema_object() {
    label="$1"
    query="$2"
    value="$(bin/mysql --batch --skip-column-names --execute "${query}" | tr -d '[:space:]')"
    if [ "${value}" != "1" ]; then
        echo "FAIL: ${label} is missing" >&2
        return 1
    fi
    echo "PASS: ${label} exists"
}

schema_failed=0
assert_schema_object \
    "rejoiner_acr_success_orders table" \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'rejoiner_acr_success_orders';" \
    || schema_failed=1
assert_schema_object \
    "quote.promo column" \
    "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'quote' AND column_name = 'promo';" \
    || schema_failed=1
assert_schema_object \
    "newsletter_subscriber.added_to_rejoiner column" \
    "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'newsletter_subscriber' AND column_name = 'added_to_rejoiner';" \
    || schema_failed=1

if [ "${RUN_COMPILE}" = "1" ]; then
    echo "Running dependency-injection compilation..."
    bin/magento setup:di:compile
fi

echo "Checking storefront response..."
curl --fail --insecure --silent --show-error --max-time 30 \
    "https://${MAGENTO_DOMAIN}/" >/dev/null
echo "PASS: storefront responds"

if [ "${schema_failed}" != "0" ]; then
    echo "Smoke test failed because the legacy module did not create its complete schema." >&2
    exit 1
fi

echo "Local Magento smoke test passed."
