<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Plugin\PaymentFirst;

use Aplazo\AplazoPayment\Model\PaymentFirst\ConversionScope;
use Aplazo\AplazoPayment\Model\PaymentFirst\Trace;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\SalesRule\Model\Coupon\Usage\UpdateInfo;
use Magento\SalesRule\Model\Coupon\Usage\UpdateInfoFactory;
use Magento\SalesRule\Model\ResourceModel\Coupon as CouponResource;
use Magento\SalesRule\Model\Service\CouponUsagePublisher;

/**
 * Holds Magento's coupon usage message back until a payment-first conversion commits (BNPL-1664).
 *
 * QuoteManagement::submit() writes the coupon and per-customer counters in the conversion's transaction and publishes
 * the rule usage message (sales.rule.update.coupon.usage) to the queue at once. If the conversion then rolls back, or
 * the process dies, the counters are rolled back but the message is already queued, and its consumer counts the rule
 * once more for an order that does not exist. While a conversion runs (ConversionScope), the message is published from
 * an after-commit callback instead: Magento runs it after the outermost commit (ExecuteCommitCallbacks) and drops it on
 * rollback. Outside a conversion, or if the coupon resource does not share the conversion's connection, publish() runs
 * as before.
 *
 * Residual window: if the process dies after the commit and before the callback publishes, or the publication fails,
 * the order and the synchronous counters stay and the rule usage is not counted (one fewer). That is logged; nothing
 * retries it.
 */
class DeferCouponUsagePublish
{
    /**
     * @param ConversionScope $scope
     * @param CouponResource $couponResource a resource model on the coupon tables, used for addCommitCallback()
     * @param UpdateInfoFactory $updateInfoFactory
     * @param Trace $trace
     */
    public function __construct(
        private ConversionScope $scope,
        private CouponResource $couponResource,
        private UpdateInfoFactory $updateInfoFactory,
        private Trace $trace
    ) {
    }

    /**
     * Publishes at once outside a conversion, or registers the publication for after the conversion's commit.
     *
     * @param CouponUsagePublisher $subject
     * @param callable $proceed
     * @param UpdateInfo $updateInfo
     * @return bool outside a conversion, publish()'s own result; inside one, true when the callback was registered
     */
    public function aroundPublish(CouponUsagePublisher $subject, callable $proceed, UpdateInfo $updateInfo): bool
    {
        $connection = $this->scope->connection();
        $defer = $connection !== null && $connection->getTransactionLevel() > 0
            && $this->couponResource->getConnection() === $connection;
        if (!$defer) {
            return $proceed($updateInfo);
        }
        // Own copies, taken now: the caller may change or reuse its object before the commit, and the scope is
        // already closed when the callback runs.
        $copy = $this->updateInfoFactory->create();
        $copy->setData($updateInfo->getData());
        $attempt = $this->scope->attempt() ?? [];
        $this->couponResource->addCommitCallback(function () use ($subject, $copy, $connection, $attempt) {
            $this->publishAfterCommit($subject, $copy, $connection, $attempt);
        });

        return true;
    }

    /**
     * Runs after the conversion committed (transaction level 0). scheduleBulk() opens its own transaction and catches
     * \Exception but not \Error: whatever happens, the connection is left at the level it had before publishing, so
     * nothing written afterwards in this process ends up in a transaction that is never committed.
     *
     * @param CouponUsagePublisher $subject
     * @param UpdateInfo $updateInfo
     * @param AdapterInterface $connection
     * @param array $attempt attempt_id and cart_id of the conversion, captured when the callback was registered
     * @return void
     */
    private function publishAfterCommit(
        CouponUsagePublisher $subject,
        UpdateInfo $updateInfo,
        AdapterInterface $connection,
        array $attempt
    ): void {
        $level = $connection->getTransactionLevel();
        $published = false;
        $error = null;
        try {
            $published = $subject->publish($updateInfo);
        } catch (\Throwable $e) {
            $error = get_class($e);
        } finally {
            while ($connection->getTransactionLevel() > $level) {
                $connection->rollBack();
            }
        }
        if ($published !== true) {
            $this->trace->add('error', 'Payment-first coupon usage message not published', ['module:checkout'], [
                'attempt_id' => $attempt['attempt_id'] ?? null,
                'cart_id' => $attempt['cart_id'] ?? null,
                'rule_ids' => implode(',', $updateInfo->getAppliedRuleIds()),
                'increment' => $updateInfo->isIncrement(),
                'error' => $error ?? 'publish returned false',
            ]);
        }
    }
}
