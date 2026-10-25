# Troubleshooting

Enable verbose logging first: **Activar logs = 2 (VVV)** and watch
`var/log/aplazo_payments.log`. Remote logs (correlated by `request_id`) are also
available to the platform team via Aplazo Core.

## The Aplazo method doesn't show at checkout

Check, in order:

1. **Activar** = Yes (`payment/aplazo_gateway/active`).
2. **Credentials valid** — `CredentialsValidator::areCredentialsValid()` requires both
   a successful `POST /api/auth` **and** store currency `MXN`. The **Estado de
   credenciales** and **Moneda configurada** badges show this in admin.
3. Cache flushed after changing config (`bin/magento cache:flush`).
4. Correct **Modo de pruebas** for the credentials you entered (sandbox creds won't
   auth against production and vice-versa).

## "No token returned" / authentication errors

- Wrong Merchant ID / API token, or wrong environment.
- The token is stored **encrypted**; if the Magento `crypt` key changed, re-enter the
  token in admin to re-encrypt it.

## Order stays `pending`, never confirmed

The webhook probably didn't reach the store or failed JWT validation.

1. Confirm Aplazo is calling `POST {base}/rest/default/V1/aplazo/callback`.
2. **JWT validation** uses the **API token** as the HS512 key. A token mismatch
   between admin config and what Aplazo signs with → `JWT Validation error` in the log.
3. Check for varnish/full-page-cache in front of the REST endpoint.
4. **Safety net:** the 15-min cancel-orders cron will still recover the order if the
   loan is `OUTSTANDING` in Aplazo — verify Magento cron is running.

## Order was cancelled but the customer actually paid

- The cancel cron checks loan status before cancelling; a paid loan should be recovered
  (`OUTSTANDING`). If it was cancelled anyway, look for `module:cron` logs around that
  increment id — likely the loan-status call failed/timed out.
- Orders that can't be cancelled cleanly are moved to `aplazo_order_canceled` to stop
  endless cron retries.

## Refund not reaching Aplazo

1. Is **Habilitar reembolsos** (or **…por RMA**) enabled?
2. Inspect the **Aplazo refund queue** grid / `aplazo_refund_request` table:
   - `status = pending` with a future `next_attempt_at` → waiting on backoff.
   - `status = failed` with `last_error` → inspect the error; `REJECTED` means Aplazo
     declined it.
3. Force a run: `php bin/magento aplazo:refund:process`.
4. Duplicate refunds are impossible — the `(type, idempotency_key)` unique constraint +
   `X-Idempotency-Key` header enforce at-most-once.

## Cart not recovered after abandon

- Requires **cancel_active** and **recover_active** enabled.
- Recovery rebuilds a quote from the order's visible items; items missing
  `info_buyRequest` options or deleted products are skipped and logged
  (`Cart recovery failed: no recoverable items`).

## Widgets not appearing on product/cart pages

- `show_on_product_page` / `show_on_cart` must be enabled, and the module active with
  valid credentials — the JS/widget loads only when the method is usable.

## Useful commands

```bash
php bin/magento module:status Aplazo_AplazoPayment
php bin/magento aplazo:orders:cancel     # run cancel-orders logic now
php bin/magento aplazo:refund:process    # drain refund queue now
tail -f var/log/aplazo_payments.log
tail -f var/log/aplazo_payment/info.log  # health check output
```
