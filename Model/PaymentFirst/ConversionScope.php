<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Marks the moment Finalizer::convert() runs QuoteManagement::submit() inside its own transaction (BNPL-1664). Only
 * while it is active does DeferCouponUsagePublish hold Magento's coupon usage message back until that transaction
 * commits; everywhere else (redirect checkout, admin, cron, cancellations) nothing changes.
 */
class ConversionScope
{
    /**
     * @var AdapterInterface|null the connection of the conversion running now
     */
    private ?AdapterInterface $connection = null;

    /**
     * @var array{attempt_id: int, cart_id: string}|null the attempt being converted, for correlation only
     */
    private ?array $attempt = null;

    /**
     * Runs $callable as the conversion's submit, on the connection whose transaction the conversion opened.
     *
     * @param AdapterInterface $connection
     * @param int $attemptId
     * @param string $cartId
     * @param callable $callable
     * @return mixed what $callable returns
     */
    public function run(AdapterInterface $connection, int $attemptId, string $cartId, callable $callable)
    {
        if ($this->connection !== null) {
            throw new \LogicException('Nested payment-first conversion');
        }
        $this->connection = $connection;
        $this->attempt = ['attempt_id' => $attemptId, 'cart_id' => $cartId];
        try {
            return $callable();
        } finally {
            $this->connection = null;
            $this->attempt = null;
        }
    }

    /**
     * The connection of the conversion running now, or null outside one.
     */
    public function connection(): ?AdapterInterface
    {
        return $this->connection;
    }

    /**
     * The attempt of the conversion running now (attempt_id, cart_id), or null outside one.
     *
     * Arrays are copied on assignment, so a caller keeps its own copy after the conversion ends.
     *
     * @return array{attempt_id: int, cart_id: string}|null
     */
    public function attempt(): ?array
    {
        return $this->attempt;
    }
}
