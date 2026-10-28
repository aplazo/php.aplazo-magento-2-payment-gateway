<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Model\Quote;

/**
 * Whether the quote's items can still be sold in the requested quantities, checked by the finalizer before converting a
 * paid attempt (BNPL-1664), so that a missing unit is a definitive rejection instead of a submit error retried later.
 *
 * With MSI it asks AreProductsSalableForRequestedQty for the website's stock. MSI is optional for this module (as in
 * OrderService::isMsiOrInventory), so its classes are resolved only when the module is enabled. Without MSI it answers
 * true and the stock is left to submit(); that path is not validated yet (no instance without MSI).
 */
class StockCheck
{
    public function __construct(
        private ModuleManager $moduleManager,
        private ObjectManagerInterface $objectManager
    ) {
    }

    public function isSalable(Quote $quote): bool
    {
        if (!$this->moduleManager->isEnabled('Magento_InventorySalesApi')) {
            return true;
        }
        $qtyBySku = [];
        foreach ($quote->getAllItems() as $item) {
            if ($item->getHasChildren()) {
                continue;
            }
            $parentQty = $item->getParentItem() ? (float)$item->getParentItem()->getQty() : 1.0;
            $sku = (string)$item->getSku();
            $qtyBySku[$sku] = ($qtyBySku[$sku] ?? 0.0) + (float)$item->getQty() * $parentQty;
        }
        if ($qtyBySku === []) {
            return false;
        }
        $websiteId = (int)$quote->getStore()->getWebsiteId();
        $stockId = (int)$this->objectManager->get(\Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface::class)
            ->execute($websiteId)->getStockId();
        $requestFactory = $this->objectManager->get(
            \Magento\InventorySalesApi\Api\Data\IsProductSalableForRequestedQtyRequestInterfaceFactory::class
        );
        $requests = [];
        foreach ($qtyBySku as $sku => $qty) {
            $requests[] = $requestFactory->create(['sku' => (string)$sku, 'qty' => $qty]);
        }
        $results = $this->objectManager->get(\Magento\InventorySalesApi\Api\AreProductsSalableForRequestedQtyInterface::class)
            ->execute($requests, $stockId);
        foreach ($results as $result) {
            if (!$result->isSalable()) {
                return false;
            }
        }

        return true;
    }
}
