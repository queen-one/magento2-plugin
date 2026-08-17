MAGENTO_LAB_DIR ?= $(CURDIR)/.local/magento
MAGENTO_VERSION ?= 2.4.9
MAGENTO_DOMAIN ?= queenone-magento.test
MAGENTO_WITH_SAMPLE_DATA ?= 1
DOCKER_MAGENTO_VERSION ?= 53.0.1

export MAGENTO_LAB_DIR
export MAGENTO_VERSION
export MAGENTO_DOMAIN
export MAGENTO_WITH_SAMPLE_DATA
export DOCKER_MAGENTO_VERSION

.PHONY: magento-bootstrap magento-auth magento-install magento-start magento-stop magento-status magento-smoke magento-shell

magento-bootstrap:
	./dev/magento/bootstrap.sh

magento-auth: magento-bootstrap
	cd "$(MAGENTO_LAB_DIR)" && bin/start --no-dev && bin/setup-composer-auth

magento-install:
	./dev/magento/install.sh

magento-start: magento-bootstrap
	cd "$(MAGENTO_LAB_DIR)" && bin/start

magento-stop:
	cd "$(MAGENTO_LAB_DIR)" && bin/stop

magento-status:
	cd "$(MAGENTO_LAB_DIR)" && bin/status

magento-smoke:
	./dev/magento/smoke-test.sh

magento-shell:
	cd "$(MAGENTO_LAB_DIR)" && bin/bash
