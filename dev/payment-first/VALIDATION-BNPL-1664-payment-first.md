# BNPL-1664 · Popup payment-first: local validation against a mock

**Run:** 2026-10-28, 19:20–19:35 UTC. **Code:** `BNPL-1664-payment-first` @ `70d8737`, local commits only (not pushed).

**This is not an integration test.** Aplazo's API, checkout and webhook are simulated, so nothing here proves the contracts C1–C8 (`DESIGN-BNPL-1664-payment-first.md` §9). STG and devices are pending.

## Environment

- **Store:** Magento 2.4.8 (docker-magento), MariaDB 11.4, MSI on, lock provider `db`, developer mode, a single website and store, `en_US`.
- **Plugin:** this worktree mounted at `app/code/Aplazo/AplazoPayment`, then `setup:upgrade` and `setup:di:compile`.
- **Configuration:** `checkout_mode=popup` on website `base`, sandbox on.
- **What is simulated:**
  - `dev/popup-harness/magento-e2e/stub-module` (`LocalDev_AplazoStub`) replaces `ApiService`, `TrackingService` and `LogService`; outbound HTTP is refused.
  - **"Paid"** means the stub answers `OUTSTANDING` for that `cartId`. The answer comes from `var/localdev_aplazo_stub_loans/<cartId>`, or a global default.
  - The stub can also simulate `createLoan` failing, `cancelLoan` failing, and pause or crash points on the finalizer's events.
  - **Aplazo's window** is `mock_https.py`, reached as `checkout.aplazo.net` through the test Chrome's `--host-resolver-rules` only.
  - **The webhook** is `webhook_sim.php`: HS512 with the local store's apiToken, through the real `/rest/V1/aplazo/callback`. It signs `status=Nuevo` in the JWT, as merchant-partners does.
- **Kit parameters** (stub `di.xml`):
  - reconciliation delays of 1 s and a start grace of 2 s;
  - `autoCancelRejected=true`, so the reversal path runs against the stub. Its production default is `false` (C5).
- **Isolation:**
  - The reconciliation was only run with `--id`, on the attempts of each test.
  - The global cron was not run, and no reservation was edited by hand.
  - In the containers, `/etc/hosts` maps the Aplazo domains to 127.0.0.1.
- **Test data:**
  - products `APLAZO-1664-PF` and `APLAZO-1664-LAST`;
  - guest `pf-test@example.invalid`, customers `pf-registered*@example.invalid`;
  - orders `000000120`–`000000152`, attempts 44–71.

**How to run:** `MAGENTO_DIR=<docker-magento dir> python3 -I dev/popup-harness/magento-e2e/pf_e2e.py [setup] all`. Results are appended to `dev/popup-harness/.out/magento-e2e/pf.jsonl` (ignored by git).

## Results

28 tests: 27 passed in the full run. `last_unit` failed with a DevTools socket reset (`ConnectionResetError` in `cdp.py`) before any assertion, then passed when rerun alone on the same commit.

| Test | What it shows | Result |
|---|---|---|
| close_unpaid_and_reuse | Aplazo posts `close`, the loan is not paid: no order, no reservation, quote active with its item, `reserved_order_id` untouched, notice "You closed the Aplazo window…", button enabled. Clicking again reuses the same attempt and URL (1 createLoan) | Pass |
| paid_success_message_once | Paid, `success` message: one order with `increment_id = cartId`, processing, 1 invoice, mark = attempt, reservations only `-1 order_placed`, 1 createLoan. A repeated `complete`, webhook and reconcile change nothing (same order, invoice, reservations, `email_requested_at`) | Pass |
| paid_window_closed_before_message | Paid, the window closes before any message: the closed window makes the page ask; one order; success page | Pass |
| concurrent_webhook_first | The webhook holds the locks inside the conversion (8 s pause); the browser's `complete` meanwhile gets `pending`, then `completed`. One order, one invoice, `-1` | Pass |
| concurrent_complete_first | `complete` holds the locks; the webhook waits for the attempt lock and answers `completed`. One order, one invoice, `-1` | Pass |
| crash_at_order_saved_then_reconcile | PHP exits right after the commit: order saved without invoice, attempt still `open`; the reconciliation adopts the order and approves it. One order, one invoice, `-1` | Pass |
| crash_at_approve_before_then_reconcile | PHP exits before approval: attempt `order_created`; the reconciliation approves it. One invoice | Pass |
| cart_changed_same_amount | Another tab changes the shipping street (same total) while Aplazo is open, then the loan is paid: `rejected/cart_changed`, no order or reservation, quote active, page "rejected" (no reversal claimed), 1 cancelLoan (stub) | Pass (C5-blocked) |
| cart_changed_amount | The same with one more unit (other total): `rejected/cart_changed` | Pass (C5-blocked) |
| cart_mutation_waits_for_conversion | An add-to-cart sent while the conversion is paused inside its transaction waits ≈7 s, then fails visibly (Magento's "No such entity with cartId = …", cart count 0). The order and the converted quote keep their 2 units | Pass |
| two_attempts_both_paid_old_first | A1 (1 unit) closed unpaid, A2 (2 units) → A1 `superseded`; both paid; A1 first → `rejected/cart_changed`, A2 → `completed`. One order, `-2` | Pass (C5-blocked) |
| two_attempts_both_paid_new_first | A2 completes first; A1 paid late → `rejected/quote_not_active`. One order | Pass (C5-blocked) |
| popup_pending_redirect_other_tab | Popup open; another tab of the same session (webview UA) places a redirect order: unchanged redirect (`-1 order_placed`, `+1 aplazo_item_reserved`, no window). The popup loan paid later → `rejected/quote_not_active` | Pass (C5-blocked) |
| status_unknown_then_paid | The status API fails (and then answers an empty list): notice "We could not confirm your payment yet…", the attempt stays `open` with `check_count` growing; once paid, the reconciliation completes it | Pass |
| create_loan_fail_then_early_webhook | createLoan fails: attempt `uncertain`, window closed, no redirect fallback, no order or reservation. An early webhook (loan paid, `loanId` only from the JWT) completes it and records the `loanId` | Pass |
| webhook_correlation | Forged signature rejected by the existing JWT check; another merchantId and another loanId rejected; a valid callback with body `Activo` but an unpaid loan → `not_paid`, no order | Pass |
| ownership_and_form_key | Another session: `complete` → `unknown_attempt`, `start` → `invalid` (its empty cart). Without a form key, both endpoints answer 403 `invalid_form_key`, also with `X-Requested-With` | Pass |
| session_lost_server_completes | Cookies cleared: the page gets `unknown_attempt` and learns nothing; the webhook creates the order | Pass |
| config_changed_after_start | Popup turned off after the start: the paid attempt is still finalized | Pass |
| redirect_mode_unchanged | Redirect mode: order-first, `purchase` → mock checkout without `mode=popup`, `-1 order_placed +1 aplazo_item_reserved`, webhook approval `-1 order_placed_aplazo`, no attempt, no window | Pass |
| popup_blocked_uses_redirect | Pop-ups blocked (no user activation, as in BNPL-1664's validation): the same redirect, no attempt | Pass |
| webview_uses_redirect | Instagram UA: the same redirect, no attempt, no window | Pass |
| last_unit_sold_elsewhere | One sellable unit; another buyer takes it (redirect, approved) while the popup is open; the popup payment → `rejected/out_of_stock`, no popup order | Pass on rerun (socket reset in the full run) (C5-blocked) |
| shipment_and_physical_stock | A completed order shipped: physical 1000 → 999, salable 969 → 968; reservations `-1 order_placed`, `+1 shipment_created` | Pass |
| double_start_two_tabs | Two starts at the same time from two tabs of one session (createLoan delayed 5 s): the same attempt for all, 1 createLoan (the session lock serializes them) | Pass |
| registered_customer_without_session | A logged-in customer; cookies cleared; the webhook answers `scheduled` (REST access control, not bypassed); the reconciliation creates the order as that customer, not as a guest | Pass |
| registered_customer_with_browser | A logged-in customer with the window present: `complete` creates the customer's order; success page | Pass |
| webview_rules | `webview.js` on 10 user agents (Facebook, Instagram, Twitter, Line/, Android wv, generic webview → redirect; Android Chrome, iPhone Safari, desktop Chrome, Linux desktop → popup) | Pass |

**C5-blocked** means the mock simulates the reversal, but the real behaviour of `cancelLoan` after a payment is not confirmed. These scenarios cannot be called ready for the real integration.

## Observations

- **Email:**
  - With sync sending and no mail transport configured locally, `OrderSender` leaves `send_email=1` and `email_sent` empty.
  - The attempts completed anyway, and `email_requested_at` was written once per attempt.
  - Magento's async retry (`sales_send_order_emails`) was not run: it would process every order of the local store.
- **Cart changes:** a change after the conversion gets Magento's core message "No such entity with cartId = …", not a message written for this flow.
- **Attempts left open:** five attempts of tests that never paid stay `open`. They would keep being checked at the ceiling interval, because no expiry is assumed (C3).
- **Demo environment:**
  - The demo environment that mounted BNPL-1665 was moved to this worktree for these tests.
  - The installed stub was replaced by this kit's.
  - A backup of the previous stub was kept outside the repository.

## Not validated (stated limits)

- **Without MSI:** needs another instance; compatibility is declared for MSI only until then.
- **Multistore:** a single local store. The store is always taken from the attempt.
- **Devices:** Android with and without the app, iPhone, Instagram and Facebook apps, a domain opened by an app (BNPL-1667).
- **STG:** the real API, real webhook delivery and retries, the `OUTSTANDING` vs `ACTIVO` status (C4), and `cancelLoan` after payment (C5).
- **Async email retry** in Magento (see Observations).
