# Finding: the webhook's `status` is not covered by its signature

Recorded on 2026-10-28 for BNPL-1664. This is a code reading, not a demonstrated exploit. It is kept apart from the payment-first design; fixing the redirect flow is outside this ticket.

## Sources

- **merchant-partners-ms:** `aplazo/java.aplazo-merchant-partners-ms`, `master` at `c209533ce58c`. File: `merchantPartners-ms-service/src/main/java/mx/aplazo/microservices/merchant/partners/service/impl/MerchantCallbackServiceImpl.java` (last change to the file: `022ec5bad472`, 2024-10-22).
- **This plugin:** `aplazo/php.aplazo-magento-2-payment-gateway`, `master` at `d88eb80`, `Model/Notifications.php`.

What runs in Aplazo's environments was not checked; merchant-partners was read on its default branch.

## Path

1. **Two callbacks are built** (merchant-partners l. 92–93). `callbackNew` carries the Spanish name of `LoanStatus.NEW`; `callbackConfirm` carries the Spanish name of `OUTSTANDING`, received by the plugin as `"Activo"`.
2. **The NEW callback** (l. 131–136): on the first delivery attempt it is sent to the merchant's `webHookUrl`, signed with `makeSecurityToken(merchantResponse, callbackNew)`.
3. **The confirmation callback** (l. 140–146): it is signed **with `callbackNew` again**, not with its own data. Its body is `callbackConfirm`.
4. **JWT claims** (`makeSecurityToken`, l. 180–196):
   - `sub`, `status`, `loanId`, `cartId` and `merchantId`, taken from the object it is given;
   - 30-minute expiry;
   - HS512 with the merchant's apiToken.
   So the JWT's `status` is NEW's, not the payment confirmation.
5. **The plugin** (`master`, `Model/Notifications.php`):
   - validates the JWT (`validateJWT`);
   - reads the `cartId` from the JWT;
   - approves on the **body's** `$status` parameter (`if ($status == 'Activo')`, l. 79). The body is not signed.

## Consequence for the redirect flow (not demonstrated)

A request that reuses a valid JWT for a `cartId`, with `"status": "Activo"` in the body, would pass the validation and trigger the approval.

That would need:
- a token from a real callback while it is still valid (30 min);
- in practice, access to the traffic to the store, or to logs that keep it.

No exploitation was attempted. It is not claimed to be exploitable in production.

## Handling in payment-first (BNPL-1664)

- The webhook keeps the same authentication (`validateJWT`), and is used only as a signal.
- Neither the body's `status` nor the JWT's is read as payment. `Model/PaymentFirst/WebhookHandler` checks that the signed `merchantId` and `loanId` claims match the attempt, then calls the finalizer.
- The finalizer confirms the payment with Aplazo's status API (`Model/PaymentFirst/LoanStatusChecker`), with the attempt store's credentials, before creating any order.

## Open (outside BNPL-1664)

- Ask the merchant-partners owners whether signing the confirmation with `callbackNew` is intended, and what is deployed.
- Decide, as its own ticket agreed with the squad, whether the redirect flow should also confirm through the API before approving.
