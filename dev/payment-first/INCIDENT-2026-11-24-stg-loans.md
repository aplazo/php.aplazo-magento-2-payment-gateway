# Incident 2026-11-24: three loans created in Aplazo STG by a local test script

**Summary.** On 2026-11-24 between 18:15:49 and 18:16:36 UTC, `dev/popup-harness/magento-e2e/query_probe.php` ran
against the local Magento while `LocalDev_AplazoStub` was disabled. The plugin therefore used its real services and
called Aplazo STG: three redirect orders, three loans created and then cancelled by the script itself. No money moved.
The buyer data was synthetic.

## What happened

- The local store (`~/Developer/Aplazo/magento248`) keeps `'LocalDev_AplazoStub' => 0` in `app/etc/config.php` and is in
  sandbox mode with STG credentials.
- `query_probe.php` measures queries on ordinary operations; its `cancel_aplazo` step places and cancels an Aplazo
  **redirect** order. In redirect mode, `sales_order_place_after` calls `createLoan`, and cancelling calls `cancelLoan`.
- The earlier session did not check the stub's state before running the script.

## Evidence

**Observed in this session (local files only; STG was not queried):**
- `var/log/aplazo_payment/info.log` of the local store, lines 2270–2355:

| Order | `order_created` tracking (STG, `core.aplazo.net`) | createLoan (`api.aplazo.net`) | cancelLoan answer |
|---|---|---|---|
| `000000411` | 18:15:49, HTTP 202 | 18:15:50, loanId 395711 | 18:15:54, `CANCELLED` |
| `000000413` | 18:16:06, HTTP 202 | 18:16:07, loanId 395712 | 18:16:10, `CANCELLED` |
| `000000415` | 18:16:31, HTTP 202 | 18:16:33, loanId 395713 | 18:16:36, `CANCELLED` |

  Each createLoan was preceded by an `/api/auth` call (3 in total). Buyer: `pf-probe@example.invalid`, "Calle Demo 3",
  synthetic.
- After 18:16:36 the same log shows no request to `api.aplazo.net` or `core.aplazo.net`; later Aplazo orders used
  `local-stub-token` (stub enabled).

**Reported by the other session, not re-verified here:**
- The local orders `000000410`–`000000415` ended `canceled` (it showed a query; `410`, `412`, `414` are check/money order
  orders with no Aplazo call).
- That nothing else reached STG. Not verifiable locally: with the stub disabled the real `LogService` was active, so
  plugin log entries for these orders may have been sent to the STG logs endpoint as well; the local log does not record
  those sends.

**Not available locally:** the loans' current state in STG. A `CANCELLED` answer to `cancelLoan` is what Aplazo returned
at that moment; it was not re-queried.

## Follow-up

- Done: `kit_guard.php` (stub enabled, the plugin's API/log/tracking services are the stub's, local store URL) runs
  before every suite and in the PHP scripts that can reach the API; with the stub disabled it stops the suite before any
  request (verified).
- To do by a person with STG access: confirm loans 395711–395713 are `CANCELLED` and tell the STG owners about three
  synthetic loans and three `order_created` events from merchant test traffic, if that is needed.
