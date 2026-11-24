<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Setup\Patch\Data;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Aplazo\AplazoPayment\Model\PaymentFirst\AttemptStore;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * BNPL-1664: payment_first_in_use for stores whose popup payment-first attempts were created before 4.0.14.
 *
 * Since 4.0.14 the popup start writes payment/aplazo_gateway/payment_first_in_use = 1 (store scope) before a store's
 * first attempt, and the reconciliation cron, the webhook routing and KeepConvertedQuoteClosed look after a store's
 * attempts while that flag is set, even with popup turned off. Attempts created by earlier builds have no flag, so they
 * would be ignored once popup is turned off. This patch sets the flag for every existing store that still has an
 * attempt in a reconciled state (AttemptStore::RECONCILED). Stores without such attempts are not touched (no new query
 * on their cart saves).
 *
 * Idempotent: insert-or-update of the same value; running it again changes nothing. Magento runs a data patch once
 * (patch_list) and cleans the caches at the end of setup:upgrade, so the cached configuration sees the flag.
 */
class MarkPaymentFirstInUseStores implements DataPatchInterface
{
    public function __construct(private ModuleDataSetupInterface $moduleDataSetup)
    {
    }

    /**
     * @inheritDoc
     */
    public function apply()
    {
        $this->moduleDataSetup->startSetup();
        try {
            $connection = $this->moduleDataSetup->getConnection();
            $attempts = $this->moduleDataSetup->getTable('aplazo_payment_attempt');
            if (!$connection->isTableExists($attempts)) {
                return $this;
            }
            $storeIds = $connection->fetchCol(
                $connection->select()->distinct()
                    ->from(['a' => $attempts], ['store_id'])
                    ->join(['s' => $this->moduleDataSetup->getTable('store')], 's.store_id = a.store_id', [])
                    ->where('a.status IN (?)', AttemptStore::RECONCILED)
                    ->where('a.store_id > 0')
            );
            $rows = [];
            foreach ($storeIds as $storeId) {
                $rows[] = [
                    'scope' => ScopeInterface::SCOPE_STORES,
                    'scope_id' => (int)$storeId,
                    'path' => AplazoHelper::PAYMENT_FIRST_IN_USE_PATH,
                    'value' => '1',
                ];
            }
            if ($rows) {
                $connection->insertOnDuplicate($this->moduleDataSetup->getTable('core_config_data'), $rows, ['value']);
            }
        } finally {
            $this->moduleDataSetup->endSetup();
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases()
    {
        return [];
    }
}
