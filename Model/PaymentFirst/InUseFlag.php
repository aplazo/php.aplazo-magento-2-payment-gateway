<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;

/**
 * Marks a store as holding payment-first attempts (BNPL-1664), before its first attempt row is written, so that the
 * reconciliation, the webhook routing and KeepConvertedQuoteClosed keep looking after its attempts after popup is turned
 * off (AplazoHelper::isPaymentFirstInUse). Only the popup start calls it: stores that never use popup are not touched.
 *
 * The cached configuration is not refreshed here (that would clean the config cache from a checkout request), so until
 * the next refresh the committed row is read instead of writing it again: one indexed read per start, popup path only,
 * and none once the cached configuration has it.
 */
class InUseFlag
{
    public function __construct(
        private ScopeConfigInterface $scopeConfig,
        private ResourceConnection $resource,
        private WriterInterface $configWriter
    ) {
    }

    /**
     * @throws \Throwable when the flag cannot be written: the caller must not create the attempt then
     */
    public function ensure(int $storeId): void
    {
        if ($this->scopeConfig->isSetFlag(AplazoHelper::PAYMENT_FIRST_IN_USE_PATH, ScopeInterface::SCOPE_STORES, $storeId)
            || $this->isWritten($storeId)
        ) {
            return;
        }
        $this->configWriter->save(AplazoHelper::PAYMENT_FIRST_IN_USE_PATH, '1', ScopeInterface::SCOPE_STORES, $storeId);
    }

    private function isWritten(int $storeId): bool
    {
        $connection = $this->resource->getConnection();

        return (string)$connection->fetchOne(
            $connection->select()->from($this->resource->getTableName('core_config_data'), ['value'])
                ->where('path = ?', AplazoHelper::PAYMENT_FIRST_IN_USE_PATH)
                ->where('scope = ?', ScopeInterface::SCOPE_STORES)
                ->where('scope_id = ?', $storeId)
        ) === '1';
    }
}
