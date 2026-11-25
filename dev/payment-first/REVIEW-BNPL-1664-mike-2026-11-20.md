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
| 3 | Retries after the window is seen closed (every 3 s, ~15–30 s) | Done, with a stricter end (§1). **Late payment of an earlier loan: release blocker, needs Aplazo + product (§1a, §8)** | renderer `CLOSE_RETRIES` = 8 (~24 s) |
| 4 | Empty minicart after success | Done | `etc/frontend/sections.xml`, `customerData.invalidate()` |
| 5 | One configuration-only switch, checked first | Done, split in two; **deviation: a store that used popup keeps 1 indexed read per cart save after turning it off (§2)** | `Helper\Data::isPaymentFirstEnabled()` / `isPaymentFirstInUse()` |
| 6 | `KeepConvertedQuoteClosed`: switch → attempt lookup → `FOR UPDATE` only if an attempt **with order** | Done, **differs** (§3) | step (b) asks for **any** attempt |
| 7 | Drop `magento/module-sales-rule` and `laminas/laminas-uri` | Done | `composer.json`, `CheckoutUrlValidator` (`parse_url()`) |
| 8 | `setup:di:compile` and a redirect checkout without MSI | **Not approved**: fails, also on master (§4) | pre-existing `Model/Product/MSIStock.php` |
| 9 | Whitelist, merge master + version, PR closing #131 | Whitelist, merge and `4.0.14` done; PR #133 open as **draft** (#131 not closed yet) | `etc/db_schema_whitelist.json` |

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

**Recovery when the page stays uncertain:** "Check again"; reloading the page (the next Aplazo click reuses the same
loan); the order arrives by webhook or reconciliation if the loan was paid.

### 1a. Close without payment: open decision (not resolved)

**The exact path:** `not_paid` → checkout enabled → second payment → possible late payment of the first loan.

1. The window closes. During ~25 s the page asks `complete` 9 times; the last answer is `not_paid` (Aplazo listed
   the loan, not `OUTSTANDING`).
2. The checkout is given back with the cart intact. The attempt stays `open`, with its loan and checkout URL; the
   reconciliation keeps checking it (`ReconcileSchedule`: 60 s … 3600 s, then every 10800 s, with no end).
3. The buyer pays again. Three cases:
   - **Aplazo again, same cart:** `StartService` reuses the same `open` attempt (same fingerprint) → same loan and URL.
     No second Aplazo loan. (fact: `close_unpaid_and_reuse`, `uncertain_start_normal_and_reuse`)
   - **Aplazo again, cart changed:** the old attempt goes `superseded` (never cancelled, comment in
     `StartService`: "Their loans may still be paid"), a new loan is created. Two payable loans exist.
   - **Another payment method (or redirect), from the same page, after a reload, from another tab or the cart page:**
     since the review of 2026-11-24 `SettlePaidAttemptsBeforePlaceOrder` first settles every payable attempt of the
     quote with Aplazo. If one is already paid (and the cart unchanged), it becomes the Aplazo order and the other
     placeOrder is refused with that order number: nothing is charged twice. If a conversion of the quote is running,
     the other order is refused as busy. Otherwise (not paid, or status unknown) Magento places that order, the quote
     becomes inactive and the first loan is still payable. (fact: `place_other_paid`, `place_other_busy`,
     `place_other_busy_checkout`, `place_other_unpaid`, `place_other_unknown`)
4. If the first loan is paid **after** that other order (the buyer reopens its URL, or Aplazo records a payment made
   before the close later than the moment of the other placeOrder), the webhook or the cron confirms `PAID` and the finalizer cannot convert the quote:
   attempt `rejected / quote_not_active` or `cart_changed`, `loan_cancel_state = pending`, listed by
   `bin/magento aplazo:payment-first:attempts --attention`. The buyer has paid twice. The reversal is manual:
   `Reconciler::$autoCancelRejected = false` because what `cancelLoan` does after a payment is not known (C5).
   (fact against the stub: `two_attempts_both_paid_*`, `popup_pending_redirect_other_tab`, `cart_changed_*`)

**Does the first loan stop being payable? Not as far as the code and the known contracts show.**
- The payment-first code never cancels an unpaid loan: `cancelLoan` is only called for a `rejected` attempt (already
  paid) and only when `autoCancelRejected` is on (off by default).
- The endpoint exists and master already calls it on unpaid loans: the redirect flow's `Cron/CancelOrders` asks
  `shouldCancelOrder` (status not `OUTSTANDING`) and then `OrderService::cancelOrder` calls
  `cancelLoan(['cartId', 'totalAmount' => 0, 'reason' => 'No payment'])` on `/api/pos/loan/cancel`. What is not known is
  whether that makes the loan unpayable, and what it does if the loan is paid between the status check and the cancel
  (the same race exists in master's cron). That is the narrower question for Aplazo behind D.
- The `createLoan` payload (`QuoteLoanPayloadBuilder`) sends no expiry; nothing in the plugin assumes one.
- Closing the window, the 25 s wait and placing another order do not reach Aplazo at all.
- How long an Aplazo checkout stays payable is unknown (contract C3), and there is no "cancel only if unpaid" call
  (C5). Whether the buyer can reach the first checkout again after closing the window (browser history, a link from
  Aplazo) is not known either.

**Alternatives for Mike (none implemented beyond A):**

| | Option | Double-payment risk | Buyer impact | Needs |
|---|---|---|---|---|
| A | **Current**: give the checkout back on the final `not_paid`; any later placeOrder first settles the earlier attempts (paid → that order, other order refused) | open only for a payment recorded **after** the other order | cart intact after ~25 s (epic BNPL-1022/1028) | — |
| B | After `not_paid`, keep the layer and ask the buyer to choose: "Volver a Aplazo" (same loan) or "Pagar de otra forma" with a warning | lower (explicit choice), still open | one more click | frontend only |
| C | Longer close window (e.g. 60–120 s) | lower for late recording only | longer wait after every close | frontend only |
| D | Cancel the loan (existing `cancelLoan`, as master's redirect cron does for unpaid loans) before the checkout is given back or before another order is placed, only if Aplazo guarantees it makes an unpaid loan unpayable and is refused once paid | closed, if the contract holds | none (a new start then needs a new loan) | Aplazo confirmation of C5 (+ C3); product decision |
| E | Turn on the automatic reversal of `rejected` attempts | the second charge is refunded, not prevented | refund delay | C5 (what cancel does after payment) |

**None of A, B or C resolves the risk.** Asking the buyer (B) or waiting longer (C) only makes an accidental second
payment less likely; the first loan stays payable either way. The placeOrder check of A closes the case "already paid
before the other order", not "paid after it". Only D prevents the double charge, and it depends on Aplazo; E refunds it
afterwards. **This is a release blocker, not an accepted risk:** it is not accepted because Mike is away. Until Aplazo
confirms C5/C3 and the owner of the product decision chooses D, E or an explicit acceptance for a limited pilot, the PR
stays draft; meanwhile an operator would watch `--attention` for `rejected` attempts.

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
| `SettlePaidAttemptsBeforePlaceOrder` (any placeOrder, since 2026-11-24) | 1 indexed read per placeOrder; status request per payable attempt of that quote | no query (no store of the site ever used popup) | same as popup on, for the whole site (gated by "any store in use") |
| Order hooks (cancel, email, stock, cancel cron) | in-memory mark first | no query without the mark | no query without the mark |

Before this change the cron and the webhook stopped at "popup off", so an attempt paid after the switch was turned off
waited for someone to run the CLI. That is no longer the only way out.

**How `payment_first_in_use` works:**
- **Write:** `Model/PaymentFirst/InUseFlag::ensure($storeId)`, called by `StartService` right before the attempt row is
  inserted. Path `payment/aplazo_gateway/payment_first_in_use`, value `1`, scope `stores`, scope id = the quote's
  store, through Magento's `WriterInterface` (core_config_data). If the cached configuration already has it, nothing is
  read; otherwise one indexed read of `core_config_data`, and a write only if the row is missing. A failed write throws,
  and the attempt is not created. Only the popup start path runs this.
- **Read:** `Helper\Data::isPaymentFirstInUse($storeId)` through `ScopeConfig` (store scope, cached, no query);
  `isPaymentFirstInUseInAnyStore()` loops over the stores for the cron and the webhook.
- **Scope:** per store view. A store that never started an attempt stays at zero queries even if another store of the
  same installation uses popup. It is not in `system.xml`: `bin/magento config:set` refuses the path; it can only be
  set by the module or by SQL.
- **Never cleared by the module.** Clearing it (SQL) is only safe when the store has no attempt left in a reconciled
  state (`starting`, `open`, `uncertain`, `superseded`, `order_created`, `rejected` with an open reversal).

**Attempts created before 4.0.14 (no flag).** Builds up to `b2f1ccb` did not write the flag. A store upgraded to
4.0.14 with older attempts has no flag until its first new popup start. While popup stays on nothing changes (the
switch covers it). If popup is turned off **before** any new start, the cron, the webhook routing and the H-1 guard
ignore those older attempts, as in `b2f1ccb`; only the CLI reaches them. Affected installations: the local store (fact:
attempts since 2026-10-28, flag written today) and magento-test (attempts from Mike's tests on `b2f1ccb`; not checked).
Production has none (popup is sandbox-only). Resolved by the data patch `Setup/Patch/Data/MarkPaymentFirstInUseStores` (`e3de859`): on `setup:upgrade` it writes
the flag for every existing store with an attempt in a reconciled state; idempotent (validated, VALIDATION round 4 addendum).

**Deviation from the original requirement.** Mike asked that, with popup off, nothing new costs anything. That holds
for stores that never used popup (0 module queries, table above). A store that used popup and turned it off keeps
**one indexed read of `aplazo_payment_attempt` per active-cart save** (43 → 44 queries in `query_probe`), plus the
cron's and the webhook's attempt lookups. This is deliberate, so that turning popup off does not abandon a paid
attempt; it needs Mike's agreement.

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

**Finding to report (prepared, no ticket created yet; full report with reproduction in `DEFECT-no-msi-compile-4.0.13.md`):**
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

## 7. Observational cases of the full run (50): what they show, what is still unverified

These cases record evidence and have no pass/fail criterion in the kit (`record(..., None)`, stored as `ok=false`).
Below is what their records show on `cd64d09` (kit `b9f9410`). **They are not approvals.** Where the local setup
cannot show something, it is listed as unverified.

| Group (cases) | Observed | Not verifiable here / still to check |
|---|---|---|
| **Inventory** `msi_fix_*` (8), `msi_*` (5), `msi_backlog_drain` (1) | Successful conversion: one `-1 order_placed` reservation, salable qty of the last unit goes to 0, the salability message is published **after** the commit (probe: transaction level 0). Abort, rollback and synthetic definitive failure: no reservation, unit still salable, attempt `needs_review`, no order. Crash then recovery, and repeated finalization: one reservation only. Publish failure after the commit (`publish_exception`/`_error`): the order completes, the probe records the error, nothing is queued for that order. Redirect: message published at once, as in Magento | In the full run the kit did not run Magento's consumer (it only consumes when it can count the queue, and `RABBITMQ_CONTAINER` was unset), so the index flag never moved there. Measured afterwards with the consumer: §7b |
| **Coupons** `coupon_fix_*` (10), `coupon_counters_*` (5), `coupon_success`, `coupon_link_fail` | Coupon `times_used` +1 exactly once per order: on success, on crash then recovery, on submit failure then recovery, and not again on a repeated finalization. Abort or definitive link failure: no change. Registered customer: per-customer counters +1 once. Limits: a second use by the same customer and a third guest use are refused. Bulk message scheduled after the commit (`coupon_bulk_publish` with no callbacks left) | In the full run the consumer did not run (same reason), so the rule-level counter never moved there. Measured afterwards with the consumer: §7b |
| **Email** `email_webhook`, `email_fix_*` (3), `email_reconcile_*` (2) | One `OrderSender::send` per order, no exception, for webhook, CLI reconciliation and the cron class; repeated finalization sends nothing more; one `order_paid` tracking event. Synchronous sending: `email_sent=1`. Asynchronous: `send_email=1`, `email_sent` empty (left for Magento's `sales_send_order_emails` cron) | No mail transport is configured, so delivery is not shown. The asynchronous cron was not run |
| **Uncertain start** `uncertain_start_*` (4) | `createLoan` failure or a process dying during it: one attempt `uncertain`/`starting`, **one** `createLoan` call in total across re-clicks, a cart change and a reconciliation; no order, no reservation. Normal start: the same click reuses the open attempt (one `createLoan`) | While such an attempt is unresolved, that cart cannot use Aplazo again; the reconciliation keeps it as it is. How it ends depends on C2 (lookup after a lost `createLoan`) |
| **Order link** `link_conflict_concurrent_complete`, `link_conflict_then_reconcile`, `link_conflict_then_cart_write`, `link_definitive_failure_synthetic` | A buyer's `complete` during the conversion: one order, one invoice, one reservation, attempt `completed`; a later cart write is refused ("The quote item isn't found"), the quote stays inactive. Synthetic definitive failure: no order, no reservation, quote active, a later cart write is accepted (qty 3), attempt `needs_review` | The synthetic case leaves a paid loan without an order for an operator (by design); its follow-up is manual |
| **Approval** `approval_*` (4), `crash_inside_tx_after_writes` | `processing` with the configured status (default or custom), one invoice, the email requested once, one `order_paid` event; a repeated finalization changes nothing. Crash after the invoice and an operator invoice: the repeat completes without a second invoice. Crash inside the transaction after writes: no order, quote active, attempt `open`; the reconciliation completes with one order and one reservation | Delivery of the email (as above) |

### 7b. Publish failures after the commit (inventory salability, coupon usage)

Payment-first converts the quote inside its own transaction. Two Magento messages that the core publishes **inside**
`submit` are deferred by the module until that transaction commits (`DeferredSalabilityPublisher`,
`Plugin/PaymentFirst/DeferCouponUsagePublish`), so a rolled-back conversion never leaves a message behind. The cost is
what happens when that late publish fails.

**Reproduction (fact, `cd64d09`, 2026-11-24, kit stub, RabbitMQ queue measured, Magento's own consumer):**
`pf_e2e.py mf_success_then_consume mf_publish_exception mf_publish_error mf_redirect cf_guest_success
cf_guest_publish_exception cf_guest_publish_error cf_redirect` with `RABBITMQ_CONTAINER` set. The failures are
injected by the stub (`SalabilityPublishProbe`, `BulkPublishProbe`: one `RuntimeException` or `\Error` in the
publisher). Queues were drained before the run.

| | Normal (payment-first) | Publish failure (payment-first) | Redirect / Magento core |
|---|---|---|---|
| **Inventory** order, reservation | order completed, `-1 order_placed` | same: order completed, `-1 order_placed`, salable qty 0 | order placed, reservation in the same transaction |
| salability message | published after the commit (1 queued) | **not queued**; `error` trace "stock salability message not published" | published inside `submit` |
| stock index `is_salable` after the consumer | `0` | **stays `1`**; a full `indexer:reindex inventory` does not change it | `0` |
| **Coupon** `times_used` (coupon) and per-customer usage | +1 (synchronous) | +1 (synchronous) | +1 (synchronous) |
| rule-level usage message (`sales.rule.update.coupon.usage`) | 1 bulk queued after the commit; consumer: rule `times_used` +1 | **no bulk**; `error` trace "coupon usage message not published"; rule `times_used` not incremented | queued inside `submit`; consumer +1 |
| If the core publish fails in the core flow | — | — | the exception aborts `submit`: no order |

**Impact:**
- **Inventory:** the order and its reservation are correct, and checkout keeps refusing the unit (salable qty is computed
  from reservations: fact, salable = false). What is wrong is the stored index flag: the SKU can keep showing as in stock
  in listings until another salability message for that SKU is consumed (fact: the next shipment of that SKU published
  one and the consumer set `is_salable = 0`). A full reindex does not repair it (fact).
- **Coupon:** coupon limits and per-customer limits are synchronous and stay correct (fact). Only the rule-level usage
  counter (`salesrule.times_used`, admin statistic; no limit in Magento 2.4.8 reads it, checked in
  `Magento\SalesRule\Model\Utility`) stays one short per failed order.
- **Compared with the core flow:** in redirect a publish failure fails the checkout (no order, buyer sees an error).
  Payment-first cannot do that, because the loan is already paid; it keeps the order and loses the message.

**Existing recovery:** none automatic. The failure is traced at `error` level through `LogService`, which in
production posts to Aplazo's log endpoint (`/api/v1/ps/logs`, Datadog), fire-and-forget; the merchant's store keeps no
local record. The inventory flag heals on the next salability message for the SKU (a later order, shipment, credit memo
or source update); the coupon statistic needs a manual `UPDATE salesrule SET times_used = times_used + 1` per order.

**Open:** whether to retry the publish (for example from the reconciliation cron) or to queue a repair message for the
SKU. Not implemented; the frequency in production is unknown (a broker outage at that exact moment).

**Counts.** The full run executed the 106 entries of `TESTS` and wrote **107** records: `link_conflict` writes two
(`link_conflict_concurrent_complete` and `link_conflict_then_reconcile`); no name repeats.
- 52 records with an assertion passed;
- 4 records with an assertion failed (`status_unknown_then_paid`, `create_loan_fail_then_early_webhook`,
  `h3_registered_cron_class_crash_then_complete`, `shipment_and_physical_stock`);
- 1 entry was interrupted before its assertion (`mutation_during_convert`, recorded under that name with the error);
- 50 records are observational.

**Reruns after the full run (separate, 6 records, kit `64cbe2c`, same production code):**
`cart_mutation_waits_for_conversion` pass, `status_unknown_then_paid` pass, `create_loan_fail_then_early_webhook`
pass, `h3_registered_cron_class_crash_then_complete` pass, `shipment_and_physical_stock` fail (indexers in realtime
without a prior reindex: salable −2, the index caught up with an earlier shipment) then pass (after a reindex).

## 8. Technical review of 2026-11-24 (in Mike's absence)

Mike is on vacation; the squad continued the review of PR #133 (draft). Read the whole production diff against
`master`, plus `work-review` and one independent read of the same diff. Findings, by severity, with what was done.
**fact** = reproduced on the local store with the kit; **inference** = from code. Commits: see the PR.

| # | Severity | Finding | Walk-through | Done |
|---|---|---|---|---|
| R-1 | **High** (money) | A loan **already paid** in Aplazo was not checked before an order with another method | Popup open, buyer pays in Aplazo; the page does not get the confirmation (window closed early, reload, network). Buyer reloads (the layer is client-side only), picks check/money order or redirect, Place Order. Magento placed that order; the quote went inactive; the webhook or cron then found the paid loan → `rejected/quote_not_active`: charged twice, manual refund. Worse: `savePaymentInformation` writes the new method on the quote before `placeOrder`, and `Finalizer::rejectReason` refused any quote whose method was not `aplazo_gateway` (`quote_mismatch`), so even a quick conversion lost | **Fixed.** `Plugin/PaymentFirst/SettlePaidAttemptsBeforePlaceOrder` (before `CartManagementInterface::placeOrder`, outside the core cart lock) runs the shared finalization on every payable attempt of the quote: paid → it becomes the Aplazo order and the other placeOrder is refused with that number; busy → refused; not paid / unknown → placeOrder goes on (unknown cannot block: an absent loan also reads as unknown). `rejectReason` no longer refuses a quote for its selected method (not part of the fingerprint); the method is set back to Aplazo and the other method's data dropped. fact: `place_other_paid`, `_unpaid`, `_unknown`, `_busy`, `_busy_checkout` |
| R-2 | **High** (compatibility) | The module required `Magento\Quote\Model\CartMutexInterface`, which exists **only from Magento 2.4.8** (checked on magento/magento2 tags: absent in 2.4.4–2.4.7-p5, present in 2.4.8). `Finalizer`/`StartService` are reached from `Notifications` (the webhook of every order, redirect included) | On 2.4.7 or earlier, popup off: `setup:di:compile` fails, and in developer mode every `/V1/aplazo/callback` fatals building the router → redirect orders never approved by webhook. A regression against 4.0.13 for every merchant below 2.4.8 | **Fixed.** `Model/PaymentFirst/CartLock`: the same lock as core `CartMutex` (`cart_lock_<quoteId>`, no wait) through `LockManagerInterface`, so on 2.4.8 it still excludes core placeOrder; no type that is missing before 2.4.8. All other Magento classes the diff uses exist in 2.4.4 and 2.4.7 (tags checked; MSI classes in magento/inventory 1.2.4/1.2.7, same constructor). inference: **not compiled on a real 2.4.7 store** (pending). Before 2.4.8 core placeOrder takes no cart lock: popup there is not recommended (the pilot is sandbox-only) |
| R-3 | Medium | "Not paid" could be concluded from an answer about **another loan** | The status API lists loans by cartId; if all of them carried another `loanId` than the attempt's, `LoanStatusChecker` returned `NOT_PAID` → the page says "you closed the window without paying" and gives the checkout back without evidence | **Fixed.** No matching loan → `UNKNOWN`. Unit test `LoanStatusCheckerTest` (9 cases; "only another loan id" returned NOT_PAID before) |
| R-4 | Medium | Failed deferred salability publish left the stock index wrong (§7b) | Publish fails after the commit → nothing queued → `is_salable` stays 1 until another message for the SKU | **Fixed.** On failure, `DeferredSalabilityPublisher` runs MSI's own handler of the topic in process (`UpdateIndexSalabilityStatus::execute`, what the consumer runs). It recalculates from committed quantities and reservations and counts nothing, so a duplicate (exception after delivery) or a repeat is harmless. Resolved only on that path and only if the MSI class exists (repo convention for optional modules: `class_exists` + ObjectManager), so no MSI type enters the constructor. fact: `msi_fix_publish_exception`, `msi_fix_publish_error` (asserted) |
| R-5 | Low | Failed deferred coupon usage publish: rule-level `times_used` one short (§7b) | as §7b | **Not changed on purpose.** The consumer **increments** a counter: a retry after an exception that may follow delivery could count twice. No limit reads that counter (admin statistic). Not a release blocker; manual fix per order documented in §7b |
| R-6 | Medium (availability) | An attempt `uncertain`/`starting` never leaves that state | `createLoan` fails (5xx, timeout, invalid URL) → `uncertain`; the status API cannot tell "no loan" from a failure (`getLoanStatus` → `false` for both) → every later popup start for that quote answers "uncertain" forever, polled every 3 h | **Pending.** No money risk: other methods stay available (R-1 lets placeOrder through on unknown). Resolving needs either an API answer that distinguishes "no loan for this cartId" (C2) or an operator resolution "no loan confirmed with Aplazo" in `aplazo:payment-first:attempts`. Not a blocker for the sandbox pilot; blocker for production enablement |
| R-7 | Medium (operations) | Reconciliation backlog grows without bound | Unpaid `open`/`superseded` attempts are re-polled every 3 h forever (C3); 50 per run, every 5 min, in the `default` cron group; 90 s API timeout | **Pending.** Proposal: due order by `paid_confirmed_at` first, lower cadence for old unpaid attempts, own cron group. Needs C3 for an end. Blocker for production enablement, not for the sandbox pilot |
| R-8 | Low | Order email lost if the process dies between `completed` and the email claim | Every later finalization returns through `settled()` and never claims the email | **Pending** (documented with the existing "exactly once not guaranteed" note in the design §8) |
| R-9 | Info | Semgrep: 13 findings, all in `dev/popup-harness/` (unlink of fixed switch files under `var/`, one `eval` in a one-off comparison script, one `urlopen` on fixed local URLs) | `dev/` is `export-ignore` (`.gitattributes`): never in the Composer package | Marked `nosemgrep` with the reason on each line; no production finding |

**What the placeOrder check guarantees, and what it does not (production code `ef63164`):**

| Buyer path after the window closes | Guaranteed | Not guaranteed | Evidence |
|---|---|---|---|
| Reload, then pay with another method or redirect (the client layer is gone) | if the earlier loan is **already paid** and the cart unchanged: that loan becomes the Aplazo order; the other order is refused with its number; nothing charged with the other method | a loan paid **after** this placeOrder | `place_other_paid` (fact) |
| Same, while the webhook/cron is converting that paid loan | the other order is refused (busy, the quote guard or the core cart lock, whichever meets it first); one Aplazo order | — | `place_other_busy`, `place_other_busy_checkout` (fact) |
| Same, loan not paid yet | the other order is placed (no lock-out) | the earlier loan stays payable; paid later → `rejected/quote_not_active`, charged twice, manual refund | `place_other_unpaid` (fact, ends `rejected`) |
| Same, status API fails or the loan was never created | the other order is placed (no lock-out) | a paid loan whose status could not be read at that moment is found later → `rejected` | `place_other_unknown` (fact) |
| Change the cart, then Aplazo again | the old attempt is superseded, a new loan is created; a paid old loan is never converted onto changed contents | both loans payable; the old one paid → `rejected/cart_changed` | `two_attempts_both_paid_*` (fact, round 5) |
| Change the cart, then another method | the check runs on the superseded attempt too; a paid loan for the old contents is recorded `rejected/cart_changed` at that moment (not silent) and the other order goes on, because refusing it would not avoid the refund | the refund of that loan (manual) | inference from `Finalizer::rejectReason` + the plugin (not run) |
| Same cart, Aplazo again | the same open attempt and loan are reused | — | `close_unpaid_and_reuse` (fact) |
| Flows that call `submit` directly (PayPal Express) | — | not checked | not run |

So the double-payment risk is **reduced, not resolved**: it remains for any loan paid after the other order, and only
Aplazo's cancellation/expiry semantics (C5/C3) plus a product decision can close it (§1a).

**Mike's nine points after this review:** unchanged except 3 (the client end is the same; the server now refuses another
order while a paid loan exists, and the late payment after another order is a release blocker, §1a) and 9 (PR #133
draft).

**Release blockers (the PR stays draft while any is open):**
1. Late payment of an earlier loan after another order (§1a): needs Aplazo to confirm what `cancelLoan` does on an
   unpaid loan and on a paid one (C5) and checkout validity (C3), then a product decision (D, E or an explicit,
   time-boxed acceptance for the sandbox pilot). Owner: product + Aplazo backend, not this PR.
2. Without MSI (§4): pre-existing in master; separate ticket (`DEFECT-no-msi-compile-4.0.13.md`).
3. Manual STG purchases and the magento-test run of this head.
4. A compile of this head on a Magento 2.4.7 store (R-2 is fixed by code; not run).

**Decisions that need another owner (not blockers of the code):** R-6 and R-7 before any production enablement; the
interim close behaviour (§1a A vs B); the extra indexed read per cart save in stores that used popup (§2, a documented
deviation from "zero queries with popup off", not compliance); the H-1 deviation (§3).
