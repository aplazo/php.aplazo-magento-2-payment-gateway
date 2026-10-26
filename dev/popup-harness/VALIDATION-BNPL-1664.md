# BNPL-1664 local validation

Validation of the popup checkout mode (configuration, opening, messages, recovery) on a local Magento before review.
It is not a validation of the real Aplazo checkout, of mobile browsers or of inventory fixes.

## Base

- Branch `BNPL-1664-popup-config-messaging`, created from `origin/master` at `d88eb80` (tag `4.0.12`). Validated on the
  branch's working tree before its first commit; that commit carries the same tree plus this note.
- Magento Open Source 2.4.8, PHP 8.3, developer mode, MSI enabled, `reserve_stock=1`, `sanbox_mode=1`, store in MXN,
  local docker-magento (markshust).
- Redirect was also run against `master` (`d88eb80`) with the same configuration, to tell existing defects from
  regressions.
- Runtime validation covers Magento 2.4.8 only. For the new `laminas/laminas-uri ^2.9` requirement, the dependency
  declared by `magento/framework` was checked in the `magento/magento2` tags 2.4.4 (`^2.9.1`), 2.4.6 and 2.4.8
  (`^2.9`); `SecureHtmlRenderer` exists in those tags. That is a dependency check, not official support of any other
  Magento version.

## What the messages do

Checkout's messages (`success`, `failure`, `close`, `pending`) and a closed window only drive navigation and what the
checkout page shows. They never change the order or the payment state. The existing server-side paths are unchanged:
Aplazo's webhook confirms the payment, and the plugin's cron cancels unpaid orders. Neither the webhook nor the cron was
exercised in these runs.

## Setup needed (local only)

1. **Stub module.** Copy `magento-e2e/stub-module/` to `app/code/LocalDev/AplazoStub/` of the local Magento, then
   `bin/magento module:enable LocalDev_AplazoStub && bin/magento setup:upgrade`. It replaces `ApiService`,
   `TrackingService` and `LogService` through DI preferences: no call reaches Aplazo, tracking or remote logging.
   Check before every run that those three classes resolve to `LocalDev\AplazoStub\...`. Never deploy it.
2. **Network block (second barrier).** In the PHP containers (`phpfpm` and `phpfpm-xdebug`), map `api.aplazo.net`,
   `core.aplazo.net`, `api.aplazo.mx`, `core.aplazo.mx` and `checkout.aplazo.net` to `127.0.0.1` in `/etc/hosts`.
   Recreating a container (for example when remounting the plugin) drops this entry: add it again afterwards.
3. **Mount the branch** with the team's `magento-local-stg` script (`local.sh mount <worktree>`), recreate
   `phpfpm-xdebug` too (the script only recreates `phpfpm` and `app`), clear `generated/code` and
   `generated/metadata`, then run `bin/magento setup:di:compile`.
4. **Popup mode** for the test website only: `bin/magento config:set --scope=websites --scope-code=base
   payment/aplazo_gateway/checkout_mode popup`. Popup is only effective with `sanbox_mode=1`.
5. **Mock checkout on the configured origin.** Write `https://checkout.aplazo.net/mock` to
   `var/localdev_aplazo_stub_checkout_url` (the stub then returns that URL from `createLoan`), create a throwaway
   certificate and start the mock:

   ```bash
   mkdir -p dev/popup-harness/.out/magento-e2e && cd dev/popup-harness/.out/magento-e2e
   openssl req -x509 -newkey rsa:2048 -nodes -keyout key.pem -out cert.pem -days 2 \
     -subj "/CN=checkout.aplazo.net" -addext "subjectAltName=DNS:checkout.aplazo.net"
   cd - && python3 -I dev/popup-harness/magento-e2e/mock_https.py
   ```

   The test Chrome maps `checkout.aplazo.net` to that mock with `--host-resolver-rules`; nothing else on the machine
   changes.
6. **Run** (each test that reaches Place Order creates one local order; `results.jsonl` goes to
   `dev/popup-harness/.out/magento-e2e/`):

   ```bash
   MAGENTO_DIR=/path/to/docker-magento python3 -I dev/popup-harness/magento-e2e/run.py popup_success popup_failure
   ```

   Reservations per order: `bin/clinotty php -- APLAZO-TEST-001 <increment_id> < dev/popup-harness/magento-e2e/snapshot.php`
   (read only).
7. **Restore** afterwards: delete the `checkout_mode` row of `websites/1` and the stub URL file, stop the mock, mount
   the previous checkout with `local.sh mount`, recreate `phpfpm-xdebug`, add the network block again and clear
   `generated/`. Test orders are kept.

The browser-only suites (`run.sh`, `click_test.py`) are described in `README.md` and need no Magento.

## Results

| Check | Result |
|---|---|
| One `aplazo_gateway` method; `redirect` by default (no stored row); website `popup` inherited by the store | Pass |
| Popup effective only with sandbox on (`sanbox_mode=0` on the website gives `redirect` and no origin) | Pass |
| Redirect: same reservations as `master` (`-1 order_placed`, `+1 aplazo_item_reserved`), no `mode=popup`, no `opener` | Pass, compared with `master` |
| Popup opens from the click, reaches Checkout with `mode=popup` and `window.opener` | Pass |
| `success`: fixed Magento success page; a `redirectUrl` inside the message is ignored | Pass |
| Initial opening blocked (no user gesture, pop-ups blocked): today's redirect, without `mode=popup` | Pass |
| Validation failure (Magento_CheckoutAgreements, required terms unchecked): no window, no order, no `purchase`; after checking the box the order is placed and the popup opens | Pass. The field error text was not captured by the driver |
| AJAX failure of `payment-information`: no order, window closed, button enabled again | Pass |
| Double click: one order, one window | Pass |
| Message filtering: version 2, string, unknown state, child-frame source and opaque origin are ignored | Pass |
| `pending` is not terminal; losing the window afterwards shows the "payment in progress" notice | Pass |
| `close`, `failure`, closed window: neutral notice and "Go to cart"; order stays `new`, no `cancelLoan`, reservations unchanged | Pass |
| "Go to cart": only an order of the same checkout session shows its notice; the cart is empty and the order, loan and reservations do not change | Pass |
| Reopen button never rendered (`POPUP_REOPEN_ENABLED=false`) | Pass |
| Translations (`es_MX`) of the notices, the link and the cart message | Pass in the browser for the notices and the link. The current cart message was rendered in English only; its `es_MX` row was checked in the CSV |
| `purchaseUnavailable` page: no inline handler or style attribute (SecureHtmlRenderer); its button closes the window | Pass |
| Restrictive CSP | Not validated, see below |

### CSP

The storefront sends `Content-Security-Policy-Report-Only`, and the `script-src` of this route includes
`'unsafe-inline'`, so Magento_Csp adds no hashes for the page's script. Setting `csp/mode/storefront/report_only=0`
(with a full cache flush) did not switch this installation to an enforced header, so the page has not been run under an
enforced policy. The inline attributes were removed anyway, so the page does not depend on `'unsafe-inline'` for them.

## Known defects

### Existing on `master`: repeated stock compensation when `purchase` is visited again

Every GET of the `purchase` URL adds another `+1 aplazo_item_reserved`. Reproduced locally in redirect on `master` and
on this branch, and in popup mode by requesting the purchase URL again. Reloading the popup reloads the Checkout page
and does not repeat `purchase`; `history.back()` did not repeat it either. Mike's decision (relayed on 26 Oct 2026):
the fix for this redirect defect goes in its own ticket and PR, and it does not block the popup while reopening stays
disabled.

### Risk of the popup flow, pending in BNPL-1665: window closed before `purchase`, no compensation

The order is placed but `purchase` never runs, so the order keeps only `-1 order_placed` (reproduced). After a
cancellation the net is `-1`: observed on the BNPL-1607 discovery tree, not repeated here. A net of `-2` after a paid
webhook is deduced from the code (the approval adds another `-1`); it has not been reproduced. This risk has not been
accepted; it stays open in BNPL-1665.

## Pending by ticket

- **BNPL-1664:** review and PR; run the validation-failure case with a capture of the field error; restrictive CSP on
  an installation where it can be enforced.
- **Separate redirect ticket and PR (repeated compensation):** to be created.
- **BNPL-1665:** the early-close risk above; reproduce the paid-webhook case before stating its net.
- **BNPL-1666:** real Checkout contract and states; `mode=popup` kept by Checkout through login and every redirect;
  COOP; who shows the success page; whether "Go to cart" may offer cancel-and-restore once Checkout's loan states are
  known.
- **BNPL-1667:** iOS Safari and Android Chrome on real devices; Safari desktop with a real click.
