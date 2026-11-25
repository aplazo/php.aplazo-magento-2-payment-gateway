# BNPL-1664 · Manual case 2 (local Magento → Aplazo STG): full payment after R-10

**Summary without personal data.** Screen recording and screenshots stay on the tester's machine.

- **Date:** 2026-11-25, 12:39–12:40 CST (18:39–18:40 UTC).
- **Code:** PR #133 head `30c95f5` (production code `3cb604e`, R-10 included), mounted in the local docker-magento 2.4.8 store.
- **Environment:** `LocalDev_AplazoStub` disabled; the plugin's real `ApiService`; `sanbox_mode=1` → API
  `https://api.aplazo.net`, checkout `https://checkout.aplazo.net` (STG); merchant 37; popup on (website 1). Kit mock
  stopped, local cron off, no tunnel (no webhook can arrive). No kit run during the purchase.
- **Who did what:** the tester bought one unit of `APLAZO-TEST-001` (MX$1,500 + MX$99 shipping) as a guest and paid in
  Aplazo's window. Everything below was read afterwards, read-only.

## Result (Magento)

| Check | Result |
|---|---|
| Attempts | 1: entity 523, `cartId 000000660`, `loan_id 396595`, `completed`, MX$1,599.00, `stg`, merchant 37, `check_count 0`, no error |
| `createLoan` calls | 1 (one `POST aplazo/popup/start`, 18:39:52 UTC) |
| Orders for that quote | 1: `000000660`, `processing`, `aplazo_gateway`, paid MX$1,599.00, `aplazo_checkout_mode=popup`, `aplazo_payment_id=396595`, the attempt's public id in the payment |
| Order comment | "payment confirmed through the loan status API before the order was created (popup)" |
| Invoices | 1, MX$1,599.00 |
| Quote | inactive, reserved order id `000000660` |
| Inventory reservations | 1: `-1 order_placed 000000660` |
| Browser sequence (nginx) | `start` → Aplazo sends the window to `aplazo/popup/landing/attempt/<public id>` (18:40:25) → one `complete` (18:40:27) → success page |

`paid_confirmed_at` 18:40:25, order created and invoiced 18:40:26: the decision came from the server's status query
inside `complete`; no webhook, no reconciliation.

## Aplazo's status API, read once afterwards (read-only, `GET /api/pos/loan/000000660`)

| loanId | status | In Magento |
|---|---|---|
| 396595 | OUTSTANDING | the attempt's loan and the order's payment |
| 236508 | CANCELLED | no |
| 144586 | CANCELLED | no |
| 41723 | CANCELLED | no |

Same shape as case 1: one cartId, four loans under merchant 37, each entry with its `loanId`. The module confirmed the
payment on its own loan id, matched exactly (R-10). This case had no foreign OUTSTANDING loan, so the refusal of a
foreign paid loan stays verified with the stub only (`r10_*`).
