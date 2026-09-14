# Local Magento development lab

This directory keeps the reproducible setup for running the Connect-only module in a
real Magento storefront. Magento itself is generated in `.local/magento` and is
not committed.

## Pinned stack

- Mark Shust docker-magento 53.0.1;
- Magento Open Source 2.4.9 by default;
- PHP, OpenSearch, MariaDB, Valkey, RabbitMQ and nginx versions selected by the
  docker-magento compatibility table;
- Magento sample data and Mailcatcher;
- current repository mounted at `app/code/Rejoiner/Acr`, with the generated
  `.local` directory masked from the container.

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
- the generated `.local/magento` lab is not recursively exposed inside the
  module mount;
- PHP linting for the compatibility-sensitive helper and observer;
- `Rejoiner_Acr` module status;
- Magento database schema status;
- the plugin queue table and both legacy extension columns;
- an HTTPS response from the storefront.

Run dependency-injection compilation as an additional gate:

    MAGENTO_RUN_COMPILE=1 make magento-smoke

If a schema assertion fails, keep the failure as evidence and create a bug.
The legacy setup scripts work in the current compatibility lane, but replacing
them with declarative schema remains a separate Marketplace-readiness task.

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

`compose.override.yaml` is refreshed automatically while it still matches the
generated plugin template. If you customize that file, the setup preserves it
and verifies that its `.local` mask is present. The setup stops before starting
containers if you must merge the updated `compose.plugin.yaml` manually. Keep
the nested `.local` volume mask when merging; bind mounts do not honor
`.dockerignore` exclusions.

Cron is deliberately not started by this repository. The legacy conversion job
is disconnected on this branch; backend Connect order delivery is a later milestone.

## Storefront tracking validation

Configure Stores > Configuration > Sales > Checkout > Queen One Connect with a
dedicated Magento staging Site ID and the externally hosted QO Tag URL. Tracking
is disabled by default; no Rejoiner credentials are required.

Use the [tracking validation runbook](../../docs/storefront-tracking-validation.md)
for automated checks, browser scenarios, CSP/consent checks and downstream evidence.
The [scope and deferred register](../../docs/storefront-tracking-scope.md) records
which Rejoiner capabilities are deliberately not migrated in this milestone.
`BASELINE_TEST_REPORT.md` remains a historical record of the earlier Rejoiner audit.

## Resetting the lab

The upstream generated lab contains destructive removal helpers. They are not
exposed through this repository's Makefile. If a clean rebuild is required,
first preserve any evidence, inspect the exact `.local/magento` target, and
then use the upstream removal command intentionally.
