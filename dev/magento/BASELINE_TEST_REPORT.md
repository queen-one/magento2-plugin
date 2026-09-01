# Legacy plugin baseline test report

Date:

Tester:

Magento version:

PHP version:

Module commit:

Overall result: Not started

## Environment evidence

- [ ] Docker services are healthy.
- [ ] Storefront URL responds.
- [ ] Admin login works.
- [ ] `Rejoiner_Acr` is enabled.
- [ ] `setup:db:status` is clean.
- [ ] Queue table and extension columns exist.
- [ ] No production credentials or personal data are present.

Evidence links or attachments:

## Functional results

| Scenario | Expected evidence | Result | Evidence / defect |
| --- | --- | --- | --- |
| Module disabled | No Rejoiner block or network traffic | Not run | |
| Product page | `setAccount`, `setDomain`, `trackProductView` | Not run | |
| Add to cart | `setCartData`, `setCartItem` | Not run | |
| Change quantity | Updated cart count, total and item | Not run | |
| Remove from cart | `removeCartItem` with SKU | Not run | |
| Customer login | Customer email/data commands | Not run | |
| Checkout success | `sendConversion` and one queue row | Not run | |
| Newsletter opt-in | Subscriber/list state change | Not run | |
| Dynamic coupon | Coupon and `quote.promo` state | Not run | |
| Cart recovery | Cart restored in disposable session | Not run | |
| Scheduled conversion | Deferred until cron defect is fixed | Blocked | Known legacy defect |

## Queen One shadow results

Frontend tracking mode:

Queen One tag URL:

| Scenario | Expected evidence | Result | Evidence / defect |
| --- | --- | --- | --- |
| Default mode | Only the existing Rejoiner frontend loads | Not run | |
| Dual mode page | `page_viewed`, `module_id: magento2` | Not run | |
| Product page | `product_viewed` with catalog-compatible product ID | Not run | |
| Cart mutation | One `cart_set` with full products and currency-aware Money values | Not run | |
| Empty cart | `cart_reset` | Not run | |
| Customer login | `user_identified` with synthetic email | Not run | |
| Checkout success | `order_created` with order ID, total and items | Not run | |
| Dual mode regression | Existing `_rejoiner` commands still appear | Not run | |
| Queen One mode | No `cdn.rejoiner.com` frontend script request | Not run | |

## Findings

### Confirmed behavior

### Defects

### Keep / Replace / Redesign / Deprecate decisions

### Follow-up tickets
