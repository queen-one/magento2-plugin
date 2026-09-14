# Queen One Connect storefront tracking scope

Decision date: 2026-09-11. Status: implemented foundation; browser and downstream staging acceptance pending.

This milestone deliberately implements a minimum storefront event set. It is **not feature parity with Rejoiner** and does not complete the full Connect migration. The deferred capabilities below are future candidates, not retired features or delivery commitments.

## Architecture and configuration

Magento customer-data → Magento adapter → externally loaded Queen One Tag → Event Collector.

`storefront-tracking.js` owns Magento subscriptions, consent, a bounded pre-load buffer and duplicate suppression. `event-mapper.js` maps source data. Neither implements the Tag runtime, accesses its internal queue, nor sends HTTP events directly. The adapter uses only `window.__qotag.push({type, siteid, time, data})` and the optional public `getSiteId()` accessor.

Admin: Stores → Configuration → Sales → Checkout → **Queen One Connect**. The `checkout/queen_one_connect/` settings are `enabled`, `site_id`, `tag_url`, `debug`, with default/website/store inheritance. Tracking defaults to off. An enabled but incomplete configuration remains inactive. No Rejoiner credentials are read.

The configured HTTPS Tag origin is added to script CSP. The bundled whitelist covers the verified staging core, configuration CDN and Collector; other environments require their actual downstream origins to be added explicitly. There is no wildcard Queen One or Rejoiner whitelist.

## Supported event matrix

| Event | Trigger | Payload |
| --- | --- | --- |
| `page_viewed` | Once per document after tracking permission | `url` |
| `product_viewed` | Once per product detail document | `product_id` |
| `cart_set` | Fresh initial nonempty cart; meaningful subsequent cart changes | `cart_id`, `cart_value`, `cart_item_count`, full `products`, optional `promo` |
| `cart_reset` | Previously observed nonempty cart becomes explicitly empty | Previous `cart_id` |
| `user_identified` | Logged-in customer email becomes known or changes | `email` only |

The initial customer-data cache is not published. After Magento initializes, the adapter requests one fresh `queen-one-connect` section and then reuses subscriptions. Duplicate section notifications, timestamps, `data_id` and item order do not cause duplicate events. Deduplication is per document; an unchanged persisted cart is published once again on the next document as required by the Tag integration contract.

Only the last cart ID is retained in sessionStorage, scoped by site and store, so clearing a cart across a redirect can publish a reset. No email or event payload is persisted by the adapter. Blocked storage falls back to document-local state, so cross-document resets cannot be guaranteed in that case. Missing, unsupported or failed cart extraction never means an empty cart. Independently available email identity can still be published when cart extraction fails. Magento customer-data itself retains its normal private-content storage behavior.

## Source contract and identifiers

The private `queen-one-connect` section uses version `1`, `status` (`ready`, `unavailable`, `error`), `store_id`, `site_id`, nullable `identity` and a complete `cart`. These are structured JSON objects, not the nested JSON strings used by the prototype. Personal data is not embedded in full-page-cache HTML.

- Product identifiers are Magento entity IDs represented as strings, matching the existing Bridge catalog extractor's source identifiers.
- A configurable cart line uses its parent entity ID plus the chosen simple product entity ID as `variant_id`. The parent and child SKUs remain in the Magento section for future catalog mapping, not in the canonical event.
- A simple product has its own entity ID and no invented variant. Product-page variant-selection tracking is deferred; the initial page event names the displayed product.
- Money uses quote currency and decimal major units. Unit and row amounts include tax before cart-level discounts; `cart_value` is the sum of row amounts, excluding shipping. `promo` reports the applied code; it does not represent coupon generation or a discounted payable order total.
- Quantities are positive integers within the current Collector int32 contract. Fractional quantities are not rounded: the entire unsupported cart snapshot is skipped.
- Simple, configurable, virtual and downloadable lines are supported. Bundle cart price/quantity ownership needs separate mapping; a cart containing a bundle is skipped as a whole rather than published incompletely. Grouped purchases are supported when Magento represents them as ordinary simple quote lines.
- Final catalog resolution must still be verified against the selected staging import; reading the extractor is not end-to-end catalog acceptance.

## Consent and failures

With Magento Cookie Restriction enabled, the adapter requires `user_allowed_save_cookie` to allow the current website. It loads no Tag and buffers no events before consent. The PHP section also withholds identity/cart data before this permission. Accepting cookies starts the current page/product/cart/identity flow without replaying pre-consent activity. When Cookie Restriction is off, enabling tracking in Admin permits loading.

The adapter checks consent before publishing and on Magento's acceptance event/customer-data updates/page visibility changes. This is the native Magento cookie permission contract, not a general CMP or marketing subscription consent implementation. It cannot cancel network work already handed to an external Tag, unload its modules, or revoke Tag-owned identity; a broader live-revocation contract is deferred.

The adapter's pre-load buffer holds at most 100 events for 15 seconds. Load errors, timeout, overflow or conflicting site ownership disable its publication for that document. Exceptions and rejected pushes do not propagate to commerce callbacks. Tracking logs contain diagnostic codes/error types, not customer payloads. The Tag remains responsible for delivery and retry behavior after accepting a push. HTTP acceptance and downstream visibility must be tested separately.

## Rejoiner disconnection

This release has no legacy or dual mode. Old frontend layouts, RequireJS hooks, customer-data section, backend observers, cron, newsletter plugins and recovery route registrations are disconnected. The legacy HTTP client entry point is disabled even if old credentials remain saved. Turning Connect off does not reactivate Rejoiner.

The internal `Rejoiner_Acr` module, Composer identity and old tables are retained pending a separate packaging/cleanup decision. Dormant legacy classes/templates remain in the repository. Reintroducing old custom theme overrides or running an old release is not supported by this foundation.

## Deferred capabilities register

All entries have status **Deferred**. External ticket links are intentionally unassigned until real follow-up issues exist.

| ID | Capability / current limitation | Reason and dependency | Completion criterion | Ticket |
| --- | --- | --- | --- | --- |
| SF-01 | Extended customer attributes | Outside the five-event foundation; validate accepted fields and downstream attribute updates | Agreed fields mapped, consent behavior tested, profile updates confirmed downstream | — |
| SF-02 | Guest checkout/form email and phone identity | Only logged-in email is approved; requires Magento capture hooks and timing/consent decisions | Approved guest/form flows identify the correct contact without duplicate or premature capture | — |
| SF-03 | `trackNumbers` / `persistForms` equivalents | Runtime behaviors are not replaced simply by adding an event; QO parity unverified | Existing Rejoiner behaviors inventoried and selected equivalents validated | — |
| SF-04 | Conversion tracking | Milestone 2: reliable Magento order lifecycle → queue → Bridge → `order_created` | One complete authoritative order downstream, retries/deduplication tested, no dependence on success page | — |
| SF-05 | Newsletter, lists and subscription consent | Explicitly outside this milestone; list mapping, UI and backend flows required | Subscribe/unsubscribe and consent changes validated end-to-end | — |
| SF-06 | Coupon generation | Reporting `promo` is supported; generating Magento coupons is a separate capability | Selected generation rules, delivery and redemption tested | — |
| SF-07 | Cart restoration links | Cart state tracking is supported; legacy restoration endpoint is disconnected | Recovery URL restores correct products, quantities and options with approved access behavior | — |
| SF-08 | Explicit checkout event | Five events selected as the minimum; campaign need not yet established | Agreed event name, payload and checkout trigger validated without duplicate events | — |
| SF-09 | Bundles, fractional quantities, variant-selection events | Source/schema mapping needs expansion | Complete cart/event payloads preserve quantities, amounts and identifiers for these cases | — |
| SF-10 | Hyvä/headless and broader CMP/revocation support | RequireJS/customer-data and native cookie consent are the first lane | Each additional storefront/consent integration has its own passing validation matrix | — |

### Temporary conversion gap

The legacy module sends order details through frontend `sendConversion`, and separately calls backend Rejoiner `convert` with only the purchaser email, immediately or via cron. Both paths originate from the success-page flow. This milestone disconnects both, and does **not** replace them with frontend `order_created`.

Connect therefore has no purchase/conversion signal from this plugin until SF-04 / Milestone 2 is delivered. This foundation alone must not be described as a complete production campaign migration. In particular, purchase-based suppression and post-purchase behavior are not validated by these five storefront events.

See [staging validation](storefront-tracking-validation.md) for commands, manual scenarios and outstanding acceptance evidence.
