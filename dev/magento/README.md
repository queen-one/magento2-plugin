# Local Magento development lab

This directory keeps the reproducible setup for running the legacy module in a
real Magento storefront. Magento itself is generated in `.local/magento` and is
not committed.

## Pinned stack

- Mark Shust docker-magento 53.0.1;
- Magento Open Source 2.4.9 by default;
- PHP, OpenSearch, MariaDB, Valkey, RabbitMQ and nginx versions selected by the
  docker-magento compatibility table;
- Magento sample data and Mailcatcher;
- current repository mounted at `app/code/Rejoiner/Acr`.

Magento 2.4.8-p5 can be selected with `MAGENTO_VERSION=2.4.8-p5`. Keep 2.4.9 as
the forward/Marketplace lane and add exact client versions after the client
inventory is known.

## Why the remote one-liner is not committed

The upstream one-liner currently defaults to Mage-OS and tracks the mutable
master branch. This setup downloads the pinned docker-magento 53.0.1 tag and
passes `community` explicitly, making the environment reviewable and
repeatable.

Upstream references:

- https://github.com/markshust/docker-magento
- https://github.com/markshust/docker-magento/releases/tag/53.0.1
- https://experienceleague.adobe.com/en/docs/commerce-operations/installation-guide/system-requirements
- https://experienceleague.adobe.com/en/docs/commerce-operations/installation-guide/prerequisites/authentication-keys

## Step 1: prerequisites

Start Docker Desktop and allocate at least 8 GB RAM. Obtain a public/private
key pair from Adobe Marketplace Access Keys. Composer treats the public key as
the username and the private key as the password. Never add either key or an
`auth.json` file to Git.

The setup uses a local HTTPS domain and may ask for the macOS password when it
updates `/etc/hosts` or trusts the local certificate authority.

## Step 2: bootstrap only

This downloads the pinned Docker template but does not install Magento:

    make magento-bootstrap

Generated files are written to `.local/magento`.

## Step 3: full install

Configure Composer access once from your own terminal:

    make magento-auth

This is intentionally interactive so the access keys never pass through Jira,
chat, a setup script argument or Git. Then run:

    make magento-install

The command performs these operations in order:

1. Downloads Magento Open Source 2.4.9 from repo.magento.com.
2. Installs Magento and its local services.
3. Adds sample data and disables 2FA for this development-only lab.
4. Activates the bind mount for this repository.
5. Enables `Rejoiner_Acr` and runs `setup:upgrade`.
6. Flushes Magento caches.

Local URLs and default docker-magento credentials:

- Storefront: https://queenone-magento.test/
- Admin: https://queenone-magento.test/admin/
- Admin username: john.smith
- Admin password: password123
- Mailcatcher: http://queenone-magento.test:1080/

These credentials are development-only.

To skip sample data:

    MAGENTO_WITH_SAMPLE_DATA=0 make magento-install

To use another domain or generated lab location:

    MAGENTO_DOMAIN=my-magento.test make magento-install
    MAGENTO_LAB_DIR=/absolute/path/to/lab make magento-install

## Step 4: automated smoke test

Run:

    make magento-smoke

It verifies:

- PHP and Magento runtime availability;
- `Rejoiner_Acr` module status;
- Magento database schema status;
- the plugin queue table and both legacy extension columns;
- an HTTPS response from the storefront.

Run dependency-injection compilation as an additional gate:

    MAGENTO_RUN_COMPILE=1 make magento-smoke

If a schema assertion fails, keep the failure as evidence and create a bug.
The current legacy InstallSchema/UpgradeSchema split may not create all objects
on a genuinely fresh installation.

## Useful commands

    make magento-start
    make magento-stop
    make magento-status
    make magento-shell

Direct Magento commands can be run from the generated lab:

    cd .local/magento
    bin/magento module:status Rejoiner_Acr
    bin/magento cache:flush
    bin/mysql
    bin/log

Cron is deliberately not started by this repository. Do not run
`bin/cron start` with real credentials until the scheduled conversion bug is
fixed.

## Safe first functional test

Use only synthetic customer/order data. In Magento Admin navigate to:

    Stores > Configuration > Sales > Checkout
    > eCommerce Email Marketing by Rejoiner

For an offline browser inspection use a non-production site ID and key, leave
the API secret empty, leave scheduled conversion enabled, and disable customer
list, marketing and coupon features. In browser DevTools block
`*rejoiner.com*` before enabling the module.

Then inspect `window._rejoiner` while performing these actions:

1. Open a product page.
2. Add, update and remove a cart item.
3. Register and log in with a synthetic customer.
4. Place an offline-payment test order.
5. Open the cart-recovery URL only in a separate disposable/incognito session.

Record the evidence in [BASELINE_TEST_REPORT.md](BASELINE_TEST_REPORT.md).

## Resetting the lab

The upstream generated lab contains destructive removal helpers. They are not
exposed through this repository's Makefile. If a clean rebuild is required,
first preserve any evidence, inspect the exact `.local/magento` target, and
then use the upstream removal command intentionally.
