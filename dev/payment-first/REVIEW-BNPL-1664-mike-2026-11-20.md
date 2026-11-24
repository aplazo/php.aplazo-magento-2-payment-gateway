# BNPL-1664 · Mike's review of 2026-11-20: status, deviations and evidence

Mike tested `b2f1ccb` on magento-test (3 orders, carts closed) and asked for nine changes before the PR: customer
experience (1–4), no cost for stores without popup (5–6), compatibility (7–8) and form (9). This file records what was
done, where the code deliberately differs from the request, and the local evidence.

- **Tested version:** see `VALIDATION-BNPL-1664-payment-first.md`, round 4 (`4.0.14`, after merging master 4.0.13).
- **Environment:** local docker-magento 2.4.8 with MSI, the Aplazo API replaced by `LocalDev_AplazoStub`, Aplazo's window
  served by `mock_https.py`. Nothing here proves the real Aplazo contracts. No STG purchase was made by these runs.
- **Tags:** **fact** (observed in a run of the tested version) and **inference** (from code, not run).

## Status of the nine points

| # | Request | Status | Where |
|---|---|---|---|
| 1 | Layer over the checkout while Aplazo's window is open; link to focus it; close button | Done | `view/frontend/web/js/model/popup-overlay.js`, renderer |
| 2 | "Confirming your payment…" loader | Done | renderer `showConfirming()` |
| 3 | Retries after the window is seen closed (every 3 s, ~15–30 s) | Done, with a stricter end (§1) | renderer `CLOSE_RETRIES` = 8 (~24 s) |
| 4 | Empty minicart after success | Done | `etc/frontend/sections.xml`, `customerData.invalidate()` |
| 5 | One configuration-only switch, checked first | Done, split in two (§2) | `Helper\Data::isPaymentFirstEnabled()` / `isPaymentFirstInUse()` |
| 6 | `KeepConvertedQuoteClosed`: switch → attempt lookup → `FOR UPDATE` only if an attempt **with order** | Done, **differs** (§3) | step (b) asks for **any** attempt |
| 7 | Drop `magento/module-sales-rule` and `laminas/laminas-uri` | Done | `composer.json`, `CheckoutUrlValidator` (`parse_url()`) |
| 8 | `setup:di:compile` and a redirect checkout without MSI | **Not approved**: fails, also on master (§4) | pre-existing `Model/Product/MSIStock.php` |
| 9 | Whitelist, merge master + version, PR closing #131 | Whitelist, merge and `4.0.14` done; PR not opened yet | `etc/db_schema_whitelist.json` |

## 1. Closing the window: what gives the checkout back

`aplazo/popup/complete` answers `completed` · `not_paid` · `pending` · `rejected` · `review` · `unknown_attempt`
(`Controller/Popup/Complete.php`).

**The only answer that gives the checkout back is `not_paid` at the end of the close window.**
- The server sends `not_paid` only when `LoanStatusChecker` got a non-empty list of loans for the attempt's `cartId`
  (the attempt's `loanId` when known) and none of them is `OUTSTANDING`. An empty list, `false` from the API, an
  exception, a context change or a busy lock is `pending`.
- After the window closes (seen closed, or closed with the layer's ×), the page asks 1 + 8 times, every 3 s. The checkout
  comes back only if the last answer is `not_paid`. The × uses the same window: the buyer may have just paid.

**Everything else keeps the checkout blocked:**

| Last answer | Page |
|---|---|
| `pending`, a network error, an empty or unknown answer, retries exhausted | layer stays: "We could not confirm your payment yet. If you already paid in Aplazo, do not pay again…" + **Check again** (a new close window) |
| `rejected`, `review`, `unknown_attempt` | layer stays with the server's message, no action |
| `completed` | success page (or the order number when the session holds another cart) |

**What `not_paid` does not prove.** It means "not paid now", not "can no longer be paid": how long an Aplazo checkout
stays payable is unknown (C3) and there is no "cancel only if unpaid" call (C5). So a loan that is still payable is
**not prevented** from being paid after the buyer pays another way. What exists instead (fact for the first three,
`pf_e2e.py`):
- the attempt stays `open` and under reconciliation; it is never closed by an unpaid or unknown answer;
- clicking Aplazo again with the same cart reuses the same attempt and loan (no second Aplazo loan);
- a late payment on a quote still active becomes its order (cron or webhook);
- a late payment on a quote already turned into another order ends `rejected / quote_not_active`, visible with
  `bin/magento aplazo:payment-first:attempts --attention`; the reversal is manual until C5 is confirmed.

Closing the gap needs Aplazo: either an expiry for unpaid checkouts (C3) or a cancel that is refused once paid (C5).
Then the page could cancel the loan before giving the checkout back.

**Recovery when the page stays uncertain:** "Check again"; reloading the page (the next Aplazo click reuses the same
loan); the order arrives by webhook or reconciliation if the loan was paid.

Evidence (fact): `js_cycle_test.py` scenarios 9, 14–17 and `ux_e2e.py closed_uncertain` (status API failing → layer with
"Check again" after 9 checks, page inert; then "not paid" → checkout back, no order, quote active).

## 2. Switch and existing attempts

Two questions, two methods (configuration only, no query):
- `isPaymentFirstEnabled($store)`: `checkout_mode = popup` and a known checkout origin. Decides **new flows**.
- `isPaymentFirstInUse($store)`: popup on, or the store ever started an attempt. Decides whether **existing attempts**
  are looked after. The mark is `payment/aplazo_gateway/payment_first_in_use = 1` (store scope), written by
  `Model/PaymentFirst/InUseFlag` before the store's first attempt row, never cleared by the module.

Why the mark is visible when it matters: it is committed before any attempt row, and a cached configuration that shows
popup off was loaded after popup was turned off, so after that commit too.

| Part | Popup on | Popup off, store never started an attempt | Popup off, store has attempts |
|---|---|---|---|
| Storefront start (`aplazo/popup/start`) | starts / reuses an attempt | `use_redirect`, no read | `use_redirect`, no new attempt (fact) |
| Storefront `complete` | finalizes the session's attempt | n/a (no attempt) | still finalizes an attempt the session owns (an open page keeps working) |
| Reconciliation cron | runs | returns, no query | runs (fact: `switch_off_cron`) |
| Webhook | routes attempts first | redirect only, no attempt lookup | routes attempts (fact: `switch_off_webhook`) |
| `bin/magento aplazo:payment-first:attempts` | not gated | not gated | not gated |
| `KeepConvertedQuoteClosed` (cart save) | guard runs | no query | guard runs (1 indexed read per active-cart save) |
| Order hooks (cancel, email, stock, cancel cron) | in-memory mark first | no query without the mark | no query without the mark |

Before this change the cron and the webhook stopped at "popup off", so an attempt paid after the switch was turned off
waited for someone to run the CLI. That is no longer the only way out.

**Cost (fact, `query_probe.php`, MySQL profiler, synthetic data):**

| Store state | cart save | cancel check/money order | cancel Aplazo redirect order |
|---|---|---|---|
| A · popup off, never used | 43 queries, none from the module | 21, none from the module | 24, none from the module |
| B · popup off, has attempts | 44: + `SELECT entity_id FROM aplazo_payment_attempt WHERE quote_id = ? LIMIT 1` | 21, none | 24, none |
| C · popup on (control) | 44, same read | 21, none | 24, none |

The only `FOR UPDATE` in the cancels is Magento's `OrderMutex` (`vendor/magento/module-sales/Model/OrderMutex.php`).

## 3. `KeepConvertedQuoteClosed`: why step (b) asks for any attempt

Mike asked for (a) switch off → return; (b) look the quote up in `aplazo_payment_attempt`; (c) `FOR UPDATE` only if an
attempt **with order** exists. The code keeps (a) (as `isPaymentFirstInUse`) and (c), and asks in (b) for **any**
attempt of the quote.

Why: the attempt's `order_id` is written in the conversion transaction. While the conversion is running, a plain read
cannot see it, so "attempt with order" answers no exactly when the guard is needed, and the stale save is let through
(H-1). Any attempt is committed at start, long before a conversion, so it is visible.

**Focused proof (fact, `pf_e2e.py h1_inflight_current` / `h1_inflight_order_only`):**
1. A `checkout/sidebar/updateItemQty` request passes Magento's own checks and stops inside its quote save, before the
   guard.
2. The webhook starts the conversion: it locks the quote rows and stops at `convert_before`, before submit.
3. The request resumes and runs the guard while the conversion is still open.

| Variant | Step (b) answered | Request | Quote after | Orders |
|---|---|---|---|---|
| Current (any attempt) | yes | refused, "No such entity with cartId" | inactive, qty 1 | 1 |
| Mike's (attempt with order, stub `H1VariantProbe`) | no | accepted | **active again, qty 3, no reserved order id** | 1 (already paid) |

For stores without attempts the cost is the same as Mike's version: no query (table in §2).

## 4. Without MSI

**Status: not approved.** The module does not compile without MSI, and neither does `master`. The cause is older than
this branch.

**What the earlier report said, corrected.** The 2026-11-24 report said that the branch compiled without MSI. What actually
ran (on the shared local store, not isolated):
- the working tree `b2f1ccb` + uncommitted changes (equivalent to `1cdb5e6`) **failed** in
  `Model/Product/MSIStock.php`;
- the same tree with `Model/Product/MSIStock.php` temporarily removed compiled. That tree matches no commit;
- `setup:upgrade` with the kit's stub enabled also failed while generating the `OrderService` interceptor:
  `Class "Magento\InventorySalesApi\Api\Data\SalesEventInterface" not found` (`OrderService.php:102`, default value
  `SalesEventInterface::EVENT_ORDER_PLACED`, in `master` since 2023). It only appears when some module adds a plugin
  to `OrderService`.

**Isolated run (fact, 2026-11-24).**
- Environment: a copy of the local 2.4.8 store in a separate Docker volume, in a container from the same image with
  no network (no database, Redis, web or Aplazo). MSI was removed with Composer (`replace` of the 76 inventory packages
  + `update --minimal-changes`, 0 `Magento_Inventory*` modules left). The module was taken from `git archive <commit>`
  without `dev/`.

| Commit | Version | `setup:di:compile` |
|---|---|---|
| `origin/master` `fe0573d` | 4.0.13 | **fails**: `Impossible to process constructor argument … GetSkusByProductIdsInterface … of Aplazo\AplazoPayment\Model\Product\MSIStock` |
| `64cbe2c` (this branch, final) | 4.0.14 | **fails**, same error |
| `b2f1ccb` (tested by Mike) | 4.0.12 | **fails**, same error |
| diagnostic: `master` without `MSIStock.php` | 4.0.13 | compiles |
| diagnostic: `64cbe2c` without `MSIStock.php` | 4.0.14 | compiles, no warnings (includes the `DeferredSalabilityPublisher` override) |

So the branch adds no compile failure of its own without MSI, but it cannot be called compatible while `MSIStock`
breaks compilation. A redirect checkout without MSI was **not** repeated on the final version: it needs a full store
without MSI (the earlier one, in developer mode, ran on the shared store before the merge).

**Finding to report (prepared, no ticket created yet):**
> **Magento plugin 4.0.13 does not compile on stores without MSI.** `Model/Product/MSIStock.php` (MG-763) requires
> `Magento\InventoryCatalogApi\Model\GetSkusByProductIdsInterface` and other MSI interfaces in its constructor.
> `bin/magento setup:di:compile` fails with "Impossible to process constructor argument … of
> Aplazo\AplazoPayment\Model\Product\MSIStock" on any Magento where the `Magento_Inventory*` modules were removed with
> Composer, so production mode cannot be deployed there. Reproduced on `master` `fe0573d` (4.0.13) in an isolated
> 2.4.8 container. Related: `Model/Service/OrderService::reservingStockUntilPayment()` uses
> `SalesEventInterface::EVENT_ORDER_PLACED` as a default value, which breaks interceptor generation without MSI if
> any module puts a plugin on `OrderService`. Suggested direction: resolve MSI classes lazily (only when
> `Magento_Inventory` is enabled), as `OrderService` already does at runtime, and use string constants for defaults.

## 5. Incident: 3 loans created in STG on 2026-11-24

See `INCIDENT-2026-11-24-stg-loans.md`.

## 6. How the kit now refuses to reach Aplazo

`dev/popup-harness/magento-e2e/kit_guard.php` checks, inside the target Magento:
- `LocalDev_AplazoStub` enabled;
- the plugin's `ApiService`, `LogService` and `TrackingService` resolve to the stub's classes;
- every store's base URL host is local (`magento.test`, `localhost`, `127.0.0.1`).

`pf_e2e.py`, `ux_e2e.py` and `qa_selection.py` run it before any scenario and stop when it fails. The PHP scripts that
can reach the API (`order_ops`, `webhook_sim`, `cron_job`, `store_ops`, `query_probe`, `sidebar_probe`) exit early too.
Fact: with the stub disabled the guard listed the four problems and `ux_e2e.py` stopped before any request (the
plugin's Aplazo log did not grow).
