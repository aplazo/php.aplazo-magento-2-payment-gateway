<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;

/**
 * Publisher given by DI only to MSI's EnqueueAfterPlaceReservationsForSalesEvent (BNPL-1664). It holds the
 * inventory.reservations.updateSalabilityStatus message of a payment-first conversion back until that conversion's
 * transaction commits.
 *
 * MSI writes the order's reservation inside Finalizer::convert()'s transaction and publishes this message at once. A
 * consumer that takes it before the commit does not see the reservation, recalculates the old salability and
 * acknowledges it; after the commit nothing is left to mark the last unit as not salable. While ConversionScope is
 * active, the original message is published from an after-commit callback instead (ExecuteCommitCallbacks runs it after
 * the outermost commit and drops it on rollback). Every other topic, and this topic outside a conversion (redirect
 * checkout, admin, cancellations, shipments), goes to the original publisher at once.
 *
 * If the publication fails, the update the message stands for is applied in this process instead: MSI's own handler
 * of the topic (UpdateIndexSalabilityStatus::execute, what the consumer runs) recalculates the salability of those SKUs
 * from the committed source quantities and reservations. It counts nothing, so running it after a publication that
 * did reach the broker (an exception after delivery) or twice gives the same result. The handler is resolved only on
 * that path and only if MSI's class exists, as the module resolves other optional modules (no MSI type in the
 * constructor, so this class still compiles without MSI).
 *
 * Residual window: if the process dies after the commit and before the callback runs, or the publication and the
 * in-process update both fail, the product's stock status stays as it was until Magento publishes another salability
 * message for the SKU (for example when the order is shipped or cancelled). Both failures are logged; nothing retries.
 */
class DeferredSalabilityPublisher implements PublisherInterface
{
    public const TOPIC = 'inventory.reservations.updateSalabilityStatus';
    /** MSI's handler of TOPIC (communication.xml), what the consumer runs for each message. */
    private const HANDLER = 'Magento\\InventoryIndexer\\Model\\Queue\\UpdateIndexSalabilityStatus';

    /**
     * @param PublisherInterface $publisher the original publisher (Magento's preference)
     * @param ConversionScope $scope
     * @param QuoteResource $quoteResource a resource model on the default connection, used for addCommitCallback()
     * @param Trace $trace
     */
    public function __construct(
        private PublisherInterface $publisher,
        private ConversionScope $scope,
        private QuoteResource $quoteResource,
        private Trace $trace
    ) {
    }

    /**
     * Publishes at once, or, for the salability topic during a conversion, after the conversion's commit.
     *
     * @param string $topicName
     * @param mixed $data
     * @return mixed what the original publisher returns; null when the publication was deferred
     */
    public function publish($topicName, $data)
    {
        $connection = $this->scope->connection();
        $defer = $topicName === self::TOPIC && $connection !== null && $connection->getTransactionLevel() > 0
            && $this->quoteResource->getConnection() === $connection;
        if (!$defer) {
            return $this->publisher->publish($topicName, $data);
        }
        // Taken now: the scope is already closed when the callback runs. The message object is MSI's own
        // ReservationData (read-only), published unchanged.
        $attempt = $this->scope->attempt() ?? [];
        $this->quoteResource->addCommitCallback(function () use ($topicName, $data, $connection, $attempt) {
            $this->publishAfterCommit($topicName, $data, $connection, $attempt);
        });

        return null;
    }

    /**
     * Runs after the conversion committed. An asynchronous publication returns nothing; only an exception is a failure.
     * The connection is left at the level it had before publishing, so nothing written afterwards in this process ends
     * up in a transaction that is never committed.
     *
     * @param string $topicName
     * @param mixed $data
     * @param AdapterInterface $connection
     * @param array $attempt attempt_id and cart_id of the conversion, captured when the callback was registered
     * @return void
     */
    private function publishAfterCommit(string $topicName, $data, AdapterInterface $connection, array $attempt): void
    {
        $level = $connection->getTransactionLevel();
        try {
            $this->publisher->publish($topicName, $data);
        } catch (\Throwable $e) {
            $context = [
                'attempt_id' => $attempt['attempt_id'] ?? null,
                'cart_id' => $attempt['cart_id'] ?? null,
                'stock_id' => is_object($data) && method_exists($data, 'getStock') ? $data->getStock() : null,
                'skus' => is_object($data) && method_exists($data, 'getSkus') ? implode(',', $data->getSkus()) : null,
                'error' => get_class($e),
            ];
            try {
                $this->applyInProcess($data);
                $this->trace->add('warn', 'Payment-first stock salability message not published; applied in process', ['module:checkout'], $context);
            } catch (\Throwable $fallback) {
                $this->trace->add('error', 'Payment-first stock salability message not published', ['module:checkout'],
                    $context + ['fallback_error' => get_class($fallback)]);
            }
        } finally {
            while ($connection->getTransactionLevel() > $level) {
                $connection->rollBack();
            }
        }
    }

    /**
     * @param mixed $data MSI's ReservationData
     * @throws \RuntimeException when MSI's handler is not available
     */
    private function applyInProcess($data): void
    {
        if (!class_exists(self::HANDLER)) {
            throw new \RuntimeException('MSI salability handler not available');
        }
        \Magento\Framework\App\ObjectManager::getInstance()->get(self::HANDLER)->execute($data);
    }
}
