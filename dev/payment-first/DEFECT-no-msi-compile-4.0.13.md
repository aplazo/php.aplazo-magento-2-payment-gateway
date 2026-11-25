# Defect (pre-existing, not filed yet): the plugin does not compile on Magento without MSI

**Status:** prepared for reporting; no ticket created. Found while checking Mike's point 8 for BNPL-1664. Not caused
by BNPL-1664 and not fixed there.

## Summary

`bin/magento setup:di:compile` fails on a Magento 2.4.8 store whose `Magento_Inventory*` modules were removed with
Composer, because `Model/Product/MSIStock.php` (added in MG-763, PR #129) requires MSI interfaces in its constructor.
Such a store cannot be deployed in production mode with plugin 4.0.13 (`master`). Developer mode still runs, because
classes are generated on demand and `MSIStock` is only built when `Magento_Inventory` is enabled.

```
In ClassReader.php line 57:
  Impossible to process constructor argument Parameter #0 [ <required> Magento\InventoryCatalogApi\Model\GetSkusByProductIdsInterface $getSkusByProductIds ] of Aplazo\AplazoPayment\Model\Product\MSIStock class
In GetParameterClassTrait.php line 41:
  Class "Magento\InventoryCatalogApi\Model\GetSkusByProductIdsInterface" does not exist
```

## Affected

| Plugin | Commit | Result |
|---|---|---|
| 4.0.13 (`master`) | `fe0573d` | fails |
| 4.0.12 + BNPL-1664 (tested by Mike) | `b2f1ccb` | fails |
| 4.0.14 (BNPL-1664 final) | `64cbe2c` | fails |
| diagnostic only: `fe0573d` without `Model/Product/MSIStock.php` | — | compiles |
| diagnostic only: `64cbe2c` without `Model/Product/MSIStock.php` | — | compiles |

The two diagnostic rows only show that `MSIStock.php` is the first and only blocker; removing the file is not a fix.

A second, latent problem: `Model/Service/OrderService::reservingStockUntilPayment($order, $type = SalesEventInterface::EVENT_ORDER_PLACED)`
(since 2023) uses an MSI constant as a default value. Without MSI, generating an interceptor for `OrderService` fails
with `Class "Magento\InventorySalesApi\Api\Data\SalesEventInterface" not found`. It only happens if some module adds a
plugin to `OrderService` (seen locally with the BNPL-1664 test stub).

## Reproduction (2026-11-24, local, isolated)

Environment: copy of a Magento 2.4.8 store (`markoshust/magento-php:8.3-fpm-7`) in a separate Docker volume, a
container with no network for the compile (no database, Redis or web needed), file cache in the copy's `env.php`.

1. Remove MSI with Composer, as a merchant would: in `composer.json`, add every `magento/*inventory*` package except
   `magento/module-catalog-inventory` and `magento/module-sales-inventory` to `replace` with `"*"` (76 packages on
   2.4.8), then `composer update --minimal-changes`.
2. Drop the modules that are no longer installed from `app/etc/config.php` (what `setup:upgrade` would do): 74
   `Magento_Inventory*` modules. Check: no `Magento_Inventory*` module is registered.
3. Put the plugin in `app/code/Aplazo/AplazoPayment` from `git archive fe0573d` and enable `Aplazo_AplazoPayment`.
4. `rm -rf generated/*` and `php bin/magento setup:di:compile` → the error above.

The script used is not in the repository (it copies a local store); the steps above are all it does.

## Suggested direction

- Do not inject MSI interfaces into `MSIStock`'s constructor: resolve them only when `Magento_Inventory` is enabled
  (as `OrderService` already decides at runtime whether MSI is active), or move `MSIStock` behind a virtual type /
  factory that is only configured when MSI is present.
- Replace `SalesEventInterface::EVENT_ORDER_PLACED` as a default value with its string value (`'order_placed'`).
- Add a no-MSI `setup:di:compile` check to the release checklist.
