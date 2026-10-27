# BNPL-1665 validation: popup orders keep Magento's native reservation

Local validation of variant C on Magento 2.4.8 with MSI, run on 2026-10-27.
Nothing here was run on QA, STG or production. Nothing was paid, and Aplazo was never called.

## Base

- Branch `BNPL-1665-popup-native-reservation`, one commit on top of the published BNPL-1664 HEAD
  `d3d72ef001fb583499f8dbbab8a3649f51af1bce` (`origin/BNPL-1664-popup-config-messaging`, commit
  "BNPL-1664: validate the popup checkout URL and keep late terminal messages"). **It depends on BNPL-1664**:
  the popup flow, `Model/PopupCheckout.php` and the `checkout_mode` setting come from that branch.
- The tests below ran on the working tree that became that commit; only this note changed afterwards.
- The discovery checkout (`BNPL-1607-checkout-discovery`) and the BNPL-1664 worktree were not modified.

## What changes

The order records, when it is created, whether it is a popup checkout. Orders with that mark keep Magento's
native MSI reservation for their whole life, and the plugin applies none of its own stock movements to them.
Orders without the mark keep today's behaviour unchanged, including redirect.

| Point | Before (master and BNPL-1664) | Marked popup order (BNPL-1665) | Unmarked order |
|---|---|---|---|
| Order placed (MSI) | `−1 order_placed` | `−1 order_placed` | `−1 order_placed` |
| `purchase` (`Operations::purchase`) | `+1 aplazo_item_reserved` every time it is reached | nothing, with or without `mode=popup` | unchanged (`+1`) |
| Approval (`OrderService::approveOrder`: webhook `Activo` and cron `OUTSTANDING`) | `−1 order_placed_aplazo` | nothing | unchanged (`−1`) |
| Cancellation (`Observer\Order\CancelAfter`) | `−1 order_canceled_aplazo`, in addition to Magento's `+1 order_canceled` | only Magento's `+1 order_canceled` | unchanged |
| Shipment (MSI) | `+1 shipment_created`, physical −1 | same | same |

Balance of a marked order: cancelled → `−1 +1 = 0`. Paid → `−1` until the shipment, then `−1 +1 = 0` with physical
quantity −1. This is the movement set Magento applies to any order.

**Consequence for the store.** A popup order holds its stock while it is pending, until it is paid or
cancelled. It is cancelled when the buyer follows the cancel link, when it is cancelled from admin, or when
the cancel cron cancels it after `cancel_time` (720 minutes in this store's configuration; the cron only
processes it if it is scheduled and enabled in that store). Redirect orders release it at `purchase`, as
before. An early close of the window (before `purchase`) no longer changes the result: the order keeps its
reservation exactly like a normal popup order.

### Files

| File | Change |
|---|---|
| `Model/CheckoutModeMarker.php` (new) | Key `aplazo_checkout_mode` of the payment's `additional_information`; `isPopupOrder($order)` reads only that mark |
| `Observer/AssignCheckoutMode.php` (new), `etc/events.xml` | On `payment_method_assign_data_aplazo_gateway`, quote payments only: writes the mark when the request asks for popup **and** popup mode is effective for the quote's store; otherwise removes it. Runs on every `assignData`. Magento copies it to the order payment (`ToOrderPayment`) |
| `Helper/Data.php` | `getCheckoutMode()` and `getCheckoutOrigin()` take an optional store id (null keeps the current store) |
| `Controller/Order/Operations.php` | `purchase`: no `reservingStockUntilPayment` for marked orders (popup and redirect branches) |
| `Model/Service/OrderService.php` | `approveOrder`: no `decreasingStockAfterPaymentSuccess` for marked orders; logs it |
| `Observer/Order/CancelAfter.php` | No `decreasingStockAfterPaymentSuccess` for marked orders; `cancelLoan` still runs |
| `view/frontend/.../aplazo-gateway.js` | `getData()` sends `additional_data.aplazo_checkout_mode = popup` only when this attempt opened the Aplazo window |

Not changed: `MSIStock`, `InventoryStock`, the redirect flow's stock handling (including master's repeated
`+1` on a repeated `purchase`), reopening (still disabled), order statuses. No compensation state, no manual
transaction, no lock, and nothing from the BNPL-1607 experiment.

### Test kit changes (local only, never deployed)

| File | Change |
|---|---|
| `magento-e2e/run.py` | `c1665_*` scenarios and helpers: cancel link, admin cancel, cron, webhook, shipment, stock snapshot |
| `magento-e2e/snapshot.php` | Also reports the order's `aplazo_checkout_mode`, invoices and shipments |
| `magento-e2e/order_ops.php` (new) | Admin cancel (`OrderManagementInterface::cancel`), shipment (`ShipOrderInterface`), creation of `APLAZO-1665-*` test products |
| `magento-e2e/stub-module/Service/ApiService.php` | `getLoanStatus` answers from `var/localdev_aplazo_stub_loan_status` (no file: not found). The plugin's real `shouldCancelOrder` decides |
| `magento-e2e/stub-module/Plugin/CronAllowlist.php` (new), `stub-module/etc/di.xml` | While `var/localdev_aplazo_cron_allowlist` exists, the cancel cron's collection only contains the listed orders, whatever their age |
| `magento-e2e/checkout_url_check.php` | The fake helper follows the new `getCheckoutOrigin(?int $storeId = null)` signature |
| `harness.html` | Records the `additional_data` the renderer sends; checks it per scenario |

## Environment

- docker-magento in `~/Developer/Aplazo/magento248`, Magento 2.4.8, MSI, one website, one store.
- **Configured mount before the test:** `compose.dev.yaml` mapped the main checkout
  `~/Developer/Aplazo/php.aplazo-magento-2-payment-gateway` (sha256 `01e195cfc281d61c…`).
- **Effective mount during the test:** `phpfpm`, `phpfpm-xdebug` and `app` all had the worktree
  `~/Developer/Aplazo/magento-BNPL-1665` on `app/code/Aplazo/AplazoPayment` (`docker inspect`). `generated/` was cleared and `setup:di:compile` returned 0.
- Local stub module `LocalDev_AplazoStub`, with the kit's files above: `ApiService`, `TrackingService` and
  `LogService` resolved to `LocalDev\AplazoStub\...`. Aplazo hosts mapped to `127.0.0.1` in both PHP containers;
  `curl https://api.aplazo.net` failed from both.
- Popup mode only for `websites/1` (`checkout_mode=popup`, removed afterwards); `sanbox_mode=1` (default scope,
  unchanged). The stub's checkout URL pointed to `https://checkout.aplazo.net/mock`, served by `mock_https.py`
  on `127.0.0.1:8443` and reached only by the test Chrome through `--host-resolver-rules`.

## What ran, what was simulated, what was not tested

| Ran (real Magento and plugin code) | Simulated | Not tested |
|---|---|---|
| Checkout in Chrome (headless, trusted clicks), `set-payment-information`, `placeOrder`, MSI reservations, `Operations::purchase`, `operation=cancel` link, the REST callback `/rest/V1/aplazo/callback/` with JWT validation, `approveOrder`, invoicing, `bin/magento aplazo:orders:cancel` with the plugin's `shouldCancelOrder`, `OrderManagementInterface::cancel` (admin cancel), `ShipOrderInterface` (shipment) | Aplazo API (`createLoan`, `getLoanStatus`, `cancelLoan` answered by the stub); Aplazo checkout (local mock); the webhook sender (`simwebhook.php`: signed like Aplazo with the local `apiToken`, legacy key); the age of the orders for the cron (allowlist) | Without MSI (legacy CatalogInventory): **no compatibility is claimed**. Real Aplazo checkout and webhooks (STG). The cron triggered by Magento's scheduler (`cron:run`). Several stores with different popup configuration. Mobile browsers. Universal Checkout (BNPL-1666). Refunds and credit memos |

## Test data

- Products: `APLAZO-TEST-001` (existing), `APLAZO-1665-LAST-APPROVE` and `APLAZO-1665-LAST-CANCEL` (created for
  this test with physical quantity 1 on the `default` source).
- Orders: `000000046` to `000000061` (entity 76 to 91), all with `@example.invalid` emails. They are kept.
- `APLAZO-TEST-001` has historical reservations that add up to +6 (master's repeated compensation, orders before
  `000000046`), so its salable quantity is above its physical quantity. They were not touched. The tables below
  give the movements of each test order, not the product's absolute salable quantity.

## Results

Movements are the order's own rows in `inventory_reservation`, read from the database after each step.
"Mark" is `aplazo_checkout_mode` on the order payment.

### Popup window closed during `placeOrder` (before `purchase`)

The stub delays `createLoan` by 4 s; the test closes the window 1 s after the trusted click. In every case
the page showed "The Aplazo window closed before the checkout loaded. Your order was placed.", the mock had no
hit, and after placement the order was `new/pending`, marked `popup`, with only `−1 order_placed`.

| Scenario | Order | Next step | Final state | Movements | Net | Invoices | Aplazo stub calls |
|---|---|---|---|---|---|---|---|
| Cancel by link | 000000047 | `operation=cancel` with the order's token (HTTP 200, back to the cart) | canceled | `−1 order_placed`, `+1 order_canceled` | 0 | 0 | createLoan, cancelLoan |
| Cancel from admin | 000000046 | `OrderManagementInterface::cancel` | canceled | `−1 order_placed`, `+1 order_canceled` | 0 | 0 | createLoan, cancelLoan |
| Cron, loan not found | 000000048 | `aplazo:orders:cancel` | canceled | `−1 order_placed`, `+1 order_canceled` | 0 | 0 | createLoan, getLoanStatus, cancelLoan |
| Webhook `Activo` | 000000049 | REST callback (simulated sender) | processing | `−1 order_placed` | −1 | 1 | createLoan |
| Cron, loan `OUTSTANDING` | 000000050 | `aplazo:orders:cancel` | processing | `−1 order_placed` | −1 | 1 | createLoan, getLoanStatus |

**Comparison with the previous code: no control test in this validation.** None of these five cases was run on
the previous code (`d3d72ef`) here. On that code an order closed before `purchase` gets no `+1`, so:
- cancelled: Magento's `+1` and the plugin's `−1`, a net of −1. Observed on the BNPL-1607 discovery tree, not on
  `d3d72ef`; that observation is recorded in `VALIDATION-BNPL-1664.md` without an order or commit, so it is cited
  as such.
- paid: the plugin's `−1` on top of Magento's, −2 until the shipment. **A historical deduction from the code,
  never reproduced.**

On this branch, unmarked orders still get the plugin's movements (000000057: `−1 order_placed_aplazo` on
approval), which is the path the deduction relies on.

### Normal popup, `purchase` reached several times (000000051)

| Step | Movements |
|---|---|
| Popup opened `purchase` (mode=popup) | `−1 order_placed` |
| Popup reloaded | `−1 order_placed` |
| Popup URL requested again (mode=popup) | `−1 order_placed` |
| `purchase` without `mode=popup` (redirect URL; redirected to the order's checkout URL) | `−1 order_placed` |
| Webhook `Activo` | `−1 order_placed`; processing, 1 invoice |

### Last unit

| Order | Product | Step | Physical | Salable | Movements | State |
|---|---|---|---|---|---|---|
| 000000052 | `APLAZO-1665-LAST-APPROVE` | Before | 1 | 1 | none | — |
| | | Placed (popup) | 1 | 0 | `−1 order_placed` | new/pending |
| | | Webhook `Activo` | 1 | 0 | `−1 order_placed` | processing, 1 invoice |
| | | Shipment | **0** | 0 | `−1 order_placed`, `+1 shipment_created` | complete, 1 shipment |
| 000000053 | `APLAZO-1665-LAST-CANCEL` | Before | 1 | 1 | none | — |
| | | Placed (popup) | 1 | 0 | `−1 order_placed` | new/pending |
| | | Admin cancel | **1** | **1** | `−1 order_placed`, `+1 order_canceled` | canceled |

Both orders reached the expected state at every step. The plugin applied no movement to them, so it never ran
`MSIStock`'s `CheckItemsQuantity` for a `+1` (the last-unit concern from the discovery). Logs were not inspected
for these two orders.

### Configuration changed after the order was created (000000054)

The popup order was placed; then `checkout_mode` was set to `redirect` for the website. `purchase` without
`mode=popup` then applied nothing (only `−1 order_placed`), and the webhook approval with popup mode off
applied nothing either (processing, 1 invoice, only `−1 order_placed`). The mark on the order stayed `popup`.
After the test the website was put back in popup mode for the next scenarios.

### Mark written and removed on the same quote (000000055)

Chrome with pop-ups blocked, guest checkout, quote 75:

| Step | `aplazo_checkout_mode` on `quote_payment` |
|---|---|
| `set-payment-information` with `additional_data.aplazo_checkout_mode=popup` while popup mode was off | not written (HTTP 200) |
| Same request with popup mode on (stands in for an earlier popup attempt that failed before the order existed) | `popup` |
| Place Order on that quote; the browser blocked the window, so the renderer sent no mark and the page redirected | removed |

The order was created unmarked and kept the redirect behaviour: `−1 order_placed`, `+1 aplazo_item_reserved`.

### Popup blocked by the browser (000000056)

Redirect to the order's checkout URL. No mark; `−1 order_placed`, `+1 aplazo_item_reserved`, as on master.

### Redirect with popup mode off: regression (000000057)

| Step | Movements |
|---|---|
| Redirect `purchase` | `−1 order_placed`, `+1 aplazo_item_reserved` |
| `purchase` requested again, then back | `… +1 aplazo_item_reserved` (master's repeated compensation, unchanged; reported in `VALIDATION-BNPL-1664.md`) |
| Webhook `Activo` | `… −1 order_placed_aplazo`; processing, 1 invoice |

No mark. Same movements as master.

### Approval and cancellation of the same popup order

**Concurrency is not exhaustively validated.** Three races were run and the webhook won all three; the opposite
order was only tested in sequence.

**At the same time** (orders 000000058, 000000059, 000000060): the webhook and the admin cancel were started
together from two threads, three times. The webhook won every time: processing/processing, 1 invoice, only
`−1 order_placed`, one status-history entry ("La operación fue Aceptada"). The admin cancel returned `false`
(order no longer cancellable). No new `ERROR`/`CRITICAL`/exception lines in `exception.log` or `system.log`
(checked for 000000059 and 000000060). For 000000058 the test lost its record after the race: its final state,
read from the database, is the same, but the admin cancel's answer and the logs were not captured. The CLI cancel always started later than the web request, so
this run never produced a cancellation that wins over the webhook.

**One after the other** (000000061), the case the race did not produce: admin cancel first (canceled,
`−1 order_placed`, `+1 order_canceled`), then a late webhook `Activo`. The plugin answered
`"Orden no se puede hacer invoice 000000061"`. The simulator printed "rechazado" because its text check
looks for `OK`, but the signature was accepted: the message comes from `approveOrder`. The order ended
`closed/closed` with no invoice and **no stock movement**. `approveOrder` does not check that the order was
cancelled, and that is existing master behaviour; this change leaves it as it is. On master, an unmarked
order in this case would also get the plugin's `−1 order_placed_aplazo` on top of the cancellation.

### Isolation

- The cron ran only with the allowlist file present, listing just the order under test. The stub log shows
  `getLoanStatus` only for `000000048` and `000000050`.
- Historical reservations (orders before `000000046`) after the run: 120 rows, sum +6, the same as before.
- No historical order (entity ≤ 75) was updated during the run.

## Checks

| Check | Result |
|---|---|
| `php -l` on every changed or new PHP file | No syntax errors |
| `node --check` on `aplazo-gateway.js` (Node 24 in the PHP container; no Node on the host) | OK |
| `xmllint --noout` on `etc/events.xml` and the stub's `di.xml` | OK |
| `setup:di:compile` with the branch mounted | rc 0 |
| Browser harness `run.sh` (BNPL-1664 suite plus the mark checks) | 34 tests, 0 failures (COOP observation as before) |
| `session_orders_check.php` | 35 checks, 0 failed |
| `checkout_url_check.php` | 25 checks, 0 failed (after updating its fake helper) |
| E2E scenarios above | As described; one test run broke on decoding a status-history comment (fixed in `run.py`) and one Chrome start lost its DevTools connection before creating any order (rerun) |

## Reproducing the validation

From the root of this branch's checkout, with docker-magento in `$MAGENTO_DIR`, following the setup of
`VALIDATION-BNPL-1664.md` (stub module, network block, mount, `di:compile`, popup mode for `websites/1`, stub
checkout URL, `mock_https.py`). For these scenarios the stub module needs the kit's current `Service/ApiService.php`,
`Plugin/CronAllowlist.php` and `etc/di.xml` (`setup:di:compile` again after copying them).

```bash
# Test products with one unit (only APLAZO-1665-* SKUs are accepted)
KIT="$PWD/dev/popup-harness/magento-e2e"
(cd "$MAGENTO_DIR" \
 && for sku in APLAZO-1665-LAST-APPROVE APLAZO-1665-LAST-CANCEL; do bin/clinotty php -- product "$sku" 1 < "$KIT/order_ops.php"; done \
 && bin/clinotty bin/magento indexer:reindex inventory cataloginventory_stock catalog_product_price)

# Scenarios (each one creates its own orders; the webhook needs the magento-qa-env simwebhook.php, see SIMWEBHOOK)
MAGENTO_DIR="$MAGENTO_DIR" python3 -I dev/popup-harness/magento-e2e/run.py \
  c1665_early_close_cancel_link c1665_early_close_cancel_admin c1665_early_close_cron_cancel \
  c1665_early_close_webhook_approve c1665_early_close_cron_approve c1665_popup_purchase_variants \
  c1665_last_unit_approve c1665_last_unit_cancel c1665_config_change c1665_marker_cleanup \
  c1665_blocked_unmarked c1665_redirect_unmarked c1665_concurrency c1665_cancel_then_webhook

# Per order: mark, state, invoices, shipments, reservations (read only)
(cd "$MAGENTO_DIR" && bin/clinotty php -- APLAZO-TEST-001 <increment_id> < "$KIT/snapshot.php")

# Browser harness and PHP checks
MAGENTO_SRC="$MAGENTO_DIR/src" dev/popup-harness/run.sh
(cd "$MAGENTO_DIR" && bin/clinotty php < "$KIT/session_orders_check.php" \
 && bin/clinotty php < "$KIT/checkout_url_check.php")
```

Results go to `dev/popup-harness/.out/magento-e2e/results.jsonl` (ignored by Git).

## Limitations and open points

- **Not tested:** without MSI, real external traffic (Aplazo checkout and STG webhooks), the cron run by
  Magento's scheduler, several stores with different configuration, mobile browsers.
- **MSI only.** Without MSI the order gets no native reservation, and this change removes the plugin's
  movements only for marked orders. Not tested and not claimed.
- The webhooks and the cron answer were simulated; the real Aplazo checkout and real STG webhooks were not used.
- The concurrency test produced only "webhook first". Cancellation-first was tested in sequence, not in a real race.
- A late approval of a cancelled order (`closed/closed`) is existing behaviour of `approveOrder`, outside this ticket.
- A pending popup order holds stock until it is paid or cancelled. Only a cancellation releases it before a
  payment: the cancel link, an admin cancel or the cancel cron after `cancel_time`. Stores that turn the cancel cron
  off keep that stock reserved until someone cancels the order.
- Orders created before this change carry no mark and keep the previous behaviour, including popup orders placed
  with BNPL-1664.
- The multi-tab limitation described in `VALIDATION-BNPL-1664.md` is not addressed here. This change does not
  resolve it.
- Test orders `000000055` and `000000056` stay `new/pending` (unmarked, net 0). They are kept, like the others.

## Restore

- The website's `checkout_mode` row and the stub's URL, delay, loan-status and allowlist files were deleted, and
  the mock was stopped.
- The local stub module was restored from its backup (identical files; `ApiService.php` sha256
  `db0e5543f3930dfa…`) and `Plugin/CronAllowlist.php` was removed.
- The original checkout was mounted again with `local.sh mount`, and `phpfpm-xdebug` was recreated.
  `compose.dev.yaml` is identical to the backup. The effective mount in all three containers is
  `php.aplazo-magento-2-payment-gateway`.
- The network block was added again in both PHP containers. `generated/` was cleared and `setup:di:compile`
  returned 0. The stubs still resolve.
- Test orders and products are kept.
