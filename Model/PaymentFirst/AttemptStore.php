<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Magento\Framework\App\ResourceConnection;
use Magento\SalesSequence\Model\Manager as SequenceManager;

/**
 * Popup payment-first attempts (BNPL-1664): one row per loan requested from a quote.
 *
 * The row is written before createLoan, so a lost response or an early webhook can still be matched by its cartId.
 * Every state change is a compare-and-set on `version`: a decision taken on data read earlier (for example a loan
 * status queried before another process converted the quote) is never written over a newer state.
 */
class AttemptStore
{
    public const TABLE = 'aplazo_payment_attempt';

    /** Row written, createLoan not answered yet. */
    public const STARTING = 'starting';
    /** Loan created and its checkout URL validated. */
    public const OPEN = 'open';
    /** createLoan failed, timed out or answered something unusable: the loan may exist. */
    public const UNCERTAIN = 'uncertain';
    /** A newer attempt was started for the same quote with other checkout data; this loan may still be paid. */
    public const SUPERSEDED = 'superseded';
    /** Order saved; approval (invoice, status, email) not finished. */
    public const ORDER_CREATED = 'order_created';
    public const COMPLETED = 'completed';
    /** Paid, but the quote could not become this attempt's order. The loan needs a reversal (contract C5). */
    public const REJECTED = 'rejected';
    /** Inconsistent data (for example another loan id or another order for the cartId): an operator decides. */
    public const NEEDS_REVIEW = 'needs_review';

    /** States the reconciliation keeps checking. A rejected attempt is checked only while its reversal is open. */
    public const RECONCILED = [self::STARTING, self::OPEN, self::UNCERTAIN, self::SUPERSEDED, self::ORDER_CREATED, self::REJECTED];

    /** States in which the loan can still be paid and the attempt converted. */
    public const PAYABLE = [self::STARTING, self::OPEN, self::UNCERTAIN, self::SUPERSEDED];

    public const CANCEL_PENDING = 'pending';
    public const CANCEL_REQUESTED = 'requested';
    public const CANCEL_ACCEPTED = 'accepted';
    public const CANCEL_FAILED = 'failed';
    public const CANCEL_UNKNOWN = 'unknown';
    public const CANCEL_OPEN = [self::CANCEL_PENDING, self::CANCEL_FAILED, self::CANCEL_UNKNOWN];

    private const CART_ID_ALLOCATION_TRIES = 5;

    public function __construct(
        private ResourceConnection $resource,
        private SequenceManager $sequenceManager
    ) {
    }

    /**
     * Allocates a new order increment id for the store as this attempt's cartId (the same sequence
     * Quote::reserveOrderId uses), so the order is later created with increment_id = cartId and refunds, cancellation
     * and the existing cron keep using the increment id. It is never written to the quote before conversion, so a
     * redirect order placed from the same quote gets another number. Unique among attempts and all stores' orders.
     */
    public function create(array $data): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        for ($try = 0; $try < self::CART_ID_ALLOCATION_TRIES; $try++) {
            $cartId = (string)$this->sequenceManager->getSequence('order', (int)$data['store_id'])->getNextValue();
            $used = $connection->fetchOne(
                $connection->select()->from($this->resource->getTableName('sales_order'), ['entity_id'])
                    ->where('increment_id = ?', $cartId)->limit(1)
            );
            if ($used) {
                continue;
            }
            $row = $data + [
                'public_id' => bin2hex(random_bytes(16)),
                'cart_id' => $cartId,
                'status' => self::STARTING,
                'version' => 0,
                'next_check_at' => $data['next_check_at'] ?? null,
            ];
            try {
                $connection->insert($table, $row);
            } catch (\Magento\Framework\DB\Adapter\DuplicateException $e) {
                continue;
            }

            return $this->get((int)$connection->lastInsertId($table));
        }
        throw new \RuntimeException('Could not allocate a cartId for the payment attempt');
    }

    public function get(int $id): ?array
    {
        return $this->fetch('entity_id = ?', $id);
    }

    public function getByPublicId(string $publicId): ?array
    {
        return preg_match('/^[0-9a-f]{32}$/', $publicId) === 1 ? $this->fetch('public_id = ?', $publicId) : null;
    }

    public function getByCartId(string $cartId): ?array
    {
        return $cartId === '' ? null : $this->fetch('cart_id = ?', $cartId);
    }

    /**
     * @return array[] newest first
     */
    public function getForQuote(int $quoteId, array $statuses): array
    {
        $connection = $this->resource->getConnection();

        return $connection->fetchAll(
            $connection->select()->from($this->resource->getTableName(self::TABLE))
                ->where('quote_id = ?', $quoteId)->where('status IN (?)', $statuses)->order('entity_id DESC')
        );
    }

    /**
     * Whether an attempt of this quote already has an order (the quote was converted by the finalizer).
     */
    public function hasOrderForQuote(int $quoteId): bool
    {
        $connection = $this->resource->getConnection();

        return (bool)$connection->fetchOne(
            $connection->select()->from($this->resource->getTableName(self::TABLE), ['entity_id'])
                ->where('quote_id = ?', $quoteId)->where('order_id IS NOT NULL')->limit(1)
        );
    }

    /**
     * Attempts whose next reconciliation check is due. Rejected attempts only while their reversal is open.
     *
     * @return array[]
     */
    public function getDue(int $limit, ?array $onlyIds = null): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()->from($this->resource->getTableName(self::TABLE))
            ->where('status IN (?)', self::RECONCILED)
            ->where($connection->quoteInto('status <> ?', self::REJECTED)
                . ' OR ' . $connection->quoteInto('loan_cancel_state IN (?)', self::CANCEL_OPEN))
            ->where('next_check_at IS NULL OR next_check_at <= ?', gmdate('Y-m-d H:i:s'))
            ->order('next_check_at ASC')->limit($limit);
        if ($onlyIds !== null) {
            $select->where('entity_id IN (?)', $onlyIds ?: [0]);
        }

        return $connection->fetchAll($select);
    }

    /**
     * Compare-and-set: applies $changes only if the row still has the version that was read.
     *
     * @return array|null the updated row, or null when another process changed it first
     */
    public function transition(array $attempt, array $changes): ?array
    {
        $connection = $this->resource->getConnection();
        $changes['version'] = new \Zend_Db_Expr('version + 1');
        $updated = $connection->update(
            $this->resource->getTableName(self::TABLE),
            $changes,
            ['entity_id = ?' => (int)$attempt['entity_id'], 'version = ?' => (int)$attempt['version']]
        );

        return $updated === 1 ? $this->get((int)$attempt['entity_id']) : null;
    }

    private function fetch(string $condition, $value): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->resource->getTableName(self::TABLE))->where($condition, $value)
        );

        return $row ?: null;
    }
}
