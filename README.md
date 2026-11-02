# Queen One Connect for Magento 2

This branch provides the managed-pilot Queen One integration for Magento 2. It publishes
storefront page, product, cart and logged-in identity events through Queen One Tag and
delivers persisted orders to Bridge through a signed backend outbox. Legacy Rejoiner
runtime, API calls and feature registrations remain disconnected.

The internal module name `Rejoiner_Acr`, Composer identity and retained legacy database
tables are unchanged for upgrade compatibility. Store provisioning is still performed
with Queen One Ops for this pilot; self-service onboarding is a later milestone.

## Production defaults

The module defaults to the production services below. Merchants cannot edit these URLs
in Magento Admin.

- Tag: `https://queen-one-init.queen.one/queen-one.js`
- Bridge: `https://integrations.queen.one`

Staging operators may override the hidden configuration paths at the required scope:

```sh
bin/magento config:set checkout/queen_one_connect/tag_url \
  https://queen-one-init-staging.queen-one.workers.dev/queen-one.js
bin/magento config:set checkout/queen_one_connect/bridge_url \
  https://integrations.staging.queen.one
bin/magento cache:clean config layout full_page
```

Do not carry staging overrides into a production deployment. Verify the effective values
with `bin/magento config:show` before enabling delivery.

## Managed production installation

Install the reviewed `dev-queen-one` revision through the customer's Composer repository
and commit the resulting `composer.lock`. The locked source reference, not the moving
branch name, is the release boundary for the pilot. Do not run an unreviewed update of the
branch on a production store.

After installing the module and taking the normal database backup:

```sh
bin/magento module:enable Rejoiner_Acr
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:clean
```

Queen One Ops provisions the Bridge integration, Magento store binding and unique HMAC
secret. At the target **store view** scope under **Stores → Configuration → Sales →
Checkout → Queen One Connect**, configure the Site ID, Bridge Integration ID and encrypted
HMAC secret. Storefront and backend delivery can be enabled independently.

Activate backend delivery only after configuration has been verified:

```sh
bin/magento queenone:orders activate STORE_ID
bin/magento queenone:orders run STORE_ID
bin/magento queenone:orders status STORE_ID
```

Activation starts with orders created from that boundary forward. No history is sent
unless an operator explicitly runs the bounded backfill command. Standard Magento cron
must be running.

See [the managed production runbook](docs/managed-production-pilot.md),
[backend order operations](docs/backend-orders.md), and
[storefront validation](docs/storefront-tracking-validation.md).

## Supported pilot lane and known limitations

- Magento Open Source 2.4.9 is the primary tested lane; 2.4.8-p5 is the compatibility lane.
- Storefront tracking uses Magento RequireJS/customer-data and does not support Hyvä or
  headless storefronts in this release.
- Guest email capture and cart return URLs are deferred.
- Bundle/grouped/custom product structures and fractional quantities are not supported.
- Each Magento store requires its own Bridge integration binding.
- Catalog setup uses a manually created Magento integration token and the approved secure
  Ops process. The merchant must explicitly enable standalone integration bearer tokens.

## Local development

Prerequisites are Docker Desktop with at least 8 GB allocated, Git, Make and Adobe
Marketplace Composer credentials. Bootstrap the default Magento 2.4.9 lab with:

```sh
make magento-auth
make magento-install
make magento-smoke
```

Use `MAGENTO_VERSION=2.4.8-p5 make magento-install` for the compatibility lane. Generated
Magento files and credentials live under `.local/` and are excluded from Git. See the
[Magento lab guide](dev/magento/README.md) for the full workflow.
