<?php

namespace LocalDev\AplazoStub\Observer;

use LocalDev\AplazoStub\Service\StubLog;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * LOCAL TEST ONLY (BNPL-1664, coupon/after-commit). On checkout_submit_all_after (dispatched by Finalizer::convert()
 * right after its commit, and by Magento's own checkout after placing an order) records the transaction level and how
 * many after-commit callbacks Magento's CallbackPool still holds for the default connection. Read-only (reflection).
 */
class CallbackPoolProbe implements ObserverInterface
{
    public function __construct(private ResourceConnection $resource)
    {
    }

    public function execute(Observer $observer)
    {
        $connection = $this->resource->getConnection();
        $prop = new \ReflectionProperty(\Magento\Framework\Model\CallbackPool::class, 'commitCallbacks');
        $prop->setAccessible(true);
        $pool = $prop->getValue();
        $order = $observer->getEvent()->getOrder();
        StubLog::write('probe', 'callback_pool_after_submit', [
            'order_id' => $order ? (string)$order->getIncrementId() : '',
            'transaction_level' => $connection->getTransactionLevel(),
            'pending_callbacks' => count($pool[spl_object_hash($connection)] ?? []),
        ]);
    }
}
