<?php

namespace LocalDev\AplazoStub\Service;

/**
 * LOCAL TEST ONLY. Aplazo API without the network: auth always succeeds, createLoan returns the local mock
 * checkout URL, cancel/refund/status are recorded and answered locally. The HTTP methods refuse to run.
 */
class ApiService extends \Aplazo\AplazoPayment\Service\ApiService
{
    public const MOCK_CHECKOUT_URL = 'http://localhost:8099/mock-checkout.html';

    // Same signature as master's ApiService since BNPL-1928 (4.0.13); still compatible with the 4.0.12 parent.
    public function getAuthorizationToken(bool $useCache = true)
    {
        StubLog::write('api', 'getAuthorizationToken');
        return 'local-stub-token';
    }

    public function createLoan($orderData, $tokenBearer)
    {
        $cartId = (string)($orderData['cartId'] ?? $orderData['shopId'] ?? '');
        // BNPL-1664 payment-first: var/localdev_aplazo_stub_createloan = fail (exception, like a timeout) | nourl |
        // crash (the PHP process exits during the call, as a request killed while waiting for Aplazo; the file is removed).
        $mode = trim((string)@file_get_contents(BP . '/var/localdev_aplazo_stub_createloan'));
        if ($mode === 'crash') {
            @unlink(BP . '/var/localdev_aplazo_stub_createloan'); // nosemgrep: php.lang.security.unlink-use.unlink-use -- local test kit (dev/ is export-ignore): fixed switch file under var/
            StubLog::write('api', 'createLoan', ['cartId' => $cartId, 'simulated' => 'crash']);
            exit(1);
        }
        if ($mode === 'fail') {
            StubLog::write('api', 'createLoan', ['cartId' => $cartId, 'simulated' => 'fail']);
            throw new \Magento\Framework\Exception\LocalizedException(__('stub: createLoan failed'));
        }
        if ($mode === 'nourl') {
            StubLog::write('api', 'createLoan', ['cartId' => $cartId, 'simulated' => 'nourl']);
            return ['loanId' => 'stub-' . $cartId];
        }
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
        if (trim((string)@file_get_contents(BP . '/var/localdev_aplazo_stub_cancel')) === 'fail') {
            throw new \Magento\Framework\Exception\LocalizedException(__('stub: cancelLoan failed'));
        }
        return ['status' => true, 'message' => 'stub'];
    }

    public function createRefund($orderData, ?string $idempotencyKey = null)
    {
        StubLog::write('api', 'createRefund', ['cartId' => (string)($orderData['cartId'] ?? '')]);
        return ['status' => false, 'message' => 'stub: refunds are not simulated'];
    }

    /**
     * The loan status to answer: var/localdev_aplazo_stub_loans/<cartId> (BNPL-1664, one per attempt), else
     * var/localdev_aplazo_stub_loan_status (BNPL-1665, global). Values: a status such as OUTSTANDING or REQUEST (one
     * loan, with the loanId this stub's createLoan gives: stub-<cartId>), ERROR (the plugin's "communication failed",
     * false), EMPTY (an empty list), or LIST:<loanId>=<STATUS>,... for several loans under the same cartId, as STG
     * answers (R-10): <loanId> `own` is stub-<cartId>, `none` an entry without loanId; numeric ids are sent as integers,
     * as STG does. Without a file: not found (false).
     */
    public function getLoanStatus($cartId)
    {
        $perCart = BP . '/var/localdev_aplazo_stub_loans/' . preg_replace('/[^0-9A-Za-z-]/', '', (string)$cartId);
        $file = is_file($perCart) ? $perCart : BP . '/var/localdev_aplazo_stub_loan_status';
        $status = is_file($file) ? trim((string)@file_get_contents($file)) : '';
        StubLog::write('api', 'getLoanStatus', ['cartId' => (string)$cartId, 'answer' => $status === '' ? 'not found' : $status]);
        if ($status === '' || $status === 'ERROR') {
            return false;
        }

        if ($status === 'EMPTY') {
            return [];
        }
        if (strpos($status, 'LIST:') !== 0) {
            return [['loanId' => 'stub-' . $cartId, 'status' => $status]];
        }
        $loans = [];
        foreach (array_filter(explode(',', substr($status, 5))) as $entry) {
            [$id, $loanStatus] = array_pad(explode('=', $entry, 2), 2, '');
            if ($id === 'none') {
                $loans[] = ['status' => $loanStatus];
                continue;
            }
            $id = $id === 'own' ? 'stub-' . $cartId : $id;
            $loans[] = ['loanId' => ctype_digit($id) ? (int)$id : $id, 'status' => $loanStatus];
        }

        return $loans;
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
