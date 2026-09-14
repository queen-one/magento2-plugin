# Queen One Connect for Magento 2

This development branch publishes storefront page, product, cart and logged-in
customer identity events through the externally loaded Queen One Tag. It is
Connect-only: Rejoiner runtime, API calls and feature registrations are disconnected.

The internal module name `Rejoiner_Acr`, Composer identity and old database
tables remain for now. This is a tracking foundation, not a complete migration:
backend `order_created` and the remaining features are tracked separately.

- [Supported events and deferred capabilities](docs/storefront-tracking-scope.md)
- [Automated checks and staging validation](docs/storefront-tracking-validation.md)

Configure **Stores → Configuration → Sales → Checkout → Queen One Connect**
with the site ID and HTTPS Tag installation URL for your environment. Tracking
is off by default and respects Magento Cookie Restriction when enabled.

## Local development

Prerequisites:

- Docker Desktop with at least 8 GB allocated to Docker;
- Git and Make;
- Adobe Marketplace public/private Composer access keys for repo.magento.com;
- permission to add the local domain to `/etc/hosts` and install a local CA.

Bootstrap and install Magento Open Source, sample data and this module:

    make magento-auth
    make magento-install

The first command stores Adobe Marketplace Composer keys in the local
docker-magento Composer home. It is interactive and must be run by the
developer; the keys are never stored in this repository.

The default compatibility lane is Magento Open Source 2.4.9. To create the
older compatibility lane instead:

    MAGENTO_VERSION=2.4.8-p5 make magento-install

After installation:

    make magento-status
    make magento-smoke
    make magento-start
    make magento-stop

The generated Magento source, databases and local credentials live under
`.local/` and are intentionally excluded from Git. The module repository is
bind-mounted into Magento at `app/code/Rejoiner/Acr`, so source changes are
visible without copying the module. A nested Docker volume masks `.local` from
the container so the generated Magento application is not recursively scanned
as part of the module.

See [the Magento lab guide](dev/magento/README.md) for setup details, safety
notes and the manual functional walkthrough.

## Validation status

Use a dedicated Magento staging site and synthetic data for validation. Browser
and downstream acceptance remain pending until that site and an event view are
available. The legacy conversion cron is no longer registered. Conversion
tracking will be implemented through the backend/Bridge milestone; this
foundation alone is not a production campaign migration.
