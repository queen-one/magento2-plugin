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

## Findings

### Confirmed behavior

### Defects

### Keep / Replace / Redesign / Deprecate decisions

### Follow-up tickets
