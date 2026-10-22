# Popup checkout harness (BNPL-1607)

Browser tests for the popup checkout mode of the Aplazo Magento plugin. They exercise the real
`view/frontend/web/js/view/payment/method-renderer/aplazo-gateway.js` and
`view/frontend/web/js/model/popup-checkout.js` against a mock of the Aplazo checkout. They don't place
orders, create loans or reserve stock.

This folder is excluded from the distributed package (`/dev export-ignore` in `.gitattributes`).

## What each tool covers

| Tool | What it runs | What it proves | What it does not prove |
|---|---|---|---|
| `run.sh` | Headless Chrome, suites `main` (popups allowed) and `blocked` (Chrome's default blocker, no user gesture) | Opening, AJAX failure, double click, validation failure, messages (`success`, `failure`, `close`, `pending`), origin/source/version/shape checks, closing during and after order creation, `window.open` throwing, two instances, `pagehide` cleanup, redirect fallback. COOP `same-origin` is reported as an observation | Real Aplazo checkout, real Magento purchase, mobile browsers |
| `click_test.py` | A visible Chrome window, normal popup blocker | The popup opens from a trusted mouse click; without a user gesture Chrome blocks it and the redirect path runs | A gesture-initiated popup being blocked (Chrome allows those even with the site's pop-ups blocked; try Safari, below) |
| `magento_smoke.py` | Headless Chrome on a local Magento checkout, up to selecting Aplazo | The renderer and template load through RequireJS with no JavaScript errors, and the checkout config reaches the page | Anything after Place Order (it is never clicked) |

The base renderer used by the harness is a stub that copies `placeOrder` and `getPlaceOrderDeferredObject`
from Magento 2.4.8 (`Magento_Checkout/js/view/payment/default.js`). If the installed Magento version changes
them, update the stub in `harness.html`.

## Message contract used by the mock (provisional)

`window.opener.postMessage({ type: 'aplazo:checkout:<state>', version: 1 }, 'http://localhost:8098')`, with
`<state>` one of `success`, `failure`, `close` (terminal) or `pending` (not terminal). The mock never
navigates to `successUrl` or `cartUrl` and never cancels. Checkout has not confirmed this contract yet.

## Requirements

- Python 3.9 or later (standard library only).
- Google Chrome or Chromium. Set `CHROME` if it is not at the default macOS path or on `PATH` as
  `google-chrome` or `chromium`.
- A Magento installation, only to copy `lib/web/jquery.js` and `lib/web/knockoutjs/knockout.js` the first
  time (`MAGENTO_SRC`), and for `magento_smoke.py`.
- Free local ports 8098, 8099, 8100 (fixed: the mock and the harness name these origins explicitly) and
  9333/9444 for the DevTools connection.

## Commands

From the repository root:

```bash
# Headless suites. MAGENTO_SRC is only needed the first time (copies jQuery and Knockout into .out/lib).
MAGENTO_SRC=/path/to/magento dev/popup-harness/run.sh

# Visible Chrome, trusted click (opens Chrome windows for a few seconds). Needs .out/lib from run.sh.
python3 -I dev/popup-harness/click_test.py                # allowed, untrusted, blocked
python3 -I dev/popup-harness/click_test.py allowed        # one run

# Local Magento checkout smoke test. Stops before Place Order.
MAGENTO_BASE_URL=https://magento.test MAGENTO_PRODUCT_PATH=aplazo-producto-prueba.html \
MAGENTO_PRODUCT_SKU=APLAZO-TEST-001 python3 -I dev/popup-harness/magento_smoke.py
```

Optional variables: `MODULE_JS` (another checkout of the module's `view/frontend/web/js`), `OUT` (results
directory, default `dev/popup-harness/.out`). Results, logs, the jQuery/Knockout copies and nothing else
are written under `OUT`. Browser profiles are temporary directories and are deleted after each run.

For `magento_smoke.py`, the Magento store needs the module mounted from this repository, a simple in-stock
product, a shipping method available for Mexico and the store in MXN. Loading the checkout validates the
module's credentials once against the configured Aplazo API, as any checkout page load does.

## Manual check: popup blocked after a real click (Safari)

1. Safari > Settings > Websites > Pop-up Windows: set `localhost` to Block.
2. `python3 -I dev/popup-harness/server.py` (from the repository root).
3. Open `http://localhost:8098/harness.html?suite=click-manual` and click "Pagar con Aplazo".
4. Expected: no window opens; the page then shows `openReturnedNull: true` and `redirectCalls` holding the
   purchase URL without `mode=popup`.

## Limits

- The mock is not the Aplazo checkout. Whether `checkout.aplazo.net` accepts `mode=popup`, which COOP header
  it sends, and how it behaves on mobile need a real run against staging once Checkout supports the mode.
- The integration Magento → `purchase` → Checkout is not covered here: `purchase` reserves stock and the
  order's loan is real.
- No credentials, tokens or buyer data are needed or stored. The smoke test uses a synthetic address.
