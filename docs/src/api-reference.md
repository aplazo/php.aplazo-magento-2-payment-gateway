# API Reference

Two directions:

- **Outbound** — calls the module makes to Aplazo.
- **Inbound** — REST endpoints the store exposes for Aplazo to call.

## Base URLs

Selected by the **Modo de pruebas** (`sanbox_mode`) toggle:

| Purpose | Sandbox | Production | Resolver |
|---|---|---|---|
| Transactional API | `https://api.aplazo.net` | `https://api.aplazo.mx` | `Helper\Data::getServiceUrl()` |
| Core (tracking & logs) | `https://core.aplazo.net` | `https://core.aplazo.mx` | `Helper\Data::getTrackingBaseUrl()` |

## Outbound — Aplazo transactional API

All requests are made with `Magento\Framework\HTTP\Client\Curl` (connect timeout 10s,
timeout 90s) and carry these headers:

```
merchant_id:  <configured Merchant ID>
api_token:    <decrypted API token>
Content-Type: application/json
Authorization: <bearer>        # only when a bearer token applies
X-Idempotency-Key: <key>       # refunds only
```

Success is any of HTTP `200/201/202`; anything else raises a `LocalizedException`.

| Operation | Method & path | Caller | Notes |
|---|---|---|---|
| Authenticate | `POST /api/auth` | `getAuthorizationToken()` | Body `{ apiToken, merchantId }` → response `{ Authorization }`. Used to validate credentials. |
| Create loan | `POST /api/loan` | `createLoan()` | Returns `{ url }` = Aplazo hosted-checkout URL. |
| Loan status | `GET /api/pos/loan/{cartId}` | `getLoanStatus()` | Returns array of loans; status `OUTSTANDING` = paid. |
| Refund | `POST /api/pos/loan/refund` | `createRefund()` | Sends `X-Idempotency-Key`. Response `{ refundId, refundStatus }`. |
| Cancel loan | `POST /api/pos/loan/cancel` | `cancelLoan()` | Body includes `cartId`, `reason`. |

`refundStatus` values handled: `REQUESTED` (success), `REJECTED` (permanent failure),
anything else → retried. See [Refunds & RMA](refunds.md).

## Outbound — Core (tracking & logs)

### Analytics events

```
POST {coreBaseUrl}/api/v1/tracking/events
Content-Type: application/json
```

Fire-and-forget (connect 2s / timeout 5s); failures never block commerce. Payload
envelope:

```json
{
  "eventId": "<uuid v4>",
  "eventName": "order_paid",
  "occurredAt": "2026-01-01T00:00:00Z",
  "source": "MGT",
  "environment": "prod",
  "schemaVersion": 1,
  "eventVersion": 1,
  "properties": { "merchantId": "...", "orderIncrementId": "...", "...": "..." }
}
```

Event names (kept stable — they are an analytics contract):
`plugin_installed`, `plugin_uninstalled`, `order_created`, `order_paid`,
`refund_created`.

### Remote logs

```
POST {coreBaseUrl}/api/v1/ps/logs
Content-Type: application/json
```

Structured logs with `platform: "magento"`, `plugin: "aplazo-payment-gateway"`,
`merchant_id`, `environment`, `level`, `message`, `request_id` (correlates a whole
request), `tags`, and `attributes`. Also fire-and-forget. See
[Observability](observability.md).

## Inbound — REST endpoints exposed (`etc/webapi.xml`)

| Method & path | Auth (resource) | Service |
|---|---|---|
| `POST /V1/aplazo/callback/` | `anonymous` (+ JWT) | `NotificationsInterface::notify` |
| `POST /V1/aplazo/checkout-not-paid` | `Magento_Sales::cancel` | `CheckoutNotPaidManagementInterface::postCheckoutNotPaid` |

Full details in [Webhook & Callbacks](webhooks.md).

## Frontend controller routes

Route front name `aplazo` (`etc/frontend/routes.xml`), admin route `aplazo`
(`etc/adminhtml/routes.xml`).

| URL | Operation | Purpose |
|---|---|---|
| `aplazo/order/operations?operation=purchase` | `purchase` | Redirect to Aplazo checkout |
| `aplazo/order/operations?operation=cancel` | `cancel` | Handle abandoned checkout (token-guarded) |
| `aplazo/order/operations?operation=redirect_to_onepage` | `redirectToOnepage` | Success/failure redirect |
