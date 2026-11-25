<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The Aplazo context a payment-first loan belongs to (BNPL-1664, finding H-4): the environment (sandbox or production,
 * which decides the API host) and the merchant id, both read from the attempt store's configuration when the loan is
 * requested and kept on the attempt.
 *
 * The API credentials themselves (merchant id and the encrypted api token, website scope) are read from the store's
 * configuration at each call, as for every Aplazo call; they are never copied to the attempt. Before any later call
 * about the loan (status, cancel) the current context must equal the stored one: a configuration changed after the
 * start must not send the question to another environment or merchant. A mismatch is reported, never resolved by
 * choosing one side.
 */
class AttemptContext
{
    public function __construct(private ScopeConfigInterface $scopeConfig)
    {
    }

    /**
     * @return array{environment: string, merchant_id: string}
     */
    public function current(int $storeId): array
    {
        return [
            'environment' => $this->scopeConfig->isSetFlag('payment/aplazo_gateway/sanbox_mode', ScopeInterface::SCOPE_STORE, $storeId) ? 'stg' : 'prod',
            'merchant_id' => (string)$this->scopeConfig->getValue('payment/aplazo_gateway/merchantid', ScopeInterface::SCOPE_STORE, $storeId),
        ];
    }

    /**
     * @return string|null what changed ("environment", "merchant"), or null when the store still has the attempt's context
     */
    public function mismatch(array $attempt): ?string
    {
        $current = $this->current((int)$attempt['store_id']);
        if ((string)($attempt['environment'] ?? '') !== $current['environment']) {
            return 'environment';
        }
        if ((string)($attempt['merchant_id'] ?? '') !== $current['merchant_id']) {
            return 'merchant';
        }

        return null;
    }
}
