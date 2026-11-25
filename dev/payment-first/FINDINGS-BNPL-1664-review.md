# BNPL-1664 · Review findings H-1..H-13: reproduction and resolution

Local, against the mock, 2026-10-28.
- **Code:** `BNPL-1664-payment-first`, local commits only; see `VALIDATION-BNPL-1664-payment-first.md` for the SHA that was tested.
- **Evidence:** the kit cases named here (`dev/popup-harness/magento-e2e/pf_e2e.py`).
- **Tags:** **fact** (observed in a run) and **inference** (from code, not run).

## H-1 · A stale cart write reactivates the converted quote (high)

**Reproduction before the fix (fact, twice, `h1_update_qty_save`):**
1. A `checkout/sidebar/updateItemQty` request loaded the quote (active, qty 3 in memory) and was held before saving.
2. The webhook converted the quote: order `…167`, qty 1, quote inactive.
3. The held request then saved:
   - the quote became active again, with qty 3 and no reserved order id;
   - a new start on that same quote created and paid a **second order** (`…168`, qty 3);
   - reservations −1 and −3.

**Other routes held right after their load** (`shipping-information`, `set-payment-information`, `updateItemQty`, add-to-cart) re-read the quote or item after the commit and failed visibly. Fact: 404 "No such entity with cartId", "The quote item isn't found", or nothing added. Those routes are exposed only between their last read and their save.

**Fix:**
- What: `Observer/PaymentFirst/KeepConvertedQuoteClosed` on `sales_quote_save_before`.
- Why there: Magento dispatches that event inside the quote save's own transaction, for repository and model saves.
- What it checks: for an active in-memory quote only, it reads the quote row `FOR UPDATE` (which waits for a conversion in progress). If the committed row is inactive and an attempt of this quote has an order, it throws `NoSuchEntityException` (the core's answer for an inactive cart), and the save rolls back.

**Scope and regressions:**
- One locked primary-key read on quote saves that already update that row.
- Inactive quotes of any other origin are not touched.
- Magento's `restoreQuote()` is refused only for quotes whose payment-first order exists.
- `vendor` is not modified.

**After the fix (fact):**

| Case | Result |
|---|---|
| `h1_update_qty_cart` (held after its last read) | refused ("No such entity with cartId = 177"); the quote stays inactive with one order |
| `h1_update_qty_save` and `h1_add_to_cart_cart` (held inside the save) | the write is serialized before the conversion, the conversion rejects the changed cart (`cart_changed`); one order per quote |

**Integrity:**
- No route ended with an active converted quote, or with two orders for one quote.
- A refused change is lost visibly, with the core error message; it is not merged.

**Not tested:** removing items, coupons, GraphQL, third-party direct writes to `quote_item` without saving the quote. For those, an inactive quote keeps any late item row (inference).

## H-2 · Synchronous log posts inside critical sections (medium → fixed)

**Fact:** with the log service slowed to 5 s, two posts ran inside the approval: `OrderService::invoiceOrder` logs "Creating invoice" and "Invoice created" with the attempt lock and the order row held.

**Fix:**
- `Model/PaymentFirst/Trace` writes the module's local log file at once and posts to Aplazo only after the locks are released.
- The finalizer creates the invoice with Magento's invoice service itself (same steps, no posts). The shared `invoiceOrder` used by redirect is unchanged.
- `trackOrderPaid` is sent after the locks.

**After (fact, `h2_slow_log_outside_locks`):** the attempt completed 11.2 s into the request, and the request returned at 31.3 s with all six delayed posts after the order. The posts before the finalizer are the existing `Notifications` logs, outside locks.

**Limitation:** the browser's `complete` and the webhook still send their posts before answering, so a slow log service delays those responses (not the locks).

## H-3 · A paid attempt without order can stay silent (medium → fixed)

**Detection:**
- `paid_confirmed_at` is written the first time the status API reports the loan paid.
- `bin/magento aplazo:payment-first:attempts --attention [--older-than=600]` lists, and exits with code 1 when any exist:
  - `paid_without_order`;
  - `rejected_unresolved`;
  - `needs_review_unresolved`;
  - `context_changed`;
  - `order_canceled`;
  - `cron_stale` (no successful `aplazo_payment_first_reconcile` in `cron_schedule` for 15 min while attempts wait).

**Fact (`h3_registered_cron_class_crash_then_complete`):**
1. A registered customer paid and the browser was gone.
2. The webhook answered `scheduled`.
3. The cron **class** was invoked directly in the `crontab` area, allowlisted to that attempt, and crashed after saving the order: one order, 0 invoices, attempt `open`, payment recorded.
4. `--attention` listed it under `paid_without_order` and flagged `cron_stale`.
5. The next invocation completed it: customer 15, not a guest, 1 invoice, `-1 order_placed`.

**Not validated:** the scheduler (`cron:run`). It was not run, because it would also run every other job on the store's historical orders. `cron_stale` reports exactly that local situation.

## H-4 · Status and cancel calls follow later configuration changes (medium → fixed)

**Fix:**
- The attempt keeps `environment` (sandbox flag) and `merchant_id` from the start.
- `Model/PaymentFirst/AttemptContext` compares them with the store's current configuration before any status or cancel call. On a mismatch, Aplazo is not asked, and the attempt records `context_changed:<what>`, visible in `--attention`.
- Credentials (merchant id, encrypted api token, website scope) are read from configuration at each call, as for every Aplazo call; they are never copied to the attempt.

**Fact (`h4_sandbox_changed_after_start`, `h4_merchant_changed_after_start`):**
- 0 status calls after the change, no order, `last_error` naming the change.
- After restoring the configuration, the reconciliation completed the order.

## H-5 · Reversal recovery after a crash or timeout (medium → fixed)

**Change:**
- A reversal request is claimed (`requested`) with a deadline before the call.
- One still `requested` after the deadline (a process that died) is closed as `unknown` and **never sent again**.
- A failed request is `unknown` too and not retried.
- An operator records what Aplazo (or the merchant) confirmed outside the module:

  ```
  bin/magento aplazo:payment-first:attempts --resolve=<id> --outcome=reversal_confirmed|fulfilled_manually --reference=<case or order> [--note=...] --confirm
  ```

  - Only for `rejected` or `needs_review` attempts, once.
  - Without `--confirm` it only shows what it would write.
  - `fulfilled_manually` requires an existing order number.
  - It records who (OS user), when, the reference and the note.
  - It moves no money and changes no loan or order.

**Fact (`h5_reversal_interrupted_then_operator_resolution`, `h5_cancel_request_fails_not_retried`):**
- Crash after the claim → `requested` → after the deadline `unknown` (`cancel_request_interrupted`); 0 cancel calls.
- A failing request: 1 call, `unknown`, no retry.
- Resolution:
  - refused without a reference;
  - nothing written without `--confirm`;
  - recorded with it;
  - refused a second time;
  - then gone from `--attention`.

## H-6 · "accepted" read as cancelled (medium → fixed)

- `accepted` is renamed `request_answered`.
- The listing prints **payment**, **conversion** and **reversal** separately; for example "Aplazo answered the cancel request; reversal NOT verified".
- The buyer is never told a reversal happened. The listing text in the test contained neither "refunded" nor "cancelled".

## H-7 · Uncertain reversals picked up every run (low, kit-only path → fixed)

Only `pending` (to send) and `requested` (to close as unknown) are picked up. `request_answered`/`unknown` and resolved attempts are not, so they no longer fill the batch.

## H-8 · Admin cancellation between order and approval (medium → fixed)

**Trace:**
- Cancelling an `order_created` payment-first order (paid, not invoiced) called `cancelLoan` through `CancelAfter`, so a real reversal is undefined (C5).
- The approval would then have marked the attempt `completed` although its order was cancelled.

**Fix (payment-first orders only):**
- `CancelAfter` requests no reversal and adds an order comment saying so.
- Approval runs under Magento's order mutex (`OrderMutexInterface`), the one `OrderManagement::cancel` and refunds take.
- An order found cancelled goes to `needs_review` (`order_canceled_after_payment`), never `completed`.
- `Plugin/PaymentFirst/RefuseStaleCancel` refuses `Order::cancel()` on a stale copy whose committed order is already approved or invoiced. This happens **before** MSI writes its `order_canceled` reservation, which a save-time refusal (fact) would come too late for.
- `Observer/PaymentFirst/KeepPaidOrderOpen` refuses such a save as a second layer.

**Fact:**

| Case | Result |
|---|---|
| `h8_admin_cancel_before_approval` | 0 cancel calls, comment written, native `+1 order_canceled`, `needs_review`, listed |
| `h8_cancel_races_approval_cancel` (core cancellation, held after its load) | serialized by the mutex: cancelled first, approval found it cancelled → `needs_review`, no invoice |
| `h8_cancel_races_approval_cancel_direct` (load + items, held, then `cancel()` and save without the mutex) | refused; order processing with 1 invoice, reservations only `-1 order_placed` |

**Limitation:**
- A cancellation that bypasses the mutex and passes the check in the instant before an approval commits would be refused at save time, after MSI's reservation.
- Only code that bypasses Magento's mutex can reach that window.
- **The loan of a cancelled payment-first order is not reversed by the module until C5 is confirmed.**

## H-9 · A cartId shared with another store's order (medium → fixed)

**Fix:**
- `Model/PaymentFirst/WebhookRouter` routes a validated webhook by `cartId` and the signed `merchantId`.
- Attempt only → attempt. No attempt → the unchanged redirect path.
- Attempt + another store's order with that number: the side whose merchant matches, if exactly one does and (for redirect) the attempt has no order of its own; otherwise `Ambiguous cartId`, refused, nothing touched.
- A paid attempt whose number another store already uses cannot become an order with that number: Magento's `reserveOrderId()` checks every store (fact). It goes to `needs_review` instead of creating an order with another number.

**Fact:**
- **`h9_cross_store_cartid_distinct_merchants`** (second website, its order with the forced number):
  - its webhook used the redirect path and approved it;
  - the attempt's webhook → `needs_review` (`cart_id_used_by_other_order`).
- **`h9_cross_store_cartid_same_merchant`:** `Ambiguous cartId`, the attempt stays `open`, the other order stays `new`.

**Note:** natural collisions need sequences without distinct prefixes. The local second store got prefix `2`, so the collision was forced in the test.

## H-10..H-13

| | Severity | Money / isolation / authorization / recovery? | Status |
|---|---|---|---|
| H-10 `last_error` left over after later checks | low | recovery (misleads operators) | fixed: a completed check clears it |
| H-11 superseded before `createLoan` | low | no: only `starting`/`uncertain` attempts or ones with other data are superseded; an identical open attempt is reused; the old loan stays watched | unchanged, documented |
| H-12 email suppression keyed on the payment field only | low | no: the field is only written by the server; worst case an email is not sent at submit | unchanged |
| H-13 stock check without reservation | info | inventory isolation: two buyers can pass the check at once, as with MSI's own check in `place()`; the loser fails at submit and is retried, then rejected as `out_of_stock` | unchanged, same as core |

## CLI permissions

- `bin/magento aplazo:payment-first:attempts` has no Magento ACL. Whoever can run `bin/magento` (operating-system access to the store) can run it.
- The listing and `--attention` only read.
- `--reconcile` can create orders, and only for loans the status API reports paid.
- `--resolve` writes only the resolution fields, and needs `--reference` and `--confirm`.
- No command calls `cancelLoan` unless `autoCancelRejected` is turned on in `di.xml`; it is off in production.

## H-1 addendum · crash window between the commit and the attempt link (2026-10-29)

**Finding (review of `87b9a0c`):**
- `Finalizer::convert` committed the order and the inactive quote before `finalizeLocked` stored `order_id` on the attempt.
- The quote guard recognises a conversion only by an attempt with `order_id`.

**Reproduction before the fix** (`h1_crash_window_before_link_model`, on `87b9a0c` + kit):
1. A `checkout/sidebar/updateItemQty` request was held at `model_save_before`: inside the quote save, after Magento's own active-cart checks, before the module's guard.
2. The webhook converted the quote and the process died at `order_saved`: order `…270` committed, attempt `open` with `order_id` NULL, quote inactive.
3. The held write then saved:
   - the quote was active again with qty 3 and no reserved order id;
   - the cart started another attempt;
   - recovery adopted the order but left the quote active.

**Held earlier** (`checkout_cart_save_before`, `h1_crash_window_before_link_cart`), the same write was refused, both with the module's guard on and with it disabled through the kit (control run, flag confirmed). The refusal came from Magento itself, traced with `sidebar_probe.php`:
- `SaveHandler::processShippingAssignment` → `ShippingAddressManagement::assign`;
- MSI `InventoryInStorePickupQuote` plugin → `QuoteRepository::getActive` → `validateCachedActiveQuote` → `NoSuchEntityException`.

That depends on the installed MSI module, on 2.4.8's cached-quote validation and on a shipping assignment (not on virtual quotes). It does not cover the moment between that check and the row write, so it was not taken as closing the finding.

**Fix:**
- `order_created` and `order_id` are written in the conversion transaction (`Finalizer::linkOrder`, compare-and-set, re-applied once on a concurrent bookkeeping write, otherwise the whole conversion rolls back).
- `AttemptStore::hasOrderForQuote` is a locking read (`LOCK IN SHARE MODE`), so inside the writer's transaction it sees committed rows.
- Rejected alternative: matching the persisted order through `sales_order_payment.additional_information` on every quote save (JSON, not indexed); with an atomic link it adds nothing.

**After the fix** (`5c4c458`):
- both window cases: the write was refused, the quote stayed inactive (qty 1), the cart could not start another purchase, and recovery completed with 1 invoice and `-1 order_placed` only;
- `crash_at_order_saved_then_reconcile`: attempt `order_created` with its `order_id` after the crash, completed by recovery;
- `conversion_rolled_back_cart_write_allowed`: crash inside the transaction → no order, no link, the cart write is accepted.

**Regression on `5c4c458`:** all H-1 late-write cases, concurrency (both orders), crash before approval, cart changed, two paid attempts, H-8, H-9, redirect, webview and blocked popup passed. `redirect_mode_unchanged` hit a DevTools socket reset once and passed alone on the same SHA.

**Limitations:**
- Writes to the quote that do not go through a quote save (direct `quote_item` writes) are still not covered.
- A process dying inside the conversion transaction relies on the database rollback (tested).

## H-1 addendum 2 · retry in `linkOrder()` and effects of an abort after submit (2026-10-29)

**Defect:**
- Under REPEATABLE READ (the local isolation level), the single retry in `linkOrder()` re-read the attempt with a plain `SELECT`, so it got the conversion transaction's snapshot from before the concurrent write. The retry could never succeed.
- Shown with two connections (plain read 4 while 5 was committed; `UPDATE … WHERE version = 4` affects 0 rows; locking read 5).
- Shown in the flow: the buyer's `complete` during the conversion → `attempt_changed_concurrently` and a rollback of the whole conversion. Recovered later by reconciliation.

**Fix (this commit):**
- `AttemptStore::getForUpdate()`: `SELECT … FOR UPDATE` on the same connection. `get()` is unchanged.
- `linkOrder()` re-reads with it and retries once, if the attempt is still payable and has no order. Otherwise `AttemptNotPayableException`: the conversion rolls back and `finalize()` returns the current state without overwriting it (no `recordTransient`).
- Lock order: quote rows, then the attempt row, as in the quote guard. The other writers of the attempt row only lock that row.

**Local tests against the mock** (records `link_*`, `crash_*`, `coupon_*`, `cart_changed_payment_kept_attention`, H-1 and regression cases; 20 records):
- **17 with `ok=true`**, including:
  - legitimate conflict → linked and completed (1 order, 1 invoice, `-1 order_placed` only);
  - the same conflict plus a crash after the commit → link already persisted, recovery without duplicates;
  - incompatible conflict (**synthetic**, direct SQL) → rollback with the attempt row intact;
  - regression of the H-1 windows, rolled-back conversion, concurrency, repeated payment, crash before approval, late writes, two paid attempts, H-9 and redirect.
- **3 observations serialized with `ok=false`** (no pass/fail assertion): `coupon_success`, `coupon_link_fail`, `link_conflict_then_cart_write`.
- These records do not replace the full matrix of earlier rounds; they are a targeted run.

**Coupon rule usage over-count (demonstrated):**

| Case | coupon `times_used` | rule `times_used` |
|---|---|---|
| `coupon_success` | 0→1 at submit | 0→1 by Magento's consumer |
| `coupon_link_fail` (**synthetic** definitive failure after `submit`) | rolls back (stays at 1) | 1→2 for an order that does not exist |

How it happens:
- `CouponUsagesIncrement::aroundSubmit` publishes the message before `submit` (AMQP, outside the transaction) and only compensates if `submit` itself fails.
- The bulk row (`magento_bulk`) that `CouponUsagePublisher` creates was rolled back with the conversion; the message was not.
- `CouponUsageConsumer::process` runs `updateRuleUsages` (the increment) **before** saving its operation. That save fails on the foreign key to the missing bulk, and the consumer logs `CRITICAL Message has been rejected: SQLSTATE[23000] … MAGENTO_OPERATION_BULK_UUID_MAGENTO_BULK_UUID` **after** the increment.

At `87b9a0c` the link happened after the commit (inspection, not run); the same class of effect existed for any failure between `submit` and the commit. Not fixed: no messaging or compensation mechanism was added.

**Log of a rolled-back order:** `SalesOrderPlaceAfterCreateLoan` traces "Payment-first order placed, loan already paid" inside the transaction. After an abort the local line remains, and the remote post (sent after the locks) reports an order that does not exist. Not fixed.

**Still open:**
- **MSI:** `inventory.reservations.updateSalabilityStatus` is published on every abort (observed). That consuming it is harmless was not demonstrated (consumer not run).
- **Customer coupon usage** (registered customer with a coupon): not run.
- **Recovery between a persisted invoice and a completed approval:** not addressed.
- **The transactional fatal in the interruption test:** clarification pending. What is known:
  - the kit's crash points end the PHP process with `exit()` inside the transaction (HTTP 200 with an empty body), and the database rolls back on disconnect;
  - the only `SQLSTATE` error recorded during these runs is the coupon consumer's rejection described above.
