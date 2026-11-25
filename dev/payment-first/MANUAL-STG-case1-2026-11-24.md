# BNPL-1664 · Manual case 1 (local Magento → Aplazo STG): full payment

**Summary without personal data.** The screenshots and the full network log stay on the tester's machine: they contain
what was typed at checkout. Nothing here identifies the buyer.

- **Date:** 2026-11-24, 22:36 CST (04:36 UTC on the 25th).
- **Code:** PR #133 head `aa5537e` (production code `ef63164`), mounted in the local docker-magento 2.4.8 store.
- **Environment:** `LocalDev_AplazoStub` disabled; the plugin's real `ApiService`; `sanbox_mode=1` → API
  `https://api.aplazo.net`, checkout `https://checkout.aplazo.net` (STG); merchant 37; popup on (website 1). Kit mock
  stopped. Local cron off (no `aplazo_*` runs). No tunnel: Aplazo's webhook cannot reach `magento.test`.
- **Who did what:** the tester bought one unit of the store's test product (`APLAZO-TEST-001`, MX$1,500 + MX$99
  shipping) as a guest and paid in Aplazo's window. Nothing was automated; nothing changed state before the evidence
  was read.

## Result (Magento, read-only queries after the purchase)

| Check | Result |
|---|---|
| Attempts created | 1: `cartId 000000635`, `loan_id 396107`, `completed`, MX$1,599.00, environment `stg` |
| `createLoan` calls | 1 (one `POST aplazo/popup/start` in nginx's log) |
| Orders for that quote / that number | 1 / 1: `000000635`, `processing`, `aplazo_gateway`, paid MX$1,599.00, `aplazo_checkout_mode=popup`, `aplazo_payment_id=396107`, the attempt's public id in the payment |
| Order comment | "payment confirmed through the loan status API before the order was created (popup)" |
| Invoices | 1, full amount |
| Quote | inactive (`is_active=0`), reserved order id `000000635` |
| Inventory reservations | 1: `-1 order_placed 000000635` (no plugin compensation, as designed for payment-first) |
| Success page | "Thank you for your purchase! Your order # is: 000000635" (screenshot) |
| Minicart | no counter after success; the cart section was reloaded after the success page (screenshot + request log) |

## How the payment was confirmed

| Time (CST) | Source | Event |
|---|---|---|
| 22:36:17 | browser | Place Order; Aplazo's window opens inside the click |
| 22:36:19 | browser → server | `POST aplazo/popup/start` (200): attempt + `createLoan`; the window loads Aplazo STG's checkout |
| 22:36:57 | browser | after the payment, Aplazo sends the window to the store's landing page; the window closes |
| 22:36:57 | browser → server | `POST aplazo/popup/complete` (one call). Inside it **the server** asks STG's loan status API; loan 396107 `OUTSTANDING` → `paid_confirmed_at` 22:36:58, order created and invoiced in the same second |
| 22:37:00 | browser | redirect to the success page |

The browser only signalled; the decision came from the server's status query. No webhook arrived (no tunnel) and no
reconciliation ran.

## Aplazo's status API, read once afterwards (read-only, `GET /api/pos/loan/000000635`)

| loanId | status | In Magento |
|---|---|---|
| 396107 | OUTSTANDING | the attempt's loan and the order's payment |
| 235354 | REQUEST | no |
| 144051 | CANCELLED | no |
| 26701 | OUTSTANDING | no |

The same cartId lists three older loans under merchant 37 (inference: other stores or tests sharing the merchant in
STG). Case 1 was not affected (the attempt had its loan id). It is finding **R-10** (REVIEW §8): an attempt without loan
id would have taken loan 26701 as its payment. Fixed after this case; see the PR.

## Visual capture: what it is and what is missing

- No video. Screenshots of the store's tab only (about 2 per second, on change), and a request log for all windows
  without headers, cookies, bodies or query strings. Aplazo's window was not captured visually on purpose.
- **Missing:** the overlay and the "Confirming your payment…" loader (22:36:20–22:37:00): Chrome sends no frames of a tab
  behind another window. The tester's screen recording is the evidence for those.
- Capture defects of this run, fixed in `dev/popup-harness/manual_capture.py`: the bodies of `start`/`complete` were
  not kept (path filter without the trailing slash); each request was logged three times (three sessions on one tab).
