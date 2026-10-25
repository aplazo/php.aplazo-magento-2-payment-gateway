# Data Model

Declarative schema lives in `etc/db_schema.xml`; custom order statuses and attributes
are added by data patches in `Setup/Patch/Data`.

## `sales_order` (extended)

| Column | Type | Purpose |
|---|---|---|
| `aplazo_checkout_url` | `varchar(255)` | Aplazo hosted-checkout URL, stored as `"<url>||<cancel token>"`. The `purchase` action uses the URL; the `cancel` action requires the token. |

## `aplazo_refund_request` (queue)

Queue of pending/failed refunds and RMA refunds.

| Column | Type | Notes |
|---|---|---|
| `entity_id` | int, PK, identity | |
| `type` | varchar(32) | `refund` or `rma` |
| `status` | varchar(16) | `pending` (default), `processing`, `success`, `failed` |
| `order_increment_id` | varchar(32) | |
| `order_id` | int, nullable | Order entity id |
| `creditmemo_id` | int, nullable | |
| `rma_entity_id`, `rma_item_id`, `order_item_id` | int, nullable | RMA linkage |
| `qty` | decimal(12,4), nullable | Qty to refund (RMA) |
| `amount_cents` | int | Refund amount in cents |
| `currency` | varchar(8), nullable | |
| `reason`, `reason_hash`, `items_hash` | text / varchar(64) | Dedup/audit helpers |
| `idempotency_key` | varchar(64) | Sent as `X-Idempotency-Key` |
| `payload_json` | text | Request payload sent to Aplazo |
| `response_json` | text, nullable | Aplazo response |
| `aplazo_refund_id`, `aplazo_refund_status` | varchar | From the Aplazo response |
| `retries` | int, default 0 | |
| `last_error` | text, nullable | |
| `next_attempt_at` | timestamp, nullable | Backoff scheduling |
| `created_at` / `updated_at` | timestamp | Auto-managed |

**Constraints & indexes**

- Unique `(type, idempotency_key)` — enforces at-most-once submission.
- Index `(status, next_attempt_at)` — drives the queue selection query.

## Custom order statuses (data patches)

| Status code | Added by | Meaning |
|---|---|---|
| `aplazo_webhook_received` | `AddAplazoWebhookReceivedStatus` | Webhook received; invoice pending (finalized by cron). |
| `aplazo_order_canceled` | `AddAplazoOrderCanceled` | Order that could not be cancelled cleanly; prevents endless cron re-processing. |

## RMA item attribute

| Attribute | Added by | Purpose |
|---|---|---|
| `qty_aplazo_refunded` | `AddQtyAplazoRefundedItemAttribute` | Tracks how much of an RMA item has already been refunded through Aplazo (Adobe Commerce only). |

## Install/uninstall side effects

- `SendModuleInstalledTrackingEvent` (data patch) → emits `plugin_installed`.
- `Setup\Uninstall` → emits `plugin_uninstalled`.
