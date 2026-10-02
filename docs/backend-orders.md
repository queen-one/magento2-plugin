# Connect backend orders (Milestone 2)

Status: implementation, not customer-pilot acceptance. No Rejoiner API key is needed.
Storefront order_created remains disabled; only backend persisted orders are authoritative.
Consent/list APIs, catalog onboarding and Composer publication remain separate work.

## Setup and activation

1. Deploy module code and run `bin/magento setup:upgrade` (back up the database
   using your normal release procedure). New tables are `queen_one_order_boundary`
   and `queen_one_order_outbox`. The legacy Rejoiner table is untouched.
2. Ops provision an active Magento2 Connect integration with the approved QO site,
   exact Magento store ID and a unique 64-character lowercase hex HMAC secret.
   See Bridge `src/platforms/magento2/events/README.md` and its provisioning script.
3. Under Sales → Checkout → Queen One Connect, at the target **store view** scope,
   set Site ID, Bridge Base URL (HTTPS), Integration ID and Backend HMAC Secret.
   The admin backend encrypts the secret; it is also marked sensitive for config
   export. Do not write a plaintext secret using config:set, put it in public HTML,
   commit it, or reuse a catalog/buyer/Connect token. Frontend and backend flags are independent.
4. Enable Backend Order Delivery for that store, clean config cache, then explicitly
   activate it using the command below. No orders are discovered until activation.
   The activation boundary, not the time you edited a config field, starts delivery.
5. Run standard Magento cron in production; the `queen_one_order_outbox` job runs
   every minute. The development lab does not start cron automatically.

```
bin/magento queenone:orders activate 1
bin/magento queenone:orders run 1
bin/magento queenone:orders status 1
```

Each store needs its own Bridge integration binding. Storefront/Admin/API orders
use their persisted store ID; Admin-created orders normally belong to the store
selected by the operator. Real store-0 orders require explicit store-0 setup.
Changing integration/site after activation fails closed; this is not an implicit
historical replay or tenant migration mechanism. Endpoint is pinned on each row;
an endpoint change does not silently redirect queued customer data elsewhere.

## Discovery, retries and recovery

The activation command records UTC time and the highest visible order entity ID.
Cron scans committed orders at/after that boundary, 100 per pass. It anti-joins the
outbox instead of advancing a cursor, so out-of-order commits after activation are
not skipped. Pre-boundary orders require explicit backfill. Native payload is a
snapshot at discovery, not a reconstruction of data before subsequent order edits.
Disabling backend delivery pauses processing; re-enabling resumes from the same
boundary, including orders created while paused. It does not reset history.

Only background cron/explicit CLI touches the outbox. No integration observer,
mapping, network call or queue write is added to browsing/cart/checkout/order creation.
Per-store Magento locks serialize cron and operator writes. Maximum 20 deliveries
per store per pass; 3-second connect / 10-second total HTTP timeout, no redirects,
TLS verification enabled. Locks recover when the worker connection/process exits.

Outbox status:

- `pending`: retryable transport/408/429/5xx or not yet attempted. Exponential backoff
  starts at 30 seconds, capped at one hour; ten attempts, then failed.
- `accepted`: Bridge returned **202**, not proof of downstream processing.
- `failed`: invalid credentials/payload/configuration, mapping/serialization failure,
  unexpected HTTP response, or exhausted attempts. Nothing is silently deleted.

`status` shows the most recent 100 records without email/payload/secret. Inspect older
rows by event/order ID in the database with restricted operator access. Payload
contains email where available; do not paste it into logs, tickets or Slack.

After fixing a failed local record's cause:

```
bin/magento queenone:orders retry 1 --id=OUTBOX_ROW_ID
bin/magento queenone:orders run 1
```

Retries preserve saved JSON/event ID and regenerate timestamp/signature. Only if
serialization failed before a payload existed does explicit retry rebuild it.
Already-accepted rows cannot be locally replayed: inspect/retry the existing Bridge
job instead. Unsupported canonical quantities/products fail there, visibly, without
rounding, dropping lines, or retry storms. Bridge owns all canonical mapping.

Explicit controlled historical backfill (maximum range of 1000 entity IDs):

```
bin/magento queenone:orders backfill 1 --from=100 --to=150
bin/magento queenone:orders run 1
```

This selects only the store's orders, never replaces an existing logical event,
and requires an activated/enabled store. Review the exact range and campaign effects
before running it outside a lab. There is no automatic historical replay.

Delivery is at least once. Local unique event IDs, stable Bridge job IDs, seven-day
dedup and a confirmed-publish marker suppress normal retries. A publish/ack crash
can still duplicate an event; Collector acceptance is not exactly-once campaign
execution. Use site/order identity for downstream idempotency. Agree production
payload retention, failed-job alerts and operational recovery ownership before pilot.

## Native contract

`Test/Fixtures/order.json` matches Bridge's fixture. IDs and amounts are native
strings, times UTC, all order lines retained (including configurable children).
No addresses/payment/notes. Event ID is SHA256 of integration ID, newline, site ID,
newline, order entity ID. Signature is HMAC-SHA256 of `timestamp + '.' + exact JSON`,
using the 64-character secret as text. Bridge accepts ±5 minutes; keep clocks synced.
The fixture is not a credential and must not be used for a real installation.

## Local and staging verification

From the Magento root:

```
vendor/bin/phpunit --no-configuration --bootstrap app/bootstrap.php app/code/Rejoiner/Acr/Test/Unit
php app/code/Rejoiner/Acr/dev/magento/check-tracking.php
QO_LOCAL_BACKEND_TEST=1 php app/code/Rejoiner/Acr/dev/magento/check-backend-orders.php
```

The last script is guarded to `queenone-magento.test`. It creates/retains a virtual
fixture product, quote/order and outbox evidence; uses request-local test config
without modifying your configured QO site or secret. It records a test boundary for
store 1 and refuses to replace an existing non-test boundary. It checks real order
creation, real failed HTTPS transport, simulated 202 recovery and repeat-scan dedup.
It does **not** prove real Bridge or EC delivery. Do not activate a staging/customer
installation on that same test-boundary store without an explicit lab reset/reprovisioning decision.

For end-to-end staging: configure a dedicated staging store/site/secret, stop Bridge,
place one order, run cron and confirm pending. Restore Bridge, wait for backoff or
operator-retry a failed row, trace the same event ID through outbox → queue → downstream
order_created. Check configurable/simple identifiers, discounts, guest/registered
and Admin/API sources, no frontend order duplicate and no Rejoiner traffic. Then
close M1 browser/five-event/catalog acceptance. Until then, customer pilot is blocked.
