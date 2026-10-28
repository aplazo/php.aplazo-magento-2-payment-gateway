<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

/**
 * How often the reconciliation asks Aplazo about a payment-first attempt (BNPL-1664).
 *
 * This is only the polling cadence of this module; it decides nothing about the loan. An attempt is never closed, and
 * a loan never considered unpaid, because time passed: Aplazo's checkout validity and loan expiry are not known
 * (contract C3). The delays grow with the number of checks up to a ceiling and keep that ceiling afterwards.
 * The test kit replaces the delays through di.xml (constructor arguments).
 */
class ReconcileSchedule
{
    /**
     * @param int $startGraceSeconds how long a request still creating the loan is left alone
     * @param int[] $delaysSeconds delay after the 1st, 2nd, ... check; the last one repeats
     */
    public function __construct(
        private int $startGraceSeconds = 120,
        private array $delaysSeconds = [60, 120, 300, 900, 1800, 3600, 10800]
    ) {
    }

    public function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public function afterStart(): string
    {
        return gmdate('Y-m-d H:i:s', time() + $this->startGraceSeconds);
    }

    public function afterCheck(int $checkCount): string
    {
        $delays = array_values(array_map('intval', $this->delaysSeconds)) ?: [3600];
        $delay = $delays[min(max($checkCount - 1, 0), count($delays) - 1)];

        return gmdate('Y-m-d H:i:s', time() + $delay);
    }
}
