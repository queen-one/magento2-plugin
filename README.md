# Rejoiner Magento 2 extension

This repository contains the legacy Rejoiner Magento 2 module registered as
`Rejoiner_Acr`. It tracks storefront product, cart, customer and conversion
activity and contains newsletter, coupon and cart-recovery integrations.

The first development milestone is to preserve and observe the current
behavior before migrating it to Queen One. A reproducible local Magento lab is
provided under `dev/magento`.

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

## Safety

Do not use production Rejoiner or Queen One credentials in the local lab.
Keep Magento cron stopped until the scheduled conversion implementation has
been fixed and verified: the legacy job can select the wrong order and process
already-sent queue rows again.
