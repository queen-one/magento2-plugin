#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
LAB_DIR="${MAGENTO_LAB_DIR:-${REPOSITORY_ROOT}/.local/magento}"
TEMPLATE_VERSION="${DOCKER_MAGENTO_VERSION:-53.0.1}"

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Missing required command: $1" >&2
        exit 1
    fi
}

require_command docker
require_command git

if ! docker info >/dev/null 2>&1; then
    echo "Docker Desktop is not running or is not accessible." >&2
    exit 1
fi

if [ -x "${LAB_DIR}/bin/download" ]; then
    printf 'REJOINER_MODULE_PATH=%s\n' "${REPOSITORY_ROOT}" > "${LAB_DIR}/.env"
    cp "${SCRIPT_DIR}/compose.plugin.yaml" "${LAB_DIR}/compose.plugin.yaml"
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
cp "${SCRIPT_DIR}/compose.plugin.yaml" "${LAB_DIR}/compose.plugin.yaml"
printf 'REJOINER_MODULE_PATH=%s\n' "${REPOSITORY_ROOT}" > "${LAB_DIR}/.env"

echo "Docker template created at ${LAB_DIR}"
echo "Next: make magento-install"
