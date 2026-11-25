<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Magento\Framework\App\Area;
use Magento\Store\Model\App\Emulation;

/**
 * Runs payment-first work in the attempt's store, whatever request started it (checkout page, webhook, cron).
 *
 * Magento allows a single level of emulation and ignores a nested start, so the inner stop would end the outer
 * emulation; this keeps one level and runs nested calls for the same store directly. Emulation only sets the store,
 * design and locale: the customer, group and validations come from the quote itself.
 */
class StoreScope
{
    private ?int $current = null;

    public function __construct(private Emulation $emulation)
    {
    }

    public function run(int $storeId, callable $callable)
    {
        if ($this->current !== null) {
            if ($this->current !== $storeId) {
                throw new \LogicException('Nested payment-first work for another store');
            }

            return $callable();
        }
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        $this->current = $storeId;
        try {
            return $callable();
        } finally {
            $this->current = null;
            $this->emulation->stopEnvironmentEmulation();
        }
    }
}
