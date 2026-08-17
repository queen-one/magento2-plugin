# Local Magento lab progress

Last updated: 2026-08-17

Overall status: In progress

## Completed

- [x] Reviewed the plugin runtime and external dependencies.
- [x] Confirmed Docker Desktop is available on Apple Silicon.
- [x] Confirmed Docker has 8 CPUs and approximately 8 GB RAM allocated.
- [x] Verified all module PHP files parse successfully under PHP 8.3.
- [x] Selected and pinned docker-magento 53.0.1.
- [x] Added repeatable bootstrap, install and smoke-test commands.
- [x] Added a bind mount for live module development.
- [x] Added local setup and baseline-test documentation.

## In progress

- [x] Bootstrapped the generated `.local/magento` Docker project.
- [x] Validated the merged Docker Compose configuration for Magento 2.4.9.
- [x] Downloaded all pinned Docker service images.
- [x] Started the stack and verified nginx, MariaDB, OpenSearch, Valkey,
  RabbitMQ, Mailcatcher and PHP containers are healthy on Apple Silicon.
- [x] Verified the selected runtime is PHP 8.5.6 and OpenSearch 3.6.0.
- [ ] Install Magento Open Source and sample data.
- [ ] Install `Rejoiner_Acr` and verify its fresh-install schema.

## Next

- [ ] Run the automated smoke test.
- [ ] Open Admin and storefront and capture evidence.
- [ ] Execute the baseline functional scenarios.
- [ ] Convert confirmed defects and migration gaps into Jira tickets.

## External prerequisites

- Adobe Marketplace Composer public/private access keys for repo.magento.com;
  configure them locally with `make magento-auth`.
- One local sudo interaction for `/etc/hosts` and HTTPS certificate setup.
- Non-production Rejoiner credentials only for the later API-connected test.

## Observed tooling gap

The docker-magento 53.0.1 PHP 8.5 image currently reports Composer 2.9.8,
while the current Adobe Magento 2.4.9 requirements list Composer 2.10. Treat
this as a compatibility/Marketplace gate; it does not block local service
bootstrap or the initial legacy behavior investigation.
