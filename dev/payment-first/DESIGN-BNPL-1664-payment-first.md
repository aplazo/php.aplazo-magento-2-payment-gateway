# BNPL-1664 · Popup payment-first: design

Status: implemented on `BNPL-1664-payment-first`, validated **against a mock only**; see `VALIDATION-BNPL-1664-payment-first.md`. The review findings H-1..H-13 and their fixes (quote guard, persisted context, webhook routing, reversal recovery and operator resolution, attention report, admin-cancel guards, logging outside locks) are in `FINDINGS-BNPL-1664-review.md`; where they differ, that file is current.

Each statement is tagged:
- **[D]** a product decision (Miguel);
- **[V]** verified in code;
- **[H]** a choice of this design;
- **[C]** a pending Aplazo contract (§9).

The first version of this design was reviewed on 2026-10-28; sections 3–8 incorporate that review.

## 1. Decisions and scope

**[D] Product decisions:**
- Popup is payment-first: no Magento order exists before Aplazo confirms the payment.
- Closing the window unpaid returns to checkout with the cart intact: no order and no stock movement.
- Redirect stays order-first and unchanged.
- The mode is a website-level select, with `redirect` by default. Popup is effective only with sandbox on.
- The choice is made inside the click:
  - if the window opens, payment-first;
  - if it is blocked or fails to open, the existing redirect, before any popup loan is requested.
- Known webviews get the redirect. Other mobile browsers get the popup.
- Payment-first orders use only Magento's native inventory.
- The server must be able to create the order when the payment is confirmed and the browser is gone.
- No remote flag is needed to activate the pilot.

**Out of scope:**
- the redirect inventory label and its repeated compensation;
- fixing the redirect flow's webhook (see `SECURITY-NOTE-webhook-status.md`);
- the delivery retries of merchant-partners.

## 2. Flow

```
click ─ validations ─ webview? ──yes──► existing redirect (no window.open)
                        │no
                    window.open ──blocked/error──► existing redirect (no popup loan requested)
                        │opened
        set-payment-information (no order) ─► POST aplazo/popup/start
                        │
   start: CartMutex(quote) ─ validate ─ attempt row (starting, committed) ─ createLoan ─ URL on origin? ─► open
                        │                                                                │no / error
                   window → Aplazo                                                   uncertain (no redirect fallback)
                        │
   signals: window message · window closed · page focus · webhook · reconciliation (cron)
                        │
                Finalizer::finalize()  ── the only path to an order
```

## 3. Conversion: what is converted and how changes are excluded (review point 3)

**What is converted.** It is the quote as stored in `quote`, `quote_item`, `quote_address` and `quote_payment`, reloaded inside the conversion transaction. The comparison runs on exactly the object that is then submitted, so nothing is copied or snapshotted.

**How it is protected [V].** `Finalizer::convert()` runs in a single DB transaction:
1. `SELECT … FOR UPDATE` on the `quote` row, then on the quote's `quote_item`, `quote_address` and `quote_payment` rows.
2. Only then is the quote loaded (`loadByIdWithoutStore`), and the payment method is imported with the `placeOrderRun` checks. It is recalculated (`collectTotals`) and validated (`SubmitQuoteValidator`).
3. It is compared with the attempt:
   - the fingerprint (store, currencies, customer and group, items with SKU, qty, options hash and row, discount and tax amounts, shipping method and amount, addresses, coupon, rules, totals);
   - the amount and currency, in integer minor units;
   - stock (MSI `AreProductsSalableForRequestedQty`).
4. `QuoteManagement::submit()` runs on that same object, still in the transaction (order, MSI reservation and quote deactivation share the connection), then commit.

**Why a concurrent change cannot slip in [V].**
- A normal cart change writes those rows. Updating an item or address, or saving the quote, takes their row locks. Adding an item inserts into `quote_item`, whose foreign key check needs a shared lock on the `quote` row.
- So every committed change either happened before the `FOR UPDATE` (and is then seen and compared) or waits until the order is committed or rolled back.
- `CartMutex` is also held, but only against `placeOrder` and our own endpoints. It is **not** what excludes normal cart edits.

**After the commit.** A change that waited meets an inactive quote, as after any core `placeOrder`.
- Measured: the blocked add-to-cart waited about 7 s, then failed visibly with Magento's own message ("No such entity with cartId = …").
- It added nothing to the converted quote and created no other cart.
- That message is Magento's core text, not one written for this flow.

**A change before the commit** (while Aplazo is open):
- If the fingerprint differs, the paid attempt is **rejected** (`cart_changed`), whether or not the amount changed.
- The buyer's cart is not overwritten, and no snapshot is used to bypass stock or validation.
- The loan then needs a reversal (§6, blocked on C5).

## 4. Persisted attempt and state machine

**The table.** `aplazo_payment_attempt` has one row per requested loan, so earlier attempts are never overwritten. Its main columns:
- `public_id` (opaque, given to the browser), `quote_id`, `store_id`, `customer_id`;
- `cart_id` (UNIQUE: the `cartId` sent to Aplazo, and later the order's `increment_id`);
- `loan_id` and `checkout_url` (validated);
- `amount` and `currency`, `fingerprint`, `summary_json` (no personal data);
- `status`, `version` (compare-and-set), `order_id`, `reject_reason`, `loan_cancel_state`;
- `email_requested_at`, `check_count`, `next_check_at`, `last_error` (sanitized).

**[H] Where the `cartId` comes from.** A new value from the store's order sequence for each attempt.
- Refunds, cancellation and the existing cron keep using the `increment_id`.
- It is written to the quote only at conversion, so a redirect order from the same quote gets another number.
- It is never reused for two loans.
- Abandoned attempts leave gaps in the numbering.

**States:**

| State | Meaning | Left by |
|---|---|---|
| `starting` | row committed, `createLoan` in flight | start → `open` / `uncertain`; reconciliation after the start grace |
| `open` | loan created, URL validated | finalize (paid) · a newer start with other data → `superseded` |
| `uncertain` | `createLoan` failed, timed out or returned no usable URL; a loan may exist | finalize (paid, e.g. an early webhook) |
| `superseded` | a newer attempt with other data exists for the quote; its loan may still be paid | finalize (paid) → normally `rejected` |
| `order_created` | order committed; approval pending | finalize resumes approval → `completed` |
| `completed` | order approved and invoiced | final |
| `rejected` | paid, but the quote could not become this order (`quote_not_active`, `cart_changed`, `amount_changed`, `out_of_stock`, `not_eligible`, `quote_mismatch`) | reversal states only (§6) |
| `needs_review` | inconsistent data, e.g. the `cartId` used by an order that is not this attempt's | operator |

**Every change is a compare-and-set on `version`.** A decision made on rows read earlier is never written over a newer state.

## 5. Coordination of start, finalization, reconciliation and reversal (review point 6)

| Operation | Locks | Reads it trusts | Writes |
|---|---|---|---|
| **start** | `CartMutex(quote)` (fails fast → `busy`) | session quote, recalculated | supersede previous; insert `starting`; then `open`/`uncertain` (CAS) |
| **finalize** | 1) `aplazo_pf_attempt_<cartId>` (lock manager; browser 0 s, webhook/reconcile 10 s), 2) `CartMutex(quote)`, 3) the DB rows of §3 | status API (before the locks); attempt, quote and order reloaded inside | `order_created`, `completed`, `rejected` (CAS) |
| **reconcile** | through finalize, or the attempt lock for reversals | due rows only | schedule fields; through finalize |
| **reversal** | attempt lock | attempt reloaded; `loan_cancel_state` must be `pending` | CAS `pending → requested`, then `accepted`/`unknown` |

- **Lock order** is always attempt → cart → rows. The core never takes the attempt lock, so there is no inversion.
- **The lock backend** is the default `db` (`GET_LOCK`). It is shared by every node on the same database and is released if the process dies.

**Why the status is queried outside the lock, without acting on stale answers:**
- Only `PAID` triggers work, and it is re-decided on reloaded rows inside the lock.
- `PAID` cannot become untrue through this module, except by a reversal. A reversal only starts from `rejected` and holds the same attempt lock. A stale `PAID` therefore meets `rejected` / `completed` after the reload and does nothing.
- `NOT_PAID` and `UNKNOWN` only move the schedule (CAS; lost races are ignored). They never close an attempt and never mark a loan unpaid.

**A held lock (`busy`)** is never a rejection.
- The browser answers `pending` and asks again on its next signal.
- The webhook and the reconciliation schedule an immediate re-check.
- `CartLockedException` (a `placeOrder` running for the quote) is treated the same way.

**Transient errors** (an exception from `submit`, a deadlock, a lock wait timeout, a failed invoice):
- the transaction is rolled back;
- `last_error` gets the exception class (plus the message only for the module's own `RuntimeException`s);
- `check_count` goes up, and the next check uses the backoff.
- Only the explicit checks of §3 are definitive; anything raised later by `submit` is retried.

**Registered customers via webhook [V]:**
- Magento's `AccessChangeQuoteControl` (`webapi_rest`) refuses to save a customer's quote from the anonymous callback. It is not bypassed.
- For those attempts the webhook schedules an immediate reconciliation (`crontab` area); the buyer's own `complete` also converts them.
- Without a browser, the order then waits for the next cron run.

## 6. Several attempts, late payments and redirect (review point 4)

**Rule: a quote produces at most one order, and the first paid attempt to convert wins** [V]. That is enforced by the attempt lock, `CartMutex`, the quote row lock and `is_active`. A new reference per attempt prevents cartId reuse, **not** these conflicts:

| Case | Behaviour | Real integration |
|---|---|---|
| Same data, second click, another tab, reopening | the open attempt is reused: same loan and URL (identity, not time) | depends on C3 (an expired checkout URL keeps being reused) |
| Data changed, new start | the old attempt goes `superseded` (never cancelled at start); new attempt and loan | the old loan stays payable |
| Both attempts paid, old one first | old → `rejected/cart_changed`; new → `completed` | **blocked on C5** (reversal) |
| Both paid, new one first; old paid late | new → `completed`; old → `rejected/quote_not_active` | **blocked on C5** |
| Popup pending, another tab places a redirect order | redirect unchanged (order-first, `-1 order_placed`, `+1 aplazo_item_reserved`); the popup loan paid later → `rejected/quote_not_active` | **blocked on C5** |
| Last unit sold elsewhere | `rejected/out_of_stock`; no order | **blocked on C5** |

**[H] The reversal of a `rejected` attempt** (`Model/PaymentFirst/Reconciler`) is off by default (`autoCancelRejected=false`).
- What `cancelLoan` does after a payment is not confirmed (C5).
- The attempt stays `rejected / loan_cancel_state=pending`, visible with `bin/magento aplazo:payment-first:attempts --status=rejected`.
- When the kit turns it on, `cancelLoan` is called once. An answer is recorded as `accepted`: the request was taken, not proof the money was returned. A failure is recorded as `unknown` and is not retried automatically.
- The buyer is never told that a reversal happened.

**Not done here:**
- No change to redirect resolves these cases. Redirect is not altered.
- No superseded loan is cancelled automatically. That would also depend on C5 and on a "cancel only if unpaid" guarantee.

## 7. Timings: what is a policy and what is unknown (review point 5)

| Concept | In this design | Source |
|---|---|---|
| Checkout validity | unknown; not enforced | **C3** |
| Attempt reuse | identity only (quote + fingerprint + `open`); **no time window** | [H] |
| Loan expiry | unknown; never inferred | **C3** |
| Query frequency | `ReconcileSchedule`: start grace 120 s; then 60, 120, 300, 900, 1800, 3600 s, then every 10800 s. This is polling cadence only, and the kit overrides it in `di.xml` | [H] |
| Closing an attempt | only on evidence: `completed`, `rejected` (+ reversal state) or `needs_review`. **Unknown or unpaid answers never close an attempt** | [H] |

The earlier 30-minute reuse window and the expiry tied to `cancel_time` were removed. An unpaid attempt therefore keeps being checked at the ceiling interval until C3 defines an expiry.

## 8. Approval, email and webhook (review points 7 and 8)

**Approval** (`order_created → completed`). It uses Magento's invoice service (`OrderService::invoiceOrder`), once:
- no second invoice if one exists;
- the status, comment and tracking are written only on the change to processing;
- an order someone already cancelled or closed is left as it is.

A crash after the order is saved (whether or not the attempt was updated) is resumed by adopting the order. The order must have `increment_id = cartId`, the same store, the same quote and the attempt's mark. A `cartId` used by any other order means `needs_review`.

**Email:**
- The `SubmitObserver` email at submit is suppressed for these orders (`Plugin/Order/NewOrderEmail`).
- After approval the finalizer claims `email_requested_at` with a CAS and hands the order to `OrderSender::send()` once.
- With async sending, Magento's `sales_send_order_emails` cron retries the delivery. With sync sending, a failure is logged and not retried by this module.
- Delivery exactly once is **not** guaranteed: a crash between the claim and the hand-off loses that email. A failure never touches the order or the loan.
- Local evidence: the mail transport is not configured, so `email_sent` stays empty and `send_email=1`. The orders were completed regardless.

**Webhook:**
- The existing JWT validation is kept as it is.
- The JWT `cartId` names an attempt, and the signed `merchantId` must equal the attempt store's merchant id.
- A signed `loanId` must equal the attempt's when it has one; it fills it when it is missing (a `createLoan` answer that was lost).
- Then finalize. The body's `status` is ignored; see `SECURITY-NOTE-webhook-status.md`.
- Redirect orders continue through the unchanged code below.

**Browser endpoints** (`aplazo/popup/start`, `aplazo/popup/complete`):
- POST with a **required** form key. The core frontend CSRF check skips requests with `X-Requested-With`; these endpoints do not rely on it (verified: 403 without the key).
- Only the session quote is used, and only attempts this session (and customer) started are answered.
- No tokens, loan ids or buyer data are sent back.

**Session data:**
- `setLast*` is written only on the buyer's `complete`.
- If the session already holds another active cart with items, the order number is shown in the checkout instead of Magento's success page, whose `clearQuote()` would drop that cart.

**Other skips, keyed on the server-written mark (order mark + attempt row with the same `cartId` and store):**
- `SalesOrderPlaceAfterCreateLoan` skips `createLoan`.
- `OrderService` skips the plugin stock movements (purchase, approval, cancellation).
- `Cron/CancelOrders` skips these orders.
- `purchase` makes no compensation.

The client cannot set that mark: BNPL-1665's `AssignCheckoutMode` was not ported.

## 9. Pending Aplazo contracts (visible until resolved)

| # | Topic | Evidence | Mock assumption |
|---|---|---|---|
| C1 | `cartId` uniqueness | loan-ms internal docs: one loan per merchantId+cartId (update while REQUEST, error otherwise); the public `/api/loan` mapping is unconfirmed | one `cartId` per attempt, never reused |
| C2 | idempotency or lookup after a `createLoan` timeout | same document, internal endpoint only | not relied on: `uncertain` + status lookup + webhook |
| C3 | checkout validity, expiry of unpaid loans | none | no expiry; reuse by identity |
| C4 | which status proves payment | the plugin uses `OUTSTANDING` on `/api/pos/loan/{cartId}`; merchant-partners maps it to "Activo"; the integration docs mention `ACTIVO` and `/api/v1/loan/status` | `OUTSTANDING` on the current endpoint |
| C5 | `cancelLoan` after payment (cancel vs refund) | none | the stub accepts; reversal off by default |
| C6 | callbacks before the order | merchant-partners: NEW then confirmation, both JWTs from NEW | correlation only; payment from the API |
| C7 | fields for amount, currency, merchant in the status answer | the plugin only reads `status` | amount and fingerprint checked against the attempt; `loanId` compared when present |
| C8 | webhook delivery and retries | merchant-partners keeps attempts and a status; schedule and success criterion unknown (the plugin answers HTTP 200 always) | no retry assumed: reconciliation |

## 10. Known limitations

- **Without MSI:** not validated (no instance); `StockCheck` returns true and leaves stock to `submit`.
- **Multistore:** not validated locally (one store). The store is taken from the attempt everywhere.
- **`X-Forwarded-For`** is not kept for these orders; the quote table has no column for it. `remote_ip` is kept.
- **The webview heuristic** differs from `angular.partner-core` 3.2.1: `Line/` with the slash, and `wv` as a whole token. It cannot detect installed apps or domain association.
- **Integration status:** STG and devices are pending; nothing here proves the real contracts.
