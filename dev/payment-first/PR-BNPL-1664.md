<!-- Draft PR description for BNPL-1664 (not opened yet). The repo has no PR template; format of the merged PRs (#132).
     Publish only after the review of the open decisions below. -->

## BNPL-1664 — Popup checkout, payment-first (sandbox pilot)

Opt-in popup mode for `aplazo_gateway` in which **the order is created only after Aplazo confirms the payment** (PayPal-style payment-first). Closing the Aplazo window without paying leaves the cart intact and creates no order. Redirect stays the default and is unchanged. Popup is effective only in sandbox; production stays blocked in this release.

Replaces the order-first approach of #131 (to be closed with this PR).

Jira: [BNPL-1664](https://aplazo.atlassian.net/browse/BNPL-1664) · parent [BNPL-1423](https://aplazo.atlassian.net/browse/BNPL-1423)

**Tested version:** production code `cd64d09` (4.0.14, master 4.0.13 merged), identical in the PR head (later commits only touch `dev/`). Kit `b9f9410` (full run) and `64cbe2c` (reruns). Details: `dev/payment-first/VALIDATION-BNPL-1664-payment-first.md`, round 4.

### How it works
1. **Click** on "Pagar con Aplazo": checkout validated as today; the Aplazo window opens inside the click (blocked → today's redirect); payment data saved **without** placing the order.
2. **`aplazo/popup/start`**: reserves the order number on the quote, records an attempt (`aplazo_payment_attempt`) and creates the loan from the quote. No order, no stock movement.
3. **`aplazo/popup/complete`** (page signals) and the **webhook**: confirm with the loan status API (a browser message or the webhook body is never proof), then convert the quote into the order and approve it as today. One order per quote: attempt lock + `CartMutex` + quote row lock.
4. **Reconciliation cron** finishes paid attempts nobody finished. `bin/magento aplazo:payment-first:attempts` lists, reconciles and records resolutions.

Design and contracts: `dev/payment-first/DESIGN-BNPL-1664-payment-first.md`. Review findings H-1..H-13: `FINDINGS-BNPL-1664-review.md`.

### Mike's review (2026-11-20)
Details and evidence: `dev/payment-first/REVIEW-BNPL-1664-mike-2026-11-20.md`.

| # | Request | Result |
|---|---|---|
| 1 | Layer over the checkout while the window is open (focus link, ×) | Done |
| 2 | "Confirming your payment…" loader | Done |
| 3 | Retries after the window closes | Done: 1 + 8 checks every 3 s. Only a final `not_paid` gives the checkout back; pending, network errors and unknown answers keep it blocked with "Check again"; `rejected`/`review` keep it blocked. **Open decision:** a loan reported `not_paid` can still be paid later (REVIEW §1a) |
| 4 | Empty minicart after success | Done (`sections.xml` + `customerData.invalidate`) |
| 5 | One config-only switch | Done, split: `isPaymentFirstEnabled()` for new flows; `isPaymentFirstInUse()` keeps cron, webhook and the H-1 guard running for a store that started attempts. **Deviation:** that store keeps one indexed read per cart save after turning popup off (REVIEW §2) |
| 6 | `KeepConvertedQuoteClosed` lock only for attempts with order | **Differs:** locks for any attempt. "With order" is invisible during the conversion and reopens H-1 (reproduced, REVIEW §3) |
| 7 | No new Composer dependencies | Done (`parse_url()` instead of laminas-uri) |
| 8 | Works without MSI | **Not approved:** `setup:di:compile` fails without MSI on this branch **and on master** (pre-existing `Model/Product/MSIStock.php`, MG-763). `dev/payment-first/DEFECT-no-msi-compile-4.0.13.md` |
| 9 | Whitelist, master, version | Done: `etc/db_schema_whitelist.json`, master merged, 4.0.14 |

### Cost for stores without popup (MySQL profiler, `query_probe.php`)
| Store | Cart save | Cancel order (check/money order, Aplazo redirect) |
|---|---|---|
| Never used popup | no module query | no module query |
| Used popup, now off | + 1 indexed read on `aplazo_payment_attempt` | no module query |

### Testing (local Magento 2.4.8 + MSI, Aplazo API stubbed, Aplazo window mocked; no STG)
- `kit_guard.php` ran before every suite: stub enabled, the plugin's API/log/tracking services stubbed, local store URL.
- QA selection (`qa_selection.py`): 6/6.
- UX in a real browser (`ux_e2e.py`): 5/5 — layer, ×, loader, late payment → success with empty minicart, closed unpaid, closed uncertain.
- Renderer cycles (`js_cycle_test.py`): 5 new/changed scenarios pass; scenarios 1–7 match the pre-review renderer.
- H-1 focused (`h1_inflight_current` / `h1_inflight_order_only`), popup off with existing attempts (`switch_off_webhook`, `switch_off_cron`): pass.
- Full kit (`pf_e2e.py all`, 106 entries, 107 records): 52 assertions pass; 4 failed and 1 was interrupted — all 5 pass on separate reruns after updating 4 stale kit expectations and fixing 1 stock-index setup issue. 50 records are observational (no pass/fail criterion): read and summarized in REVIEW §7, not counted as passed.
- Unit tests from master (`Test/Unit`): 15/15.
- No-MSI compile (isolated container): fails on this branch, on `master` and on `b2f1ccb`, all in the pre-existing `MSIStock.php`.

### Open before merge
- **Mike:** close-without-payment decision (REVIEW §1a), the extra read after turning popup off (REVIEW §2), H-1 deviation (REVIEW §3).
- **Manual:** STG purchases and the magento-test run with 4.0.14; on magento-test, attempts from `b2f1ccb` have no `payment_first_in_use` flag (REVIEW §2).
- **Aplazo contracts** C1–C8 (design §9), in particular C3 (checkout expiry) and C5 (cancel-if-unpaid).
- No-MSI compile: pre-existing defect, to be reported separately.
- Mobile browsers (BNPL-1667), Universal Checkout (BNPL-1666).

🤖 Generated with [Claude Code](https://claude.com/claude-code)
