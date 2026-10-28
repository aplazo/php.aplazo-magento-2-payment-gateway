<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;

/**
 * Decides whether a validated Aplazo webhook is for a payment-first attempt or for a redirect order (BNPL-1664, H-9).
 *
 * The callback names only a cartId and, signed, a merchantId. An attempt's cartId is unique among attempts and was not
 * an order number in any store when it was allocated, but another store's own sequence can produce the same increment
 * later. When both an attempt and such an order exist, the signed merchantId decides only if exactly one side
 * belongs to that merchant; otherwise the webhook is refused as ambiguous, without choosing one. The redirect path
 * itself is not changed: it is used only when no attempt has the cartId, or when the attempt is clearly not this
 * merchant's and has no order of its own (so the redirect lookup by increment can only find the other order).
 */
class WebhookRouter
{
    public const ATTEMPT = 'attempt';
    public const REDIRECT = 'redirect';
    public const AMBIGUOUS = 'ambiguous';

    public function __construct(
        private AttemptStore $attemptStore,
        private ResourceConnection $resource,
        private ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @return array{route: string, attempt?: array, reason?: string}
     */
    public function route(string $cartId, array $claims): array
    {
        $attempt = $this->attemptStore->getByCartId($cartId);
        if ($attempt === null) {
            return ['route' => self::REDIRECT];
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()->from($this->resource->getTableName('sales_order'), ['entity_id', 'store_id'])
            ->where('increment_id = ?', $cartId);
        if (!empty($attempt['order_id'])) {
            $select->where('entity_id <> ?', (int)$attempt['order_id']);
        }
        $others = $connection->fetchAll($select);
        if ($others === []) {
            return ['route' => self::ATTEMPT, 'attempt' => $attempt];
        }
        $claimed = (string)($claims['merchantId'] ?? $claims['sub'] ?? '');
        $attemptMerchant = (string)($attempt['merchant_id'] ?: $this->merchantOf((int)$attempt['store_id']));
        $attemptIsMerchants = $claimed !== '' && $claimed === $attemptMerchant;
        $othersOfMerchant = array_filter($others, function ($order) use ($claimed) {
            return $claimed !== '' && $claimed === $this->merchantOf((int)$order['store_id']);
        });
        if ($attemptIsMerchants && $othersOfMerchant === []) {
            return ['route' => self::ATTEMPT, 'attempt' => $attempt];
        }
        if (!$attemptIsMerchants && count($othersOfMerchant) === 1 && count($others) === 1 && empty($attempt['order_id'])) {
            return ['route' => self::REDIRECT];
        }

        return ['route' => self::AMBIGUOUS, 'attempt' => $attempt, 'reason' => 'cart_id_matches_attempt_and_order'];
    }

    private function merchantOf(int $storeId): string
    {
        return (string)$this->scopeConfig->getValue('payment/aplazo_gateway/merchantid', ScopeInterface::SCOPE_STORE, $storeId);
    }
}
