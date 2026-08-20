#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
LAB_DIR="${MAGENTO_LAB_DIR:-${REPOSITORY_ROOT}/.local/magento}"
TEMPLATE_VERSION="${DOCKER_MAGENTO_VERSION:-53.0.1}"
PLUGIN_COMPOSE_SOURCE="${SCRIPT_DIR}/compose.plugin.yaml"

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Missing required command: $1" >&2
        exit 1
    fi
}

require_command docker
require_command git

sync_plugin_compose() {
    local generated_compose="${LAB_DIR}/compose.plugin.yaml"
    local compose_override="${LAB_DIR}/compose.override.yaml"
    local override_matches_previous_template=0

    if [ -f "${compose_override}" ] \
        && [ -f "${generated_compose}" ] \
        && cmp -s "${compose_override}" "${generated_compose}"
    then
        override_matches_previous_template=1
    fi

    cp "${PLUGIN_COMPOSE_SOURCE}" "${generated_compose}"

    if [ -f "${LAB_DIR}/src/app/etc/env.php" ]; then
        if [ ! -f "${compose_override}" ] || [ "${override_matches_previous_template}" = "1" ]; then
            cp "${generated_compose}" "${compose_override}"
        else
            echo "Preserving customized ${compose_override}."
            if ! grep -Fq 'target: /var/www/html/app/code/Rejoiner/Acr/.local' "${compose_override}"; then
                echo "Merge ${generated_compose} into it before starting the Magento lab." >&2
                return 1
            fi
        fi
    fi
}

if ! docker info >/dev/null 2>&1; then
    echo "Docker Desktop is not running or is not accessible." >&2
    exit 1
fi

if [ -x "${LAB_DIR}/bin/download" ]; then
    printf 'REJOINER_MODULE_PATH=%s\n' "${REPOSITORY_ROOT}" > "${LAB_DIR}/.env"
    sync_plugin_compose
    echo "Magento Docker template already exists at ${LAB_DIR}"
    exit 0
fi

if [ -d "${LAB_DIR}" ] && [ -n "$(find "${LAB_DIR}" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
    echo "Refusing to overwrite non-empty directory: ${LAB_DIR}" >&2
    exit 1
fi

TEMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/queenone-magento-template.XXXXXX")"
cleanup() {
    rm -rf "${TEMP_DIR}"
}
trap cleanup EXIT

echo "Downloading docker-magento ${TEMPLATE_VERSION}..."
git clone --quiet --depth 1 --branch "${TEMPLATE_VERSION}" \
    https://github.com/markshust/docker-magento.git "${TEMP_DIR}/docker-magento"

mkdir -p "${LAB_DIR}"
cp -R "${TEMP_DIR}/docker-magento/compose/." "${LAB_DIR}/"
sync_plugin_compose
printf 'REJOINER_MODULE_PATH=%s\n' "${REPOSITORY_ROOT}" > "${LAB_DIR}/.env"

echo "Docker template created at ${LAB_DIR}"
echo "Next: make magento-install"
