# Observability

The module has three complementary signals: **local logs**, **remote logs**, and
**analytics events**.

## Local logs

`Helper\Data::log()` writes through a dedicated logger.

- Main file: `var/log/aplazo_payments.log`.
- Health check file: `var/log/aplazo_payment/info.log` (see `Logger\Handler\InfoHandler`).
- Verbosity is controlled by **Activar logs** (`debug_mode`):
  - `1` → normal logging.
  - `2` (`LOGS_VVV`) → very verbose (full request/response dumps, redirect traces).

## Remote logs (`LogService`)

Structured logs are shipped to Aplazo Core so the platform team can debug merchant
issues without shell access:

```
POST {coreBaseUrl}/api/v1/ps/logs
```

- Fire-and-forget (connect 2s / timeout 5s) — never blocks commerce flows.
- Correlation: a `request_id` (UUID v4) is generated per request and reused across all
  log lines of that request. `resetRequestId()` is called at the start of each webhook,
  cron, and cancel operation to start a fresh correlation id.
- Constant fields: `platform: "magento"`, `plugin: "aplazo-payment-gateway"`,
  `merchant_id`, `merchant_name`, `store_url`, `plugin_version`, `environment`
  (`staging`/`production`), `level`, `tags` (e.g. `module:webhook`, `module:refund`,
  `module:cron`, `module:checkout`, `module:cancel`, `module:http`).

### Log tags cheat-sheet

| Tag | Emitted from |
|---|---|
| `module:checkout` | Loan creation on order placement |
| `module:webhook` | Payment confirmation callback |
| `module:cancel` | Abandoned checkout / order cancel |
| `module:cron` | Cancel-orders cron & loan-status checks |
| `module:refund` | Refund queue processing |
| `module:http` | Low-level HTTP failures |

## Analytics events (`TrackingService`)

Business events sent to `POST {coreBaseUrl}/api/v1/tracking/events` with
`source = "MGT"`:

| Event | When |
|---|---|
| `plugin_installed` | Module installed (data patch) |
| `plugin_uninstalled` | Module uninstalled |
| `order_created` | Order placed with Aplazo |
| `order_paid` | Webhook confirms payment |
| `refund_created` | Refund created |

Events include `merchantId`, `shopName` (inferred from the store base URL), store/order
properties, `schemaVersion`, and `environment`. Like remote logs, they are
best-effort and never block the shopper.

## Health check

Enabling **Check Healthy Site** (`check_healthy_site`) and saving the payment config
fires the `admin_system_config_changed_section_payment` event →
`Observer\HealthySite`, which runs connectivity probes against Aplazo and writes the
outcome to `var/log/aplazo_payment/info.log`. Turn it off after verifying.
