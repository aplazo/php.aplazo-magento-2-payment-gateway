> **Superseded.** This is the stage-1 design as reviewed on 2026-10-28, kept for traceability. The current design is `DESIGN-BNPL-1664-payment-first.md`; in particular, the reuse window of 30 min and the expiry tied to `cancel_time` (§3) were withdrawn, and §3/§4/§6 were completed after the review.

# BNPL-1664 · Popup payment-first: design for review (stage 1)

Status: read-only diagnosis, 2026-10-28. No code, mounts, branches, PRs or tickets were changed.
Labels: **[D]** Miguel's decision · **[V]** verified fact (source given) · **[H]** hypothesis / design proposal · **[C]** pending external contract.

---

## 0. Verified state

| Item | Value | Source |
|---|---|---|
| master / origin | `d88eb80`, composer `4.0.12`, latest tag `4.0.12` | git, `composer.json` |
| BNPL-1664 | worktree `magento-BNPL-1664`, `d3d72ef`, clean, local = origin; PR #131 **draft** open, head `d3d72ef` | git, `gh pr view` |
| BNPL-1665 | worktree `magento-BNPL-1665`, `b049755` (on top of `d3d72ef`), clean, local = origin; no PR | git |
| Other worktrees | `magento-BNPL-1423` (`d88eb80`), main repo on `BNPL-1607-checkout-discovery` `efabc5a` clean | `git worktree list` |
| Magento | 2.4.8, MariaDB 11.4, lock provider `db`, MSI active, Magento_Paypal 101.0.8, a single store (`default`, website 1) | `bin/magento`, `env.php`, DB |
| Demo environment | mount **BNPL-1665**; `checkout_mode=popup` (websites/1); sandbox 1; delay off; stub URL → `checkout.aplazo.net/mock`; mock 200; `APLAZO-1665-DEMO` 7/7 | `demo1423.sh status` |
| Repo | no `.github/pull_request_template.md` (only `.github/workflows`); no `Test/` dir; `db_schema.xml` with no whitelist | `ls` |

**Current flow facts that shape the design [V]:**
- `cartId` = `shopId` = **order `increment_id`** (`OrderService::createLoan`). Refunds (`RefundObserver*`), cancellation (`CancelAfter`, `cancelOrder`), the cron (`shouldCancelOrder`) and the webhook (`getOrderByIncrementId`) all depend on that equality.
- The webhook finds the order by `increment_id` with **no store filter** (`getFirstItem`), and approves when the **body** `status` is `'Activo'`. It sends the email on every `Activo` with no `getEmailSent()` guard.
- Webapi returns HTTP 200 even when `status:false` → Aplazo cannot tell success from failure by the HTTP code.
- Status API: `GET /api/pos/loan/{cartId}` returns a **list** of loans; the plugin treats `OUTSTANDING` as paid.
- Unique key `SALES_ORDER_INCREMENT_ID_STORE_ID (increment_id, store_id)` in the installed schema. `OrderIncrementIdChecker::isIncrementIdUsed` searches **without store**.
- `QuoteManagement::placeOrder` wraps `placeOrderRun` in `CartMutex` (`cart_lock_<quoteId>`, timeout **0**, throws `CartLockedException`). `submit()` does **not** lock. Nothing else in the core uses `CartMutex`: mutating the cart (items, coupon, shipping) does not take it.
- `Lock\Backend\Database`: named `GET_LOCK`, with no single-lock-per-connection restriction in 2.4.8; it coordinates nodes that share a DB.
- `placeOrderRun` (not `submit`) does: guest prep, `remote_ip`, `checkout_submit_before`, `submit`, session `setLast*`, `checkout_submit_all_after`. With MSI off, `CatalogInventory` listens on `checkout_submit_all_after`.
- `Onepage\Success` calls `$session->clearQuote()` unconditionally after `SuccessValidator`.

## 1. PayPal pattern (vendor 101.0.8) — what we take and what we don't

| Step | PayPal [V] | Aplazo payment-first |
|---|---|---|
| Validation before opening | `in-context/checkout-express.js::onClick → additionalValidators.validate()` | `this.validate() && additionalValidators.validate()` inside the click |
| Save without placing the order | `beforePayment → set-payment-method → set-payment-information` | `Magento_Checkout/js/action/set-payment-information` (exists in 2.4.8) |
| Start from the quote | `Express\Checkout::start`: `collectTotals`, rejects total 0, `reserveOrderId`, saves the quote | `start`: validates, allocates **a new `cartId` per attempt** (§3), persists the attempt **before** `createLoan` |
| Conversion | `place()`: `prepareGuestQuote()` → `ignoreAddressValidation()` → `collectTotals` → `quoteManagement->submit` | same sequence **without** `ignoreAddressValidation`, under lock, with fingerprint and stock checks |
| Guest | `prepareGuestQuote`: `customer_id=null`, email, names, `is_guest`, group `NOT_LOGGED_IN` | the same, copying the core `placeOrderRun` (it does not depend on session) |
| Session for success | `OnAuthorization`: `setLastQuoteId/LastSuccessQuoteId/LastOrderId/LastRealOrderId/LastOrderStatus` + `checkout_submit_all_after` | `setLast*` **only** on the buyer's return; the event is dispatched by the finalizer (also from webhook/cron) |

Not copied: `ignoreAddressValidation()`; PayPal authorization/capture states; reliance on `_checkoutSession`/`paypalSession`; `paymentFailures->handle` or the `review` page.

## 2. Ownership, endpoints and controls

- New frontend endpoints, `HttpPostActionInterface` → core CSRF/form-key validation. No `CsrfAwareActionInterface`.
  - `POST aplazo/popup/start`
  - `POST aplazo/popup/complete`: idempotent; it also serves as the status query.
- **The webhook** stays `POST /V1/aplazo/callback` with its JWT (a different control from the form key; both are kept).
- **Ownership:**
  - `start` uses only the **session quote** (`checkoutSession->getQuote()`): no `quoteId` parameter is accepted. A logged-in customer must be the quote's `customer_id`.
  - `start` returns an opaque `attempt` (128-bit random, unique) that the session keeps in a list (max 10).
  - `complete` requires an `attempt` that is in that list, plus a `customer_id` match when there is a customer.
  - Nothing is read from the client as authority: neither amount, nor status, nor identity, nor order.
- **Store:** always from the persisted attempt (`store_id`). The webhook and cron use `Emulation::startEnvironmentEmulation(store, frontend)` with `try/finally stop`. Emulation does not replace the quote's customer or group.
- **Responses:** no tokens, loanId, emails or addresses; only `status`, an i18n message, and `url` (validated) / `redirect`.

## 3. Persisted record: `aplazo_payment_attempt`

One small table, one row per attempt (not one loanId per quote, which would overwrite attempts).

| Column | Purpose |
|---|---|
| `entity_id` PK, `public_id` char(32) UNIQUE | internal / opaque id for the browser |
| `quote_id`, `store_id`, `customer_id` null | relation and context (`website` derived from store) |
| `cart_id` varchar(32) **UNIQUE** | `cartId` sent to Aplazo = future `increment_id` of the order |
| `loan_id` null, `checkout_url` text null | from `createLoan` (URL validated against the origin before saving) or from the webhook JWT |
| `amount` decimal(20,4), `currency` char(3) | amount and currency sent (display or base, according to `shouldUseDisplayAmounts`) |
| `fingerprint` char(64), `summary_json` | quote hash, plus a PII-free summary (sku/qty/row_total, shipping method code, totals) for diagnosis |
| `status` varchar(20), `loan_cancel_state` null | state machine (below) |
| `order_id` null | resulting order |
| `check_count`, `next_check_at`, `expires_at`, `last_error` (sanitized) | recovery |
| `created_at`, `updated_at` | |

Indexes: `(quote_id, status)`, `(status, next_check_at)`.

**`cartId` = a new value from the store's order sequence for each attempt** (`SequenceManager::getSequence('order', store)->getNextValue()`, the same thing `Quote::reserveOrderId` does).
- It is persisted on the attempt, but **not** written to the quote until conversion.
- Effect:
  - the order is created with `increment_id = cart_id`, so refunds, cancel, cron and webhook keep working with no `cartId` resolver;
  - a redirect `placeOrder` on the same quote uses the quote's own `reserved_order_id` and does not collide;
  - `reserved_order_id` is never reused for two loans.
- Cost: gaps in numbering for abandoned attempts (PayPal causes them too).
- Global UNIQUE `cart_id` plus a check against `sales_order` in every store; on collision, allocate again (bounded).

**States (minimum):**
```
starting ──createLoan ok + valid URL──► open ──paid + converted──► completed
   │                                     │  └─(order saved, approval pending)─► order_created ─► completed
   └─timeout/invalid response─► uncertain ─(reconcile)─► open | expired
open|uncertain ──cart changed / new attempt──► superseded   (still watched for late payment)
paid but not convertible ─► rejected  (+ loan_cancel_state: pending|requested|confirmed|failed|unknown)
not paid on expiry ─► expired          irreconcilable / mismatch / retries exhausted ─► needs_review
```

**Concurrency and repeated starts:**

| Case | Behaviour |
|---|---|
| Double click | JS blocks (`isPlaceOrderActionAllowed`). On the server, `start` runs inside `CartMutex` (same lock as `placeOrder`, fails fast) → the second request gets `busy`. |
| Two tabs / reopen with the same fingerprint | If there is an `open` attempt for this quote, with the same fingerprint and inside the reuse window (constant, 30 min [H]), it **is reused**: same URL, same loan, no `createLoan`. |
| Cart changed and new start | The previous attempt goes to `superseded`; new attempt with a new `cartId`. The old loan stays payable [C3/C5] → if it gets paid it ends `rejected` (§6A). |
| Start after a previous `uncertain` | New attempt with a new `cartId`. The cron reconciles the `uncertain` one. It is not retried with the same `cartId` until C2 is confirmed. |
| Popup start followed by redirect | Redirect does not see the attempt (it uses a different increment). The redirect `submit` (observer `sales_model_service_quote_submit_before`, Aplazo order without an attempt) marks the quote's `open` attempts `superseded`. If the popup loan is paid later, there is no convertible quote → `rejected` (§6A). |
| Expiry | `expires_at` = `created_at` + `cancel_time` (existing config, 720 min by default) [H until C3]. |
| Two orders or two payments on the same cart version | One order is guaranteed by: one `cartId` per attempt, lock, adopting an existing order (§4), and the `increment_id+store_id` unique key as a backstop. Two **payments** can only happen with two loans (supersede or redirect); the second one cannot convert and ends `rejected` → cancellation/refund under C5. |

## 4. Start (in the click) and finalization

**JS, synchronous inside the click:**
1. Run the checkout validations.
2. If the mode is not popup, there is no origin, or a webview is detected (§9), run the **existing redirect** with `window.open` count 0.
3. `win = window.open('')`. If it is null or throws, run the existing redirect with no popup start sent.
4. `set-payment-information`.
5. `POST start`.
6. Validate `url` against the origin (`isOnOrigin`), then `win.location = url`.

`start` answers:
- `use_redirect`: popup no longer effective, guaranteed before any `createLoan`. Close `win` and run redirect.
- `invalid`: the cart is not eligible. Show the message and keep the checkout.
- `uncertain`: close and offer a retry, which creates a new attempt. **Never** fall back to redirect automatically.

**Server `start`, inside `CartMutex(quoteId)`:**
- Load the active quote from the session.
- `collectTotals`.
- `SubmitQuoteValidator::validateQuote` (addresses, shipping, payment), item errors and stock, minimum amount, `aplazo_gateway` method available for the currency/country/total (same checks as `placeOrderRun`), total ≥ 1.00 and supported currency.
- Fingerprint, `remote_ip` on the quote.
- Insert `starting` (committed) → `createLoan(quote payload)` → validate URL → `open`.

The quote payload replicates the order payload field by field: buyer, products, shipping, taxes, discount, `extOrderId = quote_id`. A unit test compares the two.

**Fingerprint [H]:** canonical (sorted) JSON, `sha256`, of:
- store and currencies (`quote`/`base`);
- `customer_id`, `is_guest`, group, normalized email;
- items: `sku`, `product_id`, `qty`, `info_buyRequest` hash, `row_total`, `discount`, `tax`;
- shipping method code and amount;
- shipping and billing addresses (normalized fields);
- `coupon_code`, `applied_rule_ids`;
- `grand_total` / `base_grand_total` in **integer minor units** (round half-up to 2 decimals; never `==` between floats).

**Shared finalization (`PaymentAttemptFinalizer::finalize(attempt, source=browser|webhook|cron)`):**
```
1 identify      browser: public_id from the session · webhook: JWT cartId (+ merchantId == store config; loanId == attempt.loan_id if set) · cron: row
2 confirm       ApiService::getLoanStatus(cart_id) → paid | not_paid | unknown     (OUTSIDE the lock; no HTTP inside)
                not_paid → browser "pending/not_paid" · webhook OK without action · unknown → retryable, no state change
3 lock          LockManager "aplazo_attempt_<cart_id>" (browser 0 s → "processing"; webhook/cron 10 s)
                  └─ CartMutex(quote_id)  (core lock; fails fast if placeOrder is running → "processing", NOT a rejection)
                order: attempt → cart. The core never takes the first one ⇒ no cycle.
4 reload        attempt, quote, order (fresh reads)
5 adopt         completed/order_created → order_id; or order with increment_id=cart_id AND store_id=attempt.store
                and quote_id=attempt.quote_id and marker=public_id → adopt (crash after submit)
6 convert       quote active? fingerprint/amount/currency equal? → guest prep · reserved_order_id=cart_id ·
                server marker (additional_information: aplazo_payment_attempt, aplazo_checkout_mode=popup, loan_id) ·
                importData with the placeOrderRun checks · checkout_submit_before · submit() · checkout_submit_all_after
                → attempt.order_id, status=order_created (short write, outside the order transaction)
7 approve       invoice if canInvoice && !hasInvoices · approved status · history comment and tracking once ·
                email if !getEmailSent → status=completed
8 finally       unlock cart, unlock attempt, stop emulation
```
- The JS `success` message only triggers `complete`.
- A **duplicate key** from `submit`: check that it is `SALES_ORDER_INCREMENT_ID_STORE_ID` (SQLSTATE 23000 + name), then load and validate that order as in step 5. Any other exception is not treated as a duplicate.

## 5. Conversion, inventory and createLoan

- **`SalesOrderPlaceAfterCreateLoan`** skips `createLoan` only when:
  - the order carries `aplazo_payment_attempt`;
  - **and** there is an attempt row with that `public_id`, `cart_id = increment_id`, `store_id` equal, and the status in course.
  The client cannot set that key: `AssignCheckoutMode` (which accepted it from `additional_data`) is removed.
- **Inventory:** MSI native only.
  - `order_placed −N` in `submit`;
  - **without** `aplazo_item_reserved` (there is no `purchase`);
  - without `order_placed_aplazo` in `approveOrder`;
  - without `order_canceled_aplazo` in `CancelAfter`. Magento releases with `order_canceled +N`.
  - `purchase` with a payment-first order (with or without `mode=popup`) makes no compensation and only redirects.
  - Redirect and historical orders without the marker: unchanged.
- `reserve_stock` comment: *"Applies to Redirect only. In Popup mode (payment-first) the order is created after payment and uses Magento's native inventory."*
- **Guest / registered customer without session:** the finalizer does not touch `customerSession`/`checkoutSession`; the quote carries `customer_id` and group. It is verified from webhook and cron in tests.

## 6. Failures and recovery

| Class | Example | Action |
|---|---|---|
| **A** definitive rejection before the order | quote inactive or consumed by another order, fingerprint/amount different, insufficient stock, validation (`LocalizedException` and no order with that increment) | `rejected`, `loan_cancel_state=pending`; **after** releasing locks, request `cancelLoan` [C5]; buyer: "we could not confirm your order; Aplazo will reverse the charge" **only** when `confirmed`, otherwise "we are reviewing your payment" |
| **B** uncertain | `createLoan` timeout, `getLoanStatus` error, an exception other than `LocalizedException` in `submit`, a crash | state unchanged + `next_check_at` with backoff; the cron reconciles (step 5 adopts an order if it exists); after N → `needs_review`. Never cancel or repeat blindly |
| **C** order created, finalization incomplete | crash between `submit` and invoice | `order_created` → the cron or the next signal resumes at step 7; the existing `CancelOrders` sees `OUTSTANDING` and approves too (compatible) |
| **D** cancellation failed or uncertain | `cancelLoan` timeout | `loan_cancel_state=failed|unknown`, the cron retries; never reported as reverted |

Specific cases:
- **Webhook before `loanId`:** the row exists since `starting` → found by `cart_id`. `loan_id` is taken from the JWT if missing; payment is confirmed through the status API.
- **No webhook and no browser:** new cron job `aplazo_payment_attempt_recover` (*/5). It only touches attempt rows in `starting>2 min|uncertain|open|superseded|order_created|rejected(cancel pending)` with `next_check_at ≤ now`, in batches. If the status is paid, it finalizes (`source=cron`), which **creates and approves the order**. It never processes orders without an attempt.
- **Session expired:** the server finalizes anyway. `complete` without ownership answers neutrally ("you will receive the confirmation by email"), with no data.
- **Restart during finalization:** DB locks are released when the connection closes; persisted state plus adoption by increment+store.
- **Operational visibility:** `LogService` error (no secrets or PII) plus a CLI command `aplazo:payment-attempt:list|recover`. An admin grid is left out [H].

## 7. Buyer return and closing

- **Closed without confirmed payment:** one call to `complete` → `not_paid/pending`.
  - Re-enable the button and say "the cart is still available".
  - Quote unchanged, no order, no reservations.
  - The REQUEST loan can still exist at Aplazo: this is not described as "no effect at all".
- **Paid:** closing does not cancel anything. On `focus`/`visibilitychange`, call `complete` (with limits, e.g. every 5 s for at most 2 min).
- **`completed` in the browser:** `setLast*` (as in `OnAuthorization`) only if `order.quote_id == attempt.quote_id` and ownership is valid.
  - If the session already has **another active quote with items**, do not go to `onepage/success` (its `clearQuote()` would drop the new cart). Show a notice with the order number instead.
- **Popup landing pages** (`successUrl`/`cartUrl`/`errorUrl` of the popup loan) on a store page that only closes the window. No new message channel and no `cancel` operation (it cancels orders).

## 8. Reuse and removal (from `d3d72ef` / `b049755`)

| Reuse | Remove / replace |
|---|---|
| `CheckoutMode` source, `system.xml`/`config.xml` (website, default redirect), `Helper::getCheckoutMode/getCheckoutOrigin(?int $storeId)` (sandbox only) | the popup branch of `purchase`; `PlacedOrders`, `RememberPlacedOrder` (+ event); `popup_return`, `popup_success` |
| `ConfigProvider` (`checkoutMode`, `aplazoOrigin`, + `startUrl`/`completeUrl`) | `AssignCheckoutMode` (marker set by the client) |
| `popup-checkout.js`: synchronous opening, `origin` + `event.source` + `version` + payload checks, watcher. Without the order-first states (`lost*`, `reopenBlocked`, recovery) | order-first states and texts of the renderer; `session_orders_check.php` |
| `PopupCheckout::isOnOrigin` / `withPopupMode` → `CheckoutUrlValidator` | — |
| BNPL-1665 marker idea → server-backed `PaymentFirstMarker`; skips in `approveOrder`/`CancelAfter`/`purchase` | — |
| harness, `mock_https.py`, stub (+ `getLoanStatus` per `cartId`), `snapshot.php`, `order_ops.php`, CDP `run.py` | — |

Dependencies checked: `PlacedOrders` is only used by `PopupCheckout`, `RememberPlacedOrder` and `Operations`. Redirect (`purchase` without `mode`, `cancel`, `redirect_to_onepage`) is kept as in master.

## 9. Webview / mobile

- Rules **[D]**, case-insensitive where it applies:
  - `FBAN|FBAV`, `Instagram`, `Twitter`, `Line/`;
  - Android + `wv`;
  - `webview|wv`.
- Effect: redirect with `window.open` count 0. Other mobile browsers: popup.
- **Difference [V] from `angular.partner-core` 3.2.1 (master `0beb0d34`, `WebViewDetectionService`):**
  - it uses `includes('Line')` (not `Line/`);
  - `/android/i && /wv|webview/i`, then `/webview|wv/i` with no anchor.
  - So equivalence is not claimed. The unanchored `wv` can give false positives (it only costs redirect).
- It does not detect installed apps or domain association: that needs device tests (§11).

## 10. Contracts pending with Aplazo

| # | Topic | Evidence | Assumption for the mock |
|---|---|---|---|
| C1 | Uniqueness of `cartId` | [V] `java.aplazo-loan-ms-service/docs` (internal `/api/internal/loan`, "v3"): one loan per `merchantId+cartId`; if `REQUEST` it is **updated**; any other status → error. Unknown whether the plugin's `/api/loan` behaves the same | one `cartId` per attempt; never reused |
| C2 | Idempotency or lookup after a `createLoan` timeout | same document ("cart-based idempotency") for the internal endpoint only | not relied on; `uncertain` + status lookup |
| C3 | Expiry of unpaid loans | none | local expiry = `cancel_time` |
| C4 | Status that proves payment | [V] the plugin uses `OUTSTANDING` on `/api/pos/loan/{cartId}`; merchant-partners maps OUTSTANDING → "Activo"; `aplazo-integrations-docs` talks about `ACTIVO` and `/api/v1/loan/status` for online. They conflict | `OUTSTANDING` on the current endpoint |
| C5 | `cancelLoan` after payment (cancel vs refund) | none | the mock answers success; the state model allows switching to `createRefund` |
| C6 | Callbacks before the order | [V] merchant-partners `MerchantCallbackServiceImpl` (master `c209533c`): on attempt 1 it sends **NEW** then the confirmation to `webHookUrl`. **Both JWTs are signed with the NEW callback data** (`status` claim ≠ Activo, 30-min expiry); the body `status` is not signed | JWT = correlation (`cartId`, `loanId`, `merchantId`); payment only through the status API |
| C7 | Fields to verify | JWT: `loanId`, `cartId`, `merchantId`. The status response: the plugin only reads `status`; amount/currency/merchant not confirmed | fingerprint and amount against the attempt; compare with the loan amount when the field exists |
| C8 | Webhook delivery and retries | [V] `MerchantCallback` keeps `attempts` and `SUCCESS/PENDING/NO_WEBHOOK`; schedule and success criterion not confirmed (the plugin always answers HTTP 200) | no retries assumed; the cron recovers |

Side note (current behaviour, out of scope): because of C6, the redirect flow approves on an unsigned body `status`. Payment-first does not inherit this because it confirms through the API.

## 11. Tests

1. **Mock/local (stage 2), with a CDP harness and stub:**
   - paid with the browser present;
   - paid and closed before the message;
   - closed without payment (quote, orders and reservations unchanged);
   - `complete` and webhook simultaneous, repeated and in both orders. Controlled sync: a stub flag that blocks `getLoanStatus` or a pause hook inside the lock, kit only;
   - duplicate start, two tabs, reuse;
   - late payment of a superseded attempt;
   - `createLoan` timeout and early webhook (NEW + confirmation);
   - cart changed with a different amount **and with the same amount**;
   - last unit consumed;
   - crash after `submit` (kill during the pause) and resumption without duplicating invoice or email;
   - `cancelLoan` failed or uncertain;
   - session expired;
   - CSRF, ownership, someone else's attempt, invalid JWT;
   - config changed after the start;
   - guest and registered customer;
   - multistore (a second local website/store);
   - redirect regression and blocked popup;
   - webview UAs;
   - shipment and physical stock.
2. **No MSI:** pending. It needs a separate instance; modules are not disabled in this store, and compatibility is declared for MSI only until then.
3. **STG (stage 3):** as the team's procedure says. Not presented as done while there is only a mock.

Evidence for each run: commit, entities, simulated services, results, pending items. The dedicated cron only runs on attempts from the run; no manual reservation edits.

## 12. Branch, files and increments

- **Base:** `origin/master` `d88eb80`.
- **Branch:** `BNPL-1664-payment-first`, a separate worktree (`magento-BNPL-1664-pf`).
- Reusable files from `d3d72ef`/`b049755` are brought in with `git checkout <sha> -- <path>` and adjusted, so `BNPL-1664-popup-config-messaging` and `BNPL-1665-popup-native-reservation` are untouched (no reset or force push).
- Alternative: continue on top of `d3d72ef` with removal commits. That keeps #131's history, but its net diff includes code that gets deleted. Recommended: new branch + new PR that supersedes #131.

**New:**
- `etc/db_schema.xml` (table) and `etc/crontab.xml` (job);
- `Model/PaymentAttempt{,/ResourceModel,/Collection,Repository}.php`;
- `Model/PaymentFirst/{StartService,QuoteFingerprint,QuoteLoanPayloadBuilder,Finalizer,LoanStatusChecker,PaymentFirstMarker,CheckoutUrlValidator}.php`;
- `Controller/Popup/{Start,Complete,Landing}.php`;
- `Cron/RecoverPaymentAttempts.php`;
- `Console/Command/PaymentAttemptCommand.php`;
- `Observer/SupersedeAttemptsOnSubmit.php`;
- `view/frontend/web/js/model/webview.js`.

**Modified:**
- `Model/Notifications.php` (attempt branch first, redirect unchanged);
- `Observer/SalesOrderPlaceAfterCreateLoan.php`;
- `Model/Service/OrderService.php` (`approveOrder` skip);
- `Observer/Order/CancelAfter.php`;
- `Controller/Order/Operations.php` (`purchase` guard);
- `Helper/Data.php`, `Model/Ui/ConfigProvider.php`, `etc/{config,events,di}.xml`, `etc/adminhtml/system.xml`, `i18n/es_MX.csv`;
- the renderer JS, `popup-checkout.js`, `form.html`.

**Commits (`BNPL-1664: …`):**
1. port config, URL validator and marker;
2. table + fingerprint + payload (unit tests);
3. start + JS (opening, fallback, webview);
4. finalizer + complete + webhook + createLoan/inventory skips;
5. cron + CLI + reject/cancel;
6. harness/stub/validation;
7. at release time, bump to `4.0.13` in `composer.json` + `versionhistory.md` (current version checked: 4.0.12).

**Checks:**
- `php -l`;
- `setup:upgrade` (schema) and `setup:di:compile` in local;
- phpcs Magento2 if it is installed;
- harness e2e;
- diff review.

**Mount:** in stage 2 the local mount changes from BNPL-1665 to the new worktree; the demo environment is left as BNPL-1665 until then.

## 13. Tickets and PRs (drafts — nothing published)

- **BNPL-1664 (comment):**
  - Scope changed to payment-first per Miguel's decisions (list in §[D]).
  - Redirect untouched; popup sandbox only.
  - Design: this document.
  - New branch `BNPL-1664-payment-first`; PR #131 will be superseded by a new draft PR and closed with a link once that PR exists.
  - Pending contracts C1–C8 listed.
- **BNPL-1665 → Won't Do (comment before the transition):**
  - Order-first is dropped because payment-first creates the order after payment.
  - Moved to BNPL-1664: a server-backed marker, no plugin movements in approval/cancellation, the `purchase` guard, `storeId` in the Helper.
  - Reference kept: branch `BNPL-1665-popup-native-reservation` @ `b049755` (not deleted) and `VALIDATION-BNPL-1665.md`.
- **PR #131:** comment linking the new PR, then close it (stage 3). No force push.
- No new tickets: C1–C8 are raised as questions for Aplazo inside BNPL-1664. BNPL-1666 (Universal Checkout) and BNPL-1667 (mobile) are only referenced.
- The repo has no PR template: the PR follows #131's format (Summary / Changes / Validation), in English.
