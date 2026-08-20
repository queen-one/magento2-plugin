#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
LAB_DIR="${MAGENTO_LAB_DIR:-${REPOSITORY_ROOT}/.local/magento}"
MAGENTO_VERSION="${MAGENTO_VERSION:-2.4.9}"
MAGENTO_DOMAIN="${MAGENTO_DOMAIN:-queenone-magento.test}"
WITH_SAMPLE_DATA="${MAGENTO_WITH_SAMPLE_DATA:-1}"

"${SCRIPT_DIR}/bootstrap.sh"
cd "${LAB_DIR}"

if [ -f compose.override.yaml ] && [ ! -f src/app/etc/env.php ]; then
    echo "compose.override.yaml is active before the base Magento install." >&2
    echo "Move it aside and rerun this command so Composer receives an empty project directory." >&2
    exit 1
fi

if [ ! -f src/app/etc/env.php ]; then
    echo "Installing Magento Open Source ${MAGENTO_VERSION}."
    echo "Composer will request repo.magento.com public/private access keys if they are not configured."
    echo "The setup may also request the macOS password to configure ${MAGENTO_DOMAIN}."
    bin/download community "${MAGENTO_VERSION}"
    bin/setup "${MAGENTO_DOMAIN}"
else
    echo "Base Magento installation already exists; skipping download and setup."
    bin/start
fi

if [ "${WITH_SAMPLE_DATA}" = "1" ]; then
    if ! bin/composer show magento/module-catalog-sample-data >/dev/null 2>&1; then
        echo "Installing Magento sample data and local development helpers..."
        bin/init
    else
        echo "Magento sample data is already installed."
    fi
fi

if [ ! -f compose.override.yaml ]; then
    cp compose.plugin.yaml compose.override.yaml
fi

bin/restart
bin/magento module:enable Rejoiner_Acr
bin/magento setup:upgrade
bin/magento cache:flush

echo
echo "Magento and Rejoiner_Acr are installed."
echo "Storefront: https://${MAGENTO_DOMAIN}/"
echo "Admin:      https://${MAGENTO_DOMAIN}/admin/"
echo "Run verification with: make magento-smoke"
