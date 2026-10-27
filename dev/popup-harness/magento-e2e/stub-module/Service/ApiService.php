<?php

namespace LocalDev\AplazoStub\Service;

/**
 * LOCAL TEST ONLY. Aplazo API without the network: auth always succeeds, createLoan returns the local mock
 * checkout URL, cancel/refund/status are recorded and answered locally. The HTTP methods refuse to run.
 */
class ApiService extends \Aplazo\AplazoPayment\Service\ApiService
{
    public const MOCK_CHECKOUT_URL = 'http://localhost:8099/mock-checkout.html';

    public function getAuthorizationToken()
    {
        StubLog::write('api', 'getAuthorizationToken');
        return 'local-stub-token';
    }

    public function createLoan($orderData, $tokenBearer)
    {
        $cartId = (string)($orderData['cartId'] ?? $orderData['shopId'] ?? '');
        // Demo only: when var/localdev_aplazo_stub_delay holds N, wait N seconds (keeps placeOrder in flight).
        $delayFile = BP . '/var/localdev_aplazo_stub_delay';
        $delay = is_file($delayFile) ? min(60, max(0, (int)trim((string)@file_get_contents($delayFile)))) : 0;
        StubLog::write('api', 'createLoan', ['cartId' => $cartId, 'delay_seconds' => $delay]);
        if ($delay > 0) {
            sleep($delay);
        }
        // var/localdev_aplazo_stub_checkout_url overrides the mock base URL, so a test browser that maps
        // checkout.aplazo.net to a local mock can exercise messages from the configured origin (BNPL-1664).
        $baseFile = BP . '/var/localdev_aplazo_stub_checkout_url';
        $base = is_file($baseFile) ? trim((string)@file_get_contents($baseFile)) : '';
        $base = $base !== '' ? $base : self::MOCK_CHECKOUT_URL;
        return ['url' => $base . '?quoteid=stub-' . rawurlencode($cartId), 'loanId' => 'stub-' . $cartId];
    }

    public function cancelLoan($orderData)
    {
        StubLog::write('api', 'cancelLoan', ['cartId' => (string)($orderData['cartId'] ?? '')]);
        return ['status' => true, 'message' => 'stub'];
    }

    public function createRefund($orderData, ?string $idempotencyKey = null)
    {
        StubLog::write('api', 'createRefund', ['cartId' => (string)($orderData['cartId'] ?? '')]);
        return ['status' => false, 'message' => 'stub: refunds are not simulated'];
    }

    /**
     * var/localdev_aplazo_stub_loan_status holds the loan status to answer (for example OUTSTANDING); without it the
     * loan is reported as not found. The plugin's own shouldCancelOrder() decides from this answer (BNPL-1665).
     */
    public function getLoanStatus($cartId)
    {
        $file = BP . '/var/localdev_aplazo_stub_loan_status';
        $status = is_file($file) ? trim((string)@file_get_contents($file)) : '';
        StubLog::write('api', 'getLoanStatus', ['cartId' => (string)$cartId, 'answer' => $status === '' ? 'not found' : $status]);
        return $status === '' ? false : [['status' => $status]];
    }

    public function requestMagentoCurl($url, $body, $method, $bearer, array $extraHeaders = [])
    {
        StubLog::write('api', 'BLOCKED requestMagentoCurl');
        throw new \LogicException('LocalDev_AplazoStub: outbound HTTP is disabled');
    }

    public function requestPHPCurl($url, $body, $method, $bearer)
    {
        StubLog::write('api', 'BLOCKED requestPHPCurl');
        throw new \LogicException('LocalDev_AplazoStub: outbound HTTP is disabled');
    }
}
