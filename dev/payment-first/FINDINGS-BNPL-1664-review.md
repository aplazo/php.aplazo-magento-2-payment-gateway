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
