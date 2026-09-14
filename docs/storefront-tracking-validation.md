# Storefront tracking validation

## Local automated checks

Run dependency-free JavaScript tests with Node 22:

```sh
node --test Test/JavaScript/*.test.cjs
```

From `.local/magento`, using the installed Magento PHP/PHPUnit:

```sh
bin/cli vendor/bin/phpunit --no-configuration --bootstrap app/bootstrap.php app/code/Rejoiner/Acr/Test/Unit
bin/cli php app/code/Rejoiner/Acr/dev/magento/check-tracking.php
```

The configuration check validates Magento XML schemas, absence of legacy cron/observers, public initialization, private section registration, configured Tag CSP origin, and disabled behavior. It also exercises the installed frontend CSP collector graph with tracking enabled and disabled on home and checkout routes: Magento directives, static allowlists, dynamic nonces and checkout inline restrictions must survive, with only the configured Tag origin added. Its test config is request-local: nothing is saved and no Tag or external delivery executes.

Register the Tag collector in global `etc/di.xml`, alongside Magento's collectors; the collector itself limits its contribution to the frontend area. An area-specific `collectors` array replaces the global array instead of extending it ([Magento DI merge rules](https://developer.adobe.com/commerce/php/development/build/dependency-injection-file)). Do not compensate for a missing Magento policy by adding `unsafe-inline` or `unsafe-eval` in this module.

From the repository root:

```sh
MAGENTO_RUN_COMPILE=1 make magento-smoke
```

The historical queue/table checks are retained because schema removal is outside this milestone. The old conversion cron registration has been removed.

## Staging prerequisites

- Provision a **dedicated Magento staging Site ID**, scoped to the local/demo store's actual domain, with identity configured and without legacy/exchanges modules or another automatic producer of these storefront events.
- Tag URL: `https://queen-one-init-staging.queen-one.workers.dev/queen-one.js`.
- Observed core: `https://queen-one-core-staging.queen.one/queen-one-core.js` (redirects to a versioned asset).
- Observed Collector: `https://events.staging.queen.one/track`.
- Staging configuration is under `https://cdn.queen.one/t/config-stg/{SITE_ID}.json`.
- Do not use shared testbed Site ID `AvDEADL`: its cookie domain/modules are for the Tag testbed, not this Magento integration.
- Obtain access to the actual downstream staging event view. A queue entry, Tag readiness or HTTP success alone is not downstream confirmation.
- Use synthetic customer data. Provisioning, consent configuration and enabling real delivery are recorded explicitly below; do not put credentials in evidence.

In Magento Admin, open Stores → Configuration → Sales → Checkout → Queen One Connect. Set Site ID and Tag URL, enable tracking and optionally debug. Flush config/layout/full-page caches and invalidate the storefront private section after changing site/store configuration. No Rejoiner credentials are needed.

## Manual scenarios

Use DevTools Network with Preserve log and count events inside Collector batch bodies, not just requests. Inspect Console for uncaught errors. Capture event type, time, siteid, relevant source IDs and downstream lookup evidence. Redact emails from shared artifacts.

| Scenario | Expected result |
| --- | --- |
| Disabled; old Rejoiner settings still saved | No Connect initialization, Rejoiner script or Rejoiner API traffic |
| Home/category/product page | One `page_viewed` each document; product page also one `product_viewed` with entity ID |
| Initial persisted cart and repeated identical section reloads | One initial `cart_set`; no notification duplicates |
| Add simple/configurable, update quantity/options, remove one item | Complete `cart_set`, correct parent/child IDs, quantity, quote currency and row amounts |
| Apply/remove coupon | Updated `promo`; documented cart value semantics maintained |
| Remove last item; clear via redirect; reload empty cart | One transition `cart_reset` naming the prior cart; no repeated initial-empty resets |
| Login, repeat customer-data load, logout, login as another synthetic customer | One identity per current email/document; no logged-out or stale previous-customer identity |
| Cookie Restriction on, missing/malformed/wrong-website permission | No Tag load or events; private section withholds identity/cart |
| Accept cookies on current page | One current page/product and latest cart/identity flow; no pre-consent replay |
| Two store views/sites and full-page cache | Correct site/store config and identifiers; no cached customer data in HTML |
| Tag 404, blocked/slow script, core/config/Collector failure, throwing/rejecting push | Commerce still works; own errors are contained; no uncontrolled queue/retry loop |
| Home and restrictive checkout CSP, tracking enabled and disabled | Standard Magento directives remain; enabling tracking adds the configured script origin; checkout retains its inline restrictions and nonce/hash allowances; staging core/config/Collector origins remain allowed |
| Complete offline-payment checkout and reload success page | Checkout succeeds; zero frontend `order_created`; no legacy conversion API/queue entry |
| Bundle/fractional cart | Unsupported cart is skipped with diagnostic, never truncated or treated as empty |
| Catalog fixture lookup | Published parent/variant IDs resolve to the expected imported Magento products |

Also inspect server-side outbound traffic while exercising newsletter, checkout and old recovery URLs. Static disconnection and an HTTP client guard are implemented; a runtime no-Rejoiner-traffic claim still requires this evidence.

## Evidence record

| Check | Result / evidence |
| --- | --- |
| JS unit scenarios | PASS, 2026-09-11: 16 tests, Node 22.15.1; CI workflow added (remote CI not yet run) |
| PHP unit scenarios | PASS, 2026-09-11: 11 tests / 46 assertions, PHPUnit 12.5.33 on PHP 8.5.6 |
| PHP lint / Magento XML / configuration smoke | PASS, 2026-09-11: source lint, schemas, legacy cron/observer removal, enabled/disabled block, section and configured Tag CSP origin |
| Magento smoke / DI compilation | PASS, 2026-09-11: Magento 2.4.9, schema up to date, retained tables/columns present, DI compilation and storefront HTTP response |
| CSP regression fix | PASS, 2026-09-14: reproduced lost Magento directives with the frontend DI registration; global registration restores composition. PHP suite: 15 tests / 57 assertions; installed collector graph preserves enabled/disabled home and checkout policies, whitelist and dynamic nonce. DI compilation passed, caches cleaned; actual homepage HTTP 200 carries 14 CSP directives with Magento script permissions and staging Tag/core/Collector origins. Browser recheck remains manual |
| Real Magento product mapping | PASS, 2026-09-11: unsaved sample quotes; simple 24-MB01 → product 1, qty 2, USD 68; configurable MH01 → parent 62 / variant 47 (MH01-XS-Black), qty 2, USD 104. No order, persisted quote or external event was created |
| Browser commerce, consent, CSP and Network scenarios | Pending: no connected browser in implementation session |
| Dedicated Magento staging Site ID | Pending provisioning |
| Five events confirmed downstream | Pending provisioning and staging event-view access |
| Product/variant resolution against imported catalog | Pending staging catalog validation |

Do not mark Milestone 1 accepted until the browser and downstream rows are complete. The [deferred register](storefront-tracking-scope.md#deferred-capabilities-register) remains open after foundation acceptance, including backend conversion delivery in Milestone 2.
