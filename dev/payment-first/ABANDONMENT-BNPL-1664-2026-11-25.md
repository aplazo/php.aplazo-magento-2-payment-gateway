# BNPL-1664 · Abandonment, recovery and communication failures (local kit, 2026-11-25)

- **Code:** production code of `3cb604e` (PR #133 head `d8ae867` when run; only `dev/` differs).
- **Environment:** local docker-magento 2.4.8 + MSI. `LocalDev_AplazoStub` enabled for the run, `kit_guard.php` passed
  before every suite (ApiService, LogService and TrackingService resolve to the stub; store host `magento.test`);
  Aplazo's window served by `mock_https.py` on 127.0.0.1:8443 (only the kit's headless Chrome maps
  `checkout.aplazo.net` there). **No call to Aplazo STG.** Each case runs in its own browser profile with its own cart.
- **Runs:** new cases 12:51–13:08 CST; restart cases rerun 13:16–13:19 (after a harness fix, below); selected existing
  cases 13:19–13:26. Logs: `dev/popup-harness/.out/magento-e2e/abandon-*.log`, records in `pf.jsonl` (local only).
- **After the run:** stub disabled again (store → STG), mock stopped, no cron, no kit Chrome left.

New kit cases in `pf_e2e.py`: `ab_restart_*` (A), `ab_wait_*` (B), `ab_close_paid_*` (C), `ab_reopen_*` (E),
`ab_success_then_close` (F), `ab_net_*` (G). `Browser` accepts a kept `profile` and `stop(graceful)`.

## Results

| Case | Simulation | Expected | Observed | Status |
|---|---|---|---|---|
| A1 normal close, before Aplazo | checkout filled, Chrome quit with DevTools `Browser.close`, reopened on the same profile | cart kept, no attempt, no loan; purchase continues on the same quote | cart kept (same quote), 0 attempts and 0 `createLoan` before and after; then one order `000000676`, 1 invoice, 1 reservation | Passed |
| A1 crash, before Aplazo | same, SIGKILL right after filling | no attempt/loan; cart depends on what Chrome had written | 0 cookies restored, cart gone; 0 attempts, 0 loans | No premature attempt: passed. Cart loss: **simulation limit / finding to investigate** (cause not proven; see below) |
| A2 normal close, attempt open, unpaid | Aplazo opened (attempt 540), Chrome quit, reopened, Aplazo clicked again, paid | cart kept, same attempt and loan reused, one order | same attempt, 1 `createLoan`, same URL loaded once more, order `000000677`, 1 invoice, 1 reservation | Passed; while Chrome was closed the buyer could have paid another way: the earlier loan stays payable while the buyer can pay another way (late payment, release blocker) |
| A2 crash, attempt open, unpaid | SIGKILL after opening Aplazo (attempt 541) | nothing created; attempt stays under reconciliation | session lost, cart gone for the buyer; attempt 541 `open`, quote active, no order | Nothing premature created; cart loss: **simulation limit / finding to investigate**; **Passed with residual risk**: the earlier loan stays payable while the buyer can pay another way (late payment, release blocker) |
| A3 paid while closed, webhook arrives | Chrome quit, loan paid, signed webhook (kit) | order before the buyer returns, empty cart | order `000000679` while closed; cart empty on return; 1 invoice, 1 reservation | Passed |
| A3 paid while closed, no signal | Chrome quit, loan paid, no webhook/cron; buyer returns | one order on return | cart still there; the Aplazo click **reuses the paid attempt and opens Aplazo's window again**; the close check creates order `000000680` | Passed (server); what Aplazo shows for an already paid loan's URL is unknown → manual STG |
| A3 paid while closed, crash | SIGKILL, loan paid, no signal | order by the reconciliation | session lost; reconciliation (as the cron) completed attempt 544 → order `000000681`, 1 invoice | Order: passed (the buyer only learns by email); cart loss: **simulation limit / finding to investigate** |
| B wait | window open 45 s (> the page's whole 1+8×3 s check cycle) then paid | nothing created or asked while waiting; one loan | 0 status calls, 1 `createLoan`, 0 orders during the wait; then order `000000665` | Passed |
| B expired loan | stub lists the loan `CANCELLED` (stands for expired/cancelled; STG's real expiry is contract C3) | checkout given back; a new Aplazo click must not reopen the dead loan | checkout given back; attempt 529 stays `open`; the next click **reuses it and loads the same loan URL again**, no new loan | **Defect reproduced** |
| B unknown status | `status_unknown_then_paid` (stub ERROR, then EMPTY, then paid) | blocked, never "not paid"; completes once paid | blocked with "could not confirm"; reconciliation `unknown` twice, then `completed` | Passed |
| C unpaid | `close_unpaid_and_reuse` | cart back, same loan reused | as expected (attempt 545) | **Passed with residual risk**: the earlier loan stays payable while the buyer can pay another way (late payment, release blocker) |
| C still processing → paid during the checks | closed with REQUEST, paid after 3 status calls | page finds it, one order | success page, order `000000667` | Passed |
| C still processing → paid after the checks | checks end `not_paid`, checkout given back, then paid; reconciliation | one order | buyer told "not paid, cart available"; later reconciliation creates order `000000668`, cart converted | Consistent with the real state; **Passed with residual risk**: the earlier loan stays payable while the buyer can pay another way (late payment, release blocker) (the page said "not paid" while the loan was still payable) |
| C paid before close | `paid_window_closed_before_message` | one order | order `000000683` | Passed |
| D unpaid → other method | `place_other_unpaid` | other order placed; old loan stays payable | checkmo order `000000689`; the old loan paid later → attempt 551 `rejected` (`quote_not_active`), reversal pending | **Defect reproduced** (late payment = second charge, refund by an operator; release blocker) |
| D paid → other method | `place_other_paid` | other order refused, Aplazo order | refused ("already confirmed: order 000000687") | Passed |
| D status failed → other method | `place_other_unknown` | other order placed, attempt kept | checkmo order `000000691`; attempt 552 `open` (still checked) | **Passed with residual risk**: the earlier loan stays payable while the buyer can pay another way (late payment, release blocker) |
| D paid after the other order | covered by D unpaid's second step | — | as above | **Defect reproduced** |
| E same cart | `close_unpaid_and_reuse`; `uncertain_start_normal_and_reuse` (observational case, no kit assertion) | same attempt and loan | same attempt 553, same URL, 1 `createLoan` (read from the record) | Passed (evidence read by hand); while the checkout is given back the loan stays payable: residual risk as C unpaid |
| E changed quantity | closed, 2nd unit added, Aplazo again, paid; then the 1st loan paid late | new attempt/loan; old superseded; one order; old never a 2nd order | attempt 533 new, 532 `superseded`, 2 loans; order `000000670` for 2 units; 532 paid late → `rejected` | Order: passed; **defect reproduced** for the late payment of 532 (residual risk) |
| E cart changed while open | `cart_changed_amount` | no order, rejected | as expected | Passed |
| E double click / two tabs | two clicks in a row; `double_start_two_tabs` | 1 attempt, 1 loan, 1 window | 1/1/1 | Passed |
| E R-10 | `r10_own_unpaid_other_paid`, `r10_no_loan_foreign_paid` | never another loan of the cartId | as expected | Passed |
| F paid, closed before the message | `paid_window_closed_before_message` | one order via complete | yes | Passed |
| F success and close at once | mock posts success and closes in the same tick; complete repeated | one order, invoice, reservation | order `000000672`, 1 invoice, `-1` once; repeat answers `completed` | Passed |
| F concurrent complete/webhook | `concurrent_complete_first`, `paid_success_message_once` | one order | yes | Passed |
| G1 browser offline before complete | DevTools offline, window closed | no complete reaches Magento; blocked, never "not paid"; Check again recovers | 0 status calls, blocked "could not confirm"; online + Check again → order `000000673` | Passed |
| G2 response lost | DevTools `Fetch` fails the `complete` response after Magento answered 200 | order exists once; retry answers completed | order `000000674` already there when the answer was lost; the page's retry → success; still 1 order | Passed |
| G3 Magento cannot reach Aplazo | stub `getLoanStatus` → false (the plugin's own "communication failed") | unknown, blocked | as B unknown | Simulation limit: no real TCP/TLS failure or 90 s timeout of the real `ApiService` |
| G4 createLoan answer lost | stub `createLoan` throws after the "loan" exists | uncertain, no loan id; webhook held; operator attribution | as expected (`create_loan_fail…`) | Simulation limit: a loan really created and its answer lost cannot be produced locally |
| Session gone | `session_lost_server_completes` | page learns nothing; webhook completes | `unknown_attempt`; webhook → order `000000700` | Passed |

## Classification note (2026-11-25, after review)

A case that lets the buyer pay another way while the earlier loan stays payable is marked **passed with residual risk**
even when its assertions hold: the double charge on a late payment is a release blocker (contract question Q1 below,
REVIEW §8d). Cart loss after SIGKILL is a **simulation limit / finding to investigate**, not a pass: it was observed
with an immediate SIGKILL (~2 s after the last page activity), every Magento cookie was missing on restart, and the
cause was not isolated (Chrome's delayed cookie write is the likely explanation, not proven; SIGKILL after 30+ s and a
real desktop Chrome crash were not run). No conclusion about real buyers follows from it.

## Harness fix during the run (not a product change)

The first `ab_restart_*` run used SIGTERM as "normal close" 2 s after the last page activity: headless Chrome quits
without writing cookies changed in the last ~30 s, so every restart lost the session, and `ab_restart_before_*` passed
without the cart persisting (its criterion allowed it). Checked with a probe: `Browser.close` and SIGTERM after 35 s
both keep all 11 cookies. Normal close is now `Browser.close` and must keep the cart; crash stays SIGKILL. The three
cases that crashed (`click_place_order` found no button on an empty cart) and the two that passed for the wrong reason
were rerun.

## Defect: an expired or cancelled loan is offered again (B)

`StartService` reuses an `open` attempt with the same cart fingerprint without asking the loan's status
(`StartService.php`, the loop over `getForQuote(..., [OPEN])`). When Aplazo lists the loan as CANCELLED (or expired),
`complete` answers `not_paid`, the checkout is given back, and every later Aplazo click for the same cart opens that
dead loan again; a new loan is created only if the cart changes. Impact: the buyer cannot pay that cart with Aplazo
(they can change the cart or use another method); no money risk. Not fixed: the minimal fix (remember the `CANCELLED`
read by `complete`, supersede on the next click; no extra call) is safe only if `CANCELLED` is final. Not documented:
question Q2 in REVIEW §8d.

## Still manual (STG)

What Aplazo's checkout shows when its URL is reopened for a paid loan (A3) and for an expired one (B); real expiry time
and status name (C3); a real network failure between Magento and Aplazo (G3); a real lost createLoan answer (G4).
