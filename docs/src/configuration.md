# Configuration

All settings live under **Stores → Configuration → Sales → Payment Methods → Aplazo**
and are stored under the config path prefix `payment/aplazo_gateway/`. Values are read
at `store` scope through `Aplazo\AplazoPayment\Helper\Data`.

## Credentials & status

| Field (admin) | Config path | Type | Notes |
|---|---|---|---|
| Merchant ID | `payment/aplazo_gateway/merchantid` | text | Sent as `merchant_id` header on every API call. Invalidates cache on change. |
| API token | `payment/aplazo_gateway/apitoken` | obscure | **Encrypted at rest**; decrypted via `EncryptorInterface`. Also used as the **HS512 key** to verify the webhook JWT. |
| Modo de pruebas | `payment/aplazo_gateway/sanbox_mode` | select | Switches all base URLs between sandbox and production. |
| Estado de credenciales | _(display)_ | block | Live validation badge (`CredentialsStatus`). |
| Moneda configurada | _(display)_ | block | Store currency; **must be `MXN`** or the method stays hidden. |

Credentials are considered valid when `CredentialsValidator::areCredentialsValid()`
returns true — i.e. an auth token is successfully obtained from Aplazo **and** the
store currency is `MXN`.

## General

| Field | Config path | Default | Purpose |
|---|---|---|---|
| Activar | `active` | 1 | Enable/disable the method. |
| Posición en el checkout | `sort_order` | 1 | Ordering vs other methods. |
| Estado de orden nueva | `order_status` | `pending` | Status when the order is first created. |
| Estado de orden aprobada | `approved_order_status` | `processing` | Status after the webhook confirms payment. |
| Estado de orden rechazada | `failure_order_status` | `canceled` | Status on failure. |
| Reservar stock | `reserve_stock` | 1 | Reserve inventory until the webhook confirms payment. |
| Tiempo en que se cancelan las órdenes | `cancel_time` | 720 (min) | Age after which unpaid orders are cancelled by cron. |
| Mostrar widget en página de producto | `show_on_product_page` | 1 | Product-page installment widget. |
| Mostrar widget en página de carrito | `show_on_cart` | 1 | Cart-page installment widget. |
| Habilitar reembolsos | `refund` | 1 | Enqueue refunds on credit memo. |
| Habilitar reembolsos por RMA | `rma_refund` | 1 | Enqueue refunds on RMA (Adobe Commerce). |
| Enviar correo de confirmación en webhook | `send_email` | 0 | Send the order-confirmation email when the webhook confirms payment. |

## Abandoned checkout

| Field | Config path | Default | Purpose |
|---|---|---|---|
| Cancelar orden si el usuario abandona checkout | `cancel_active` | 1 | Cancel the order when the shopper leaves the Aplazo checkout. |
| Mensaje a mostrar | `cancel_message` | _(see below)_ | Shown on redirect back to cart. |
| Recuperar carrito al regresar | `recover_active` / `enable_recover_cart` | 1 | Rebuild a cart from the abandoned order's items. |

> Default cancel message: _"No terminaste la orden con Aplazo. La orden se canceló.
> Por favor, intenta nuevamente."_

## Test & Debug

| Field | Config path | Default | Purpose |
|---|---|---|---|
| Activar logs | `debug_mode` | 1 | Log verbosity (`1` = normal, `2` = very verbose / `LOGS_VVV`). |
| Check Healthy Site | `check_healthy_site` | 0 | On save, fires test requests to Aplazo to verify connectivity. Results in `var/log/aplazo_payment/info.log`. |

## Payment method behavior (`etc/config.xml`)

The method is an **offsite gateway**: `can_authorize`, `can_capture`, `can_void`,
and `is_gateway` are all `0` — Magento does not capture funds; Aplazo does. Model
facade is `AplazoFacade`, `can_use_checkout = 1`, `allowspecific = 1`.

!!! warning "Credentials are secrets"
    The API token is encrypted in the DB and doubles as the webhook signing key.
    Never commit real credentials, and rotate them through the admin (which
    re-encrypts and invalidates cache) rather than editing the database directly.
