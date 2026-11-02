# Managed production pilot runbook

This runbook covers the first production customer while Magento provisioning remains an
Ops-assisted process. It does not create or rotate Bridge credentials.

## Before deployment

1. Confirm the exact customer Magento/PHP version, storefront type and product types.
2. Confirm one approved Queen One Site ID and Bridge integration per Magento store.
3. Pin the reviewed `dev-queen-one` source reference in the customer's `composer.lock`.
4. Back up the Magento database and record the prior module revision.
5. Confirm standard Magento cron, synchronized host time and outbound HTTPS access.

## Install and preflight

Install the module, run `setup:upgrade` and DI compilation, then verify the effective
endpoints before enabling either delivery path:

```sh
bin/magento config:show checkout/queen_one_connect/tag_url
bin/magento config:show checkout/queen_one_connect/bridge_url
```

They must resolve to the approved production Tag and Bridge URLs. Configure Site ID,
integration ID and HMAC secret at the target store-view scope. Never place the secret on
the command line, in source control, logs, tickets or Slack.

For catalog import, the merchant creates a least-privilege Magento integration, explicitly
enables standalone integration bearer tokens, and transfers the token through the approved
secret channel. Ops validates and saves it through the existing Bridge
`POST /platforms/magento2/auth` flow.

## Enable and verify

1. Enable storefront tracking, clear Magento caches, and verify consent before any event.
2. Check home, product, cart and enforced checkout CSP in a normal browser.
3. Enable backend delivery for the target store and run:

   ```sh
   bin/magento queenone:orders activate STORE_ID
   bin/magento queenone:orders run STORE_ID
   bin/magento queenone:orders status STORE_ID
   ```

4. Place one synthetic production order and trace its event ID through Magento outbox,
   Bridge queue and downstream `order_created`.
5. Verify storefront events, catalog product/variant resolution and the absence of legacy
   Rejoiner requests.

## Operations and rollback

Monitor Magento cron, pending/failed outbox rows and Bridge failed jobs. A Bridge `202`
only proves queue admission, not downstream campaign execution.

To stop new data safely, disable storefront tracking and backend order delivery at the
same store scope and clean the config cache. Disabling backend delivery pauses discovery
without deleting the activation boundary or outbox. Do not delete rows, rotate credentials
or replay accepted records during rollback. Restore the prior locked module revision only
through the customer's normal deployment and database-backup procedure.
