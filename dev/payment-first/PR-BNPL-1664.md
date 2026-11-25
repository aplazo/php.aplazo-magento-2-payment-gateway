<!-- PR #133 description (draft). The repo has no PR template; format of the merged PRs (#132). Source of the body. -->

## BNPL-1664 — Popup checkout, payment-first (sandbox pilot)

Opt-in popup mode for `aplazo_gateway` in which **the order is created only after Aplazo confirms the payment** (PayPal-style payment-first). Closing the Aplazo window without paying leaves the cart intact and creates no order. Redirect stays the default and is unchanged. Popup is effective only in sandbox; production stays blocked in this release.

Replaces the order-first approach of #131 (to be closed when this PR is ready, not before).

Jira: [BNPL-1664](https://aplazo.atlassian.net/browse/BNPL-1664) · parent [BNPL-1423](https://aplazo.atlassian.net/browse/BNPL-1423)

**Draft: not ready for merge.** Release blockers are listed at the end. Mike is on vacation; the squad reviewed it on 2026-11-24 (`dev/payment-first/REVIEW-BNPL-1664-mike-2026-11-20.md` §8).

**Tested version:** production code `ef63164` (4.0.14) for the round-5 checks below; the PR head differs from it only in `dev/` (kit `51b7f31`, docs). Rounds 1–4 (`cd64d09` and earlier): `dev/payment-first/VALIDATION-BNPL-1664-payment-first.md`. Round 5 (2026-11-24, after the squad review): REVIEW §8.

### How it works
1. **Click** on "Pagar con Aplazo": checkout validated as today; the Aplazo window opens inside the click (blocked → today's redirect); payment data saved **without** placing the order.
2. **`aplazo/popup/start`**: records an attempt (`aplazo_payment_attempt`) with its own order number and creates the loan from the quote. No order, no stock movement.
3. **`aplazo/popup/complete`** (page signals) and the **webhook**: confirm with the loan status API (a browser message or the webhook body is never proof), then convert the quote into the order and approve it as today. One order per quote: attempt lock + cart lock + quote row lock.
4. **Any other order for that quote** (another method, redirect, reload, another tab): its earlier popup attempts are checked with Aplazo first; a paid one becomes the order and the other order is refused.
5. **Reconciliation cron** finishes paid attempts nobody finished. `bin/magento aplazo:payment-first:attempts` lists, reconciles and records resolutions.

Design and contracts: `dev/payment-first/DESIGN-BNPL-1664-payment-first.md`. Earlier findings H-1..H-13: `FINDINGS-BNPL-1664-review.md`.

### Review of 2026-11-24 (squad, in Mike's absence)
| # | Finding | Result |
|---|---|---|
| R-1 | A loan **already paid** was not checked before an order with another method (reload / other tab / other method → charged twice). Also a paid quote whose selected method had changed could never become its order | **Fixed**: `SettlePaidAttemptsBeforePlaceOrder` + `Finalizer` no longer refuses for the selected method |
| R-2 | `CartMutexInterface` exists only from Magento 2.4.8: compile and the webhook of every order (redirect too) would break on ≤ 2.4.7 | **Fixed**: own `CartLock` (same `cart_lock_<id>` as core). Not compiled on a real 2.4.7 store (pending) |
| R-3 | "Not paid" concluded from an answer about another loan id | **Fixed** (`UNKNOWN`), unit test |
| R-4 | Failed deferred stock salability publish left the stock index wrong | **Fixed**: MSI's own handler applied in process (idempotent recalculation) |
| R-5 | Failed deferred coupon usage publish: rule statistic one short | **Not retried on purpose**: the consumer increments; a retry after an uncertain publish could count twice. No limit reads it |
| R-6 | An `uncertain` start blocks popup for that cart for good (other methods still work) | Pending; needs C2 or an operator resolution. Blocker for production enablement |
| R-7 | Reconciliation backlog grows without bound (unpaid attempts polled every 3 h) | Pending; needs C3. Blocker for production enablement |
| R-8 | Order email lost if the process dies between `completed` and the email claim | Pending (low) |
| R-9 | Semgrep: 13 findings, all in `dev/` (export-ignore, never packaged) | Marked `nosemgrep` with reasons |

### Mike's review (2026-11-20)
| # | Request | Result |
|---|---|---|
| 1 | Layer over the checkout while the window is open (focus link, ×) | Done |
| 2 | "Confirming your payment…" loader | Done |
| 3 | Retries after the window closes | Done: 1 + 8 checks every 3 s. Only a final `not_paid` gives the checkout back; pending, network errors and unknown answers keep it blocked with "Check again". Server side, any later order first settles the earlier attempts (R-1). **Late payment after another order: release blocker** (below) |
| 4 | Empty minicart after success | Done (`sections.xml` + `customerData.invalidate`) |
| 5 | One config-only switch | Done, split: `isPaymentFirstEnabled()` for new flows; `isPaymentFirstInUse()` keeps cron, webhook, the H-1 guard and the placeOrder check running for a store that started attempts. **Deviation, not compliance:** a site that used popup keeps one indexed read per cart save and per placeOrder after turning popup off (REVIEW §2) |
| 6 | `KeepConvertedQuoteClosed` lock only for attempts with order | **Differs:** locks for any attempt. "With order" is invisible during the conversion and reopens H-1 (reproduced, REVIEW §3) |
| 7 | No new Composer dependencies | Done (`parse_url()` instead of laminas-uri) |
| 8 | Works without MSI | **Not approved:** `setup:di:compile` fails without MSI on this branch **and on master** (pre-existing `Model/Product/MSIStock.php`, MG-763). `dev/payment-first/DEFECT-no-msi-compile-4.0.13.md`. This PR adds no MSI type to any constructor |
| 9 | Whitelist, master, version | Done: `etc/db_schema_whitelist.json`, master merged, 4.0.14. Data patch `MarkPaymentFirstInUseStores` for attempts created before 4.0.14 |

### Cost for sites without popup (MySQL profiler, `query_probe.php`)
| Site | Cart save | placeOrder | Cancel order |
|---|---|---|---|
| No store ever used popup | no module query | no module query | no module query |
| A store used popup, now off | + 1 indexed read on `aplazo_payment_attempt` | + 1 indexed read; a status request only per payable attempt of that quote | no module query |

### Testing — passed (with assertions)
All local: Magento 2.4.8 + MSI, Aplazo API stubbed, Aplazo window mocked, `kit_guard.php` before every suite (stub enabled, the plugin's API/log/tracking services stubbed, local store URL). No STG.
Round 5, on `ef63164` (focused on what changed, not the full suite):
- New placeOrder check (`pf_e2e.py`): `place_other_paid`, `place_other_unpaid`, `place_other_unknown`, `place_other_busy`, `place_other_busy_checkout`: 5/5.
- Regression of the paths the cart lock, the check and the status change touch: `close_unpaid_and_reuse`, `paid_success_message_once`, `paid_window_closed_before_message`, `concurrent_webhook_first`, `concurrent_complete_first`, `crash_at_order_saved_then_reconcile`, `crash_at_approve_before_then_reconcile`, `double_start_two_tabs`, `two_attempts_both_paid_old_first`, `two_attempts_both_paid_new_first`, `popup_pending_redirect_other_tab`, `status_unknown_then_paid`, `ownership_and_form_key`, `h1_inflight_current`, `h1_inflight_order_only`, `switch_off_webhook`, `switch_off_cron`, `redirect_mode_unchanged`, `popup_blocked_uses_redirect`: 19/19.
- Salability fallback with Magento's consumer (`RABBITMQ_CONTAINER` set): `msi_fix_publish_exception`, `msi_fix_publish_error`: 2/2 (index `is_salable` 0 right after the failed publish; it stayed 1 before).
- Unit tests (`Test/Unit`): 24/24 (15 from master + 9 `LoanStatusCheckerTest`; "only another loan id" returned NOT_PAID before the fix). 1 runner warning (Allure config missing).
- Query profile (`query_probe.php`, MySQL profiler): no store ever used popup → cart save and placeOrder issue no query on `aplazo_payment_attempt` (43 and 92 queries); store in use → 1 indexed read each (44 and 95). Cancel unchanged (21 / 24).
- `php -l` on the 72 changed PHP files: clean.

Rounds 1–4 (`cd64d09`), not repeated: QA selection 6/6; UX in a real browser 5/5; renderer cycles; full kit 52 assertions passed (4 failed + 1 interrupted, all 5 pass on reruns); data patch validated (only stores with attempts in progress, idempotent).

### Observational (evidence recorded, no pass/fail criterion — not counted as passed)
- 50 records of the round-4 full run (inventory, coupons, email, uncertain start, order link, approval): REVIEW §7.
- Round 5: `uncertain_start_uncertain_reclick`, `uncertain_start_normal_and_reuse` (one `createLoan` per cart with the new cart lock, as before); `msi_fix_success_then_consume`, `msi_fix_redirect` (unchanged from round 4).
- Coupon publish failure (`cf_*_publish_*`, round 4, REVIEW §7b): order correct, coupon and per-customer limits correct, rule-level statistic one short.

### Release blockers (the PR stays draft while any is open)
- **Late payment of an earlier loan after another order (open risk, not accepted).** A loan reported `not_paid` stays payable: neither the plugin nor any confirmed contract makes it unpayable (REVIEW §1a). The placeOrder check covers "paid before the other order", not "paid after". Then the buyer is charged twice; the attempt ends `rejected` and the refund is manual. Asking the buyer or waiting longer only lowers the chance. Needs Aplazo to confirm what the existing `cancelLoan` (`/api/pos/loan/cancel`, already used by master's redirect cron on unpaid loans) does on an unpaid and on a paid loan (C5) and checkout validity (C3), then a product decision.
- **No MSI:** pre-existing compile failure (`DEFECT-no-msi-compile-4.0.13.md`), separate ticket.
- **Manual:** STG purchases and the magento-test run of this head (the data patch marks magento-test's older attempts on `setup:upgrade`).
- **Magento 2.4.7:** compile this head on a 2.4.7 store (R-2 fixed by code, not run).

### Not run
Magento's cron scheduler (`cron:run`), email delivery (no mail transport), mobile browsers (BNPL-1667), Universal Checkout (BNPL-1666), PayPal Express (calls `submit` directly, not covered by the placeOrder check), the real Aplazo contracts C1–C8.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
