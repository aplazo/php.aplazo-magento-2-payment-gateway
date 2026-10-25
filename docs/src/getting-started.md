# Getting Started

## Requirements

- Magento 2 / Adobe Commerce (tested on 2.4.x; PHP 8.2 supported).
- Modules present in the store (declared as a `sequence` in `etc/module.xml`):
  `Magento_Backend`, `Magento_Ui`, `Magento_Authorization`, `Magento_Sales`,
  `Magento_Payment`, `Magento_Checkout`.
- Composer dependency `firebase/php-jwt ^6.0` (installed automatically).
- A store currency of **MXN** — the method is disabled for any other currency
  (see [Configuration → Credentials](configuration.md#credentials--status)).
- Aplazo **Merchant ID** and **API token** (request them at
  [become a merchant](https://web.aplazo.mx/merchant-registration/become-merchant/company-info)).

## Installation

```bash
# 1. Require the module
composer require aplazo/aplazopayment

# 2. Enable it
php bin/magento module:enable Aplazo_AplazoPayment

# 3. Run setup upgrade (installs DB schema + data patches)
php bin/magento setup:upgrade

# 4. Recompile & flush (production mode)
php bin/magento setup:di:compile
php bin/magento cache:flush
```

`setup:upgrade` applies the module's declarative schema and data patches:

- Adds the `aplazo_checkout_url` column to `sales_order`.
- Creates the `aplazo_refund_request` queue table.
- Registers the custom order statuses `aplazo_webhook_received` and
  `aplazo_order_canceled`.
- Adds the `qty_aplazo_refunded` attribute to RMA items (Adobe Commerce only).
- Emits a `plugin_installed` tracking event to Aplazo.

## Configuration

1. Go to **Stores → Configuration → Sales → Payment Methods → Aplazo**.
2. Under **APLAZO checkout → Credenciales**, enter your **Merchant ID** and
   **API token**, and pick **Modo de pruebas** (sandbox) if you are testing.
3. Under **General**, set **Activar = Yes** and adjust order statuses, widgets,
   refunds, and cancellation behavior as needed.
4. Save. On save the module validates credentials against Aplazo and shows an
   **Estado de credenciales** badge and the **Moneda configurada** (must be MXN).
5. Flush the cache. The **"Compra ahora y paga en 5 quincenas"** method should now
   appear at checkout.

!!! tip "Sandbox vs Production"
    A single **Modo de pruebas** toggle switches every outbound base URL at once:

    | | Sandbox (test) | Production |
    |---|---|---|
    | API | `https://api.aplazo.net` | `https://api.aplazo.mx` |
    | Core (tracking/logs) | `https://core.aplazo.net` | `https://core.aplazo.mx` |

## Verify the install

```bash
# Confirm the module is enabled
php bin/magento module:status Aplazo_AplazoPayment

# Trigger the built-in health check (or enable "Check Healthy Site" in admin)
# and watch the log:
tail -f var/log/aplazo_payment/info.log
```
