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
 * Residual window: if the process dies after the commit and before the callback publishes, or the publication fails,
 * there is no message: the product's stock status stays as it was until Magento publishes another salability message
 * for the SKU (for example when the order is shipped or cancelled). A failure is logged; nothing retries it.
 */
class DeferredSalabilityPublisher implements PublisherInterface
{
    public const TOPIC = 'inventory.reservations.updateSalabilityStatus';

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
            $this->trace->add('error', 'Payment-first stock salability message not published', ['module:checkout'], [
                'attempt_id' => $attempt['attempt_id'] ?? null,
                'cart_id' => $attempt['cart_id'] ?? null,
                'stock_id' => is_object($data) && method_exists($data, 'getStock') ? $data->getStock() : null,
                'skus' => is_object($data) && method_exists($data, 'getSkus') ? implode(',', $data->getSkus()) : null,
                'error' => get_class($e),
            ]);
        } finally {
            while ($connection->getTransactionLevel() > $level) {
                $connection->rollBack();
            }
        }
    }
}
