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
   (read only). Session authorization checks, with in-memory fakes and no orders:
   `bin/clinotty php < dev/popup-harness/magento-e2e/session_orders_check.php`.
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
| `success`: goes through `popup_success` for the popup's own order, then Magento's success page for it; a `redirectUrl` inside the message is ignored | Pass (`popup_success_single`, order 64) |
| Initial opening blocked (no user gesture, pop-ups blocked): today's redirect, without `mode=popup` | Pass |
| Validation failure (Magento_CheckoutAgreements, required terms unchecked): no window, no order, no `purchase`; after checking the box the order is placed and the popup opens | Pass. The field error text was not captured by the driver |
| AJAX failure of `payment-information`: no order, window closed, button enabled again | Pass |
| Double click: one order, one window | Pass |
| Message filtering: version 2, string, unknown state, child-frame source and opaque origin are ignored | Pass |
| `pending` is not terminal; losing the window afterwards shows the "payment in progress" notice | Pass |
| `close`, `failure`, closed window: neutral notice and "Go to cart"; order stays `new`, no `cancelLoan`, reservations unchanged | Pass |
| "Go to cart": only an order of the same checkout session shows its notice; the cart is empty and the order, loan and reservations do not change | Pass |
| Session authorization by entity id (`session_orders_check.php`, in-memory fakes): session order accepted; other session rejected; same increment id in another store rejected; malformed ids (`0`, `-1`, `0101`, `+101`, spaces, `1e3`, `10.0`, increment ids, overflow, non-strings) rejected; old increment-id session data ignored; two tabs of one session both known; list bounded to ten | Pass, 35 checks |
| Purchase keeps master's scope: two orders in one session, then `order` pointed at the older one and at malformed ids (leading zero, `+`, suffix, negative, increment id) | Pass: all six rejected with the popup page before any compensation; reservations of both orders unchanged; no stub API call; six rejections logged. `popup_return` still shows the older order's status |
| Two tabs, delayed `purchase` of the older order (`popup_tabs_race`) | Pass: the newer order is never processed by the older popup; the older one is rejected before any compensation or Aplazo call. Limitation described below |
| `success` in tab A after tab B placed a newer order (`popup_success_other_tab`, orders 69 and 70) | Pass: tab A never shows B's confirmation; it gets the cart and a neutral notice naming A (a Magento session message, which another tab of the session may show instead, see the limitation below); B's success page still shows B afterwards; states and reservations of both orders unchanged |
| `popup_success` with a foreign, zero-padded, suffixed or empty id; popup `purchase` without an `order` id | Pass: cart without any order notice; the purchase request gets the popup page, reservations unchanged |
| Popup turned off (`checkout_mode=redirect`) or sandbox turned off while A's popup `purchase` is held, with B placed meanwhile (`popup_tabs_race_popup_off`, `popup_tabs_race_sandbox_off`, orders 65 to 68) | Pass: A's request gets "Aplazo cannot be opened in its own window right now"; never handled as a redirect, B untouched, no compensation for A, no Aplazo call |
| Normal redirect without `mode=popup` after these changes (`redirect_check`, order 71) | Pass: `-1` / `+1`, no `mode=popup`, no `opener` |
| Checkout URL validated against the configured origin before compensation (`checkout_url_check.php`, in-memory orders) | Pass, 25 checks: absolute https URL on `checkout.aplazo.net` (case-insensitive host, explicit `:443`) accepted and built with `mode=popup`; empty, relative, scheme-relative, `http`, `javascript:`, `data:`, credentials, user only, suffix/prefix host, subdomain, parent domain, trailing dot, other port, backslash, space, control character, malformed and no-host URLs rejected; no configured origin (production) rejected |
| Same, on Magento (`popup_url_valid`, `popup_url_suffix_host`, `popup_url_credentials`, orders 72 to 74) | Pass: the valid URL opens Checkout with `mode=popup` (`-1` / `+1`); the suffix-host and credentials URLs get the neutral popup page, no compensation (`-1` only) and are never loaded. Order creation (and its loan request, stubbed here) had already happened before this check; the rejection only avoids the compensation and the navigation |
| Terminal message and window closing (harness `success_then_close`, `watcher_before_terminal_message`, `closed_without_message`, `earlier_session_ignored`; Magento `popup_success_then_close`, order 75) | Pass: a `success` posted just before the window closes is processed (Magento: success page for order 75). When the watcher sees the window closed first, the notice is "lost" and a later terminal message from that same window still replaces it; a late `pending` turns it into "payment in progress"; a duplicate terminal, a message from another window, a wrong origin or version, and anything after the terminal are ignored. A closed window with no message reports "lost" once. Events from an earlier session of the renderer change nothing |
| Reopen button never rendered (`POPUP_REOPEN_ENABLED=false`) | Pass |
| Translations (`es_MX`) of the notices, the link and the cart message | Pass in the browser for the notices and the link. The current cart message was rendered in English only; its `es_MX` row was checked in the CSV |
| `purchaseUnavailable` page: no inline handler or style attribute (SecureHtmlRenderer); its button closes the window | Pass |
| Restrictive CSP | Not validated, see below |

### CSP

The storefront sends `Content-Security-Policy-Report-Only`, and the `script-src` of this route includes
`'unsafe-inline'`, so Magento_Csp adds no hashes for the page's script. Setting `csp/mode/storefront/report_only=0`
(with a full cache flush) did not switch this installation to an enforced header, so the page has not been run under an
enforced policy. The inline attributes were removed anyway, so the page does not depend on `'unsafe-inline'` for them.

## Checkout review observations (Gus)

Sources: the summary of Checkout's technical review (Gustavo, 16 Oct 2026) in the description of BNPL-1607; the
follow-up points relayed in the BNPL-1664 work on 26 Oct 2026 (the original Slack thread was not accessible for this
note); the Buildable Spec for Phase 1 (23 Jul 2026, PIPE-209) for the load timeout, OQ1 and device segmentation.
"Pending" means no agreement yet; assigning a ticket does not resolve a row.

| Observation | Current behaviour and evidence | Decision pending | Ticket |
|---|---|---|---|
| The pilot does not depend on the Universal Checkout launch or on 9.x | The plugin loads no Checkout SDK; it opens the URL returned by `createLoan` and adds the provisional `mode=popup`. Popup is limited to sandbox (`https://checkout.aplazo.net`) | Which Checkout release serves `mode=popup` and the message contract, and on what date; other Checkout dependencies stay open | BNPL-1666 |
| Staging URLs and headers, including COOP | STG origin hard-coded for sandbox; production origin `null`, so production falls back to redirect. The checkout URL from the loan API must be on that origin before the popup opens it. With COOP `same-origin` on the mock the opener is lost and the page shows the "lost communication" notice (harness observation `coop_same_origin_observation`). Real staging headers not checked | Exact staging and production origins; COOP on Checkout and on merchant pages (`unsafe-none` or `same-origin-allow-popups`) | BNPL-1666 |
| Acknowledgement, timeout and fallback | **Initial fallback** when the browser blocks the window: implemented and tested (redirect without `mode=popup`). **Recovery when no message arrives:** no acknowledgement and no timeout; only a closed window is detected (`lost` notice), and the listener stays so a terminal message already posted before the close is still handled; an open but silent window waits indefinitely | Whether Checkout sends a ready/ack message, the timeout and what the store does when it expires | BNPL-1666, then BNPL-1664 for the plugin side |
| Who closes the window on terminal messages | The store page closes the popup on `success`, `failure` and `close` (`popup-checkout.js` `onMessage`); if Checkout closes it first, its terminal message is still handled once; evidence: harness `success`/`failure`/`close`/`success_then_close`, Magento E2E with no window left open | Whether Checkout closes itself, or the store closes it, and when | BNPL-1666 |
| Querying the order until `processing` | Not implemented: `success` goes straight to the success page; the webhook confirms the payment asynchronously | Whether polling (or another wait) is part of this iteration (spec OQ1, BNPL-1666 criterion 5) | BNPL-1666 |
| Desktop / mobile participation and the conversion hypothesis | Desktop opens a window; mobile browsers are expected to open a tab. Only desktop Chrome with mobile emulation and Safari desktop (blocked case) were run; no device split, telemetry or A/B switch in the plugin | Whether popup is enabled on mobile, and how the conversion hypothesis of BNPL-1028 is measured (device segmentation, A/B) | BNPL-1667, with Product |

## Known defects

### Existing on `master`: repeated stock compensation when `purchase` is visited again

Every GET of the `purchase` URL adds another `+1 aplazo_item_reserved`. Reproduced locally in redirect on `master` and
on this branch, and in popup mode by requesting the purchase URL again. Reloading the popup reloads the Checkout page
and does not repeat `purchase`; `history.back()` did not repeat it either. This branch keeps master's scope: only the
session's last order can be selected (see "PR #131 automated review"), so the defect is not extended to older orders. Mike's decision (relayed on 26 Oct 2026):
the fix for this redirect defect goes in its own ticket and PR, and it does not block the popup while reopening stays
disabled.

### Risk of the popup flow, pending in BNPL-1665: window closed before `purchase`, no compensation

The order is placed but `purchase` never runs, so the order keeps only `-1 order_placed` (reproduced). After a
cancellation the net is `-1`: observed on the BNPL-1607 discovery tree, not repeated here. A net of `-2` after a paid
webhook is deduced from the code (the approval adds another `-1`); it has not been reproduced. This risk has not been
accepted; it stays open in BNPL-1665.

## PR #131 automated review

| Finding | Change | Evidence | Status |
|---|---|---|---|
| XHawk: `PlacedOrders` keyed by increment id, which is not unique across stores | The session list holds positive integer entity ids under a new key, compared strictly; ids are normalized explicitly (`PlacedOrders::normalizeId`). Orders are remembered on `checkout_submit_all_after` (`Observer/RememberPlacedOrder`), once the entity id exists, so `SalesOrderPlaceAfterCreateLoan` is back to master. Showing an order (`popup_return`) also requires the current store | `session_orders_check.php` (35 checks); Magento E2E: the first popup of each order still opens | Fixed |
| XHawk: a request-selected `order` lets `purchase` repeat the compensation for any of the last ten session orders | Showing an order and running `purchase` are authorized separately. `purchase` keeps master's scope (`getLastRealOrder()`); the popup's `order` must name that same order, checked before any compensation (`PopupCheckout::isPurchaseTarget`). Older session orders can only be shown | Magento E2E `popup_order_manipulation` | Fixed: the extension is removed. Master's own defect (repeating `purchase` for the last order) stays, see Known defects |
| XHawk: closing the window before `purchase` leaves the order without compensation | No change here (inventory) | Reproduced earlier | Open in BNPL-1665, not accepted |
| XHawk (2nd review): a popup `success` can show another tab's order confirmation | `success` goes to `popup_success` with the popup's order id. The server checks the order belongs to the session and the current store; Magento's success page is used only when that order is the session's last one. Otherwise the cart shows a neutral notice for that order (a Magento session message). The order, the reservations and the session's last-order data are not changed | `popup_success_other_tab`, `popup_success_single` | Fixed |
| XHawk (2nd review): turning popup off while a popup request is in flight drops the order check | `mode=popup` marks the request as a popup request regardless of configuration: its `order` is always checked, and if popup is no longer effective the request gets the popup page before any compensation. It is never handled as a redirect. Requests without `mode=popup` keep the redirect path | `popup_tabs_race_popup_off`, `popup_tabs_race_sandbox_off`, `redirect_check` | Fixed |
| XHawk (3rd review): the popup opens a checkout URL that is not checked against the configured origin | In popup mode, `purchase` validates the stored URL (absolute https, same host and effective port as `getCheckoutOrigin()`, no credentials, no whitespace, control characters or backslashes) and builds `mode=popup` before the stock compensation; otherwise the popup gets the neutral page. Redirect is unchanged from `master` | `checkout_url_check.php` (25 checks), `popup_url_*` | Fixed |
| XHawk (3rd review): the closed-window watcher can drop a terminal message already posted | Detecting `window.closed` no longer ends message handling: the watcher reports `lost` and stops; the listener stays until a terminal message, `pagehide` or `dispose()`. The first terminal message from that same window is handled once; later ones are ignored. Renderer handlers act only for the current session. No new timer or wait | harness `watcher_before_terminal_message`, `success_then_close`, `closed_without_message`, `earlier_session_ignored`; Magento `popup_success_then_close` | Fixed |
| Semgrep `dynamic-urllib-use-detected` (`magento_smoke.py`, `click_test.py`, `magento-e2e/run.py`) | All tool HTTP goes through `cdp.local_request` / `cdp.devtools_json`: fixed host `127.0.0.1`, checked port, path and method, DevTools paths on an allow list, an opener with only HTTP/HTTPS handlers (no `file://`) that does not follow redirects | Guard checks: twelve invalid targets rejected, a 302 is not followed; `run.sh` 30/30 and `click_test.py` through the new client | Fixed; Semgrep scan passes |
| Semgrep `detect-insecure-websocket` (`cdp.py`) | The DevTools socket is accepted only as `ws` on loopback (`127.0.0.1` / `localhost`); other hosts, `wss` and other schemes are rejected. Chrome's DevTools socket has no TLS, and it never leaves the machine | Guard checks | Fixed; Semgrep scan passes |

## Limitation: popup with several tabs of one session

Run (`popup_tabs_race`, orders 60 and 61): tab A placed order A and its popup's `purchase` request was held at the
browser; tab B, same session, placed order B and completed its own `purchase`; then A's request was released.

- `purchase` did not process B: B kept `-1 order_placed` / `+1 aplazo_item_reserved` from its own popup and nothing more.
- A's request was rejected before any compensation: A kept only `-1 order_placed`; Checkout (the mock) was never
  loaded for A; no Aplazo (stub) call; one rejection logged.
- The buyer sees, in A's window: "We could not open Aplazo for this order from this window." with a close button.
  Tab A shows no notice while that window stays open; once it is closed it shows the "lost communication" notice and
  "Go to cart" (which only shows A's status).
- Order A stays pending with no compensation until it is paid or cancelled by the existing paths.
- The notices of this flow (and the neutral `success` notice of `popup_success`) are Magento session messages: they
  are shown by the next page of the session that renders messages, which can be another tab. In one run the notice for
  A after a `success` was shown by tab B's next page. The notice names order A, and tab A never shows B's confirmation
  as A's result, but which tab displays the notice is not controlled.

This rejection comes from the current protection (purchase keeps master's last-order scope). It is a limitation of the
popup with several tabs, not an accepted risk, and it is distinct from:

- the **early manual close** (BNPL-1665): the buyer closes the window before `purchase`; nothing is rejected, `purchase`
  simply never runs;
- the **repeatable master defect**: each extra visit to `purchase` for the last order adds another compensation.

Letting tab A open its own order (for example with a one-time purchase token per order) is not implemented. How to solve
this limitation and which ticket owns it are still pending; moving it into BNPL-1665 has not been agreed.

## Pending by ticket

- **BNPL-1664:** human review of PR #131 (draft); push these fixes and answer the automated findings; run the
  validation-failure case with a capture of the field error, and restrictive CSP on an installation where it can be
  enforced.
- **Separate redirect ticket and PR (repeated compensation):** to be created.
- **BNPL-1665:** the early-close risk above; reproduce the paid-webhook case before stating its net.
- **BNPL-1666:** real Checkout contract and states; `mode=popup` kept by Checkout through login and every redirect;
  COOP; who shows the success page; whether "Go to cart" may offer cancel-and-restore once Checkout's loan states are
  known.
- **BNPL-1667:** iOS Safari and Android Chrome on real devices; Safari desktop with a real click.
