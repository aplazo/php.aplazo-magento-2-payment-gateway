<?php

namespace Aplazo\AplazoPayment\Service;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\HTTP\Client\Curl;

class ApiService
{
    const TOKEN_CACHE_KEY = 'aplazo_api_token';
    const LOAN_SUCCESS_STATUS = 'OUTSTANDING';

    /**
     * @var AplazoHelper
     */
    private $aplazoHelper;

    /**
     * @var string
     */
    private $accessToken;

    /**
     * @var array
     */
    private $merchant;
    /**
     * @var Curl
     */
    private $curl;

    public function __construct
    (
        Curl $curl,
        AplazoHelper $aplazoHelper
    )
    {
        $this->curl = $curl;
        $this->aplazoHelper = $aplazoHelper;
    }

    /**
     * @param $orderData
     * @return array
     * @throws LocalizedException
     */
    public function createLoan($orderData){
        $response = [];
        if($authToken = $this->getAuthorizationToken()) {
            $response = $this->request(
                $this->getCreateLoanUrl(),
                json_encode($orderData),
                ['Content-Type: application/json','Authorization: ' . $authToken]
            );
        }

        return $response;
    }

    /**
     * @param $orderData
     * @return array
     * @throws LocalizedException
     */
    public function createRefund($orderData){
        $response = $this->requestRefund(
            $this->getRefundLoanUrl(),
            json_encode($orderData),
            [
                'merchant_id' => $this->aplazoHelper->getMerchantId(),
                'api_token' => $this->aplazoHelper->getApiToken(),
                'Content-Type: application/json'
            ]
        );

        return $response;
    }

    /**
     * @param $orderData
     * @return array
     * @throws LocalizedException
     */
    public function cancelLoan($orderData){
        $response = $this->requestRefund(
            $this->getCancelLoanUrl(),
            json_encode($orderData),
            [
                'merchant_id' => $this->aplazoHelper->getMerchantId(),
                'api_token' => $this->aplazoHelper->getApiToken(),
                'Content-Type: application/json'
            ]
        );

        return $response;
    }

    /**
     * @return string
     * @throws LocalizedException
     */
    public function getAuthorizationToken()
    {
        $authToken = $this->createToken();
        return $authToken;
    }

    /**
     * @return string
     * @throws LocalizedException
     */
    private function createToken()
    {
        try {
            $response = $this->request($this->getAuthorizationUrl(), json_encode(['apiToken' => $this->aplazoHelper->getApiToken(),'merchantId' => $this->aplazoHelper->getMerchantId()]), ['Content-Type: application/json']);
            if (!isset($response['Authorization'])) {
                throw new AuthenticationException(__('No token returned from ' . $this->getAuthorizationUrl()));
            }
            $authToken = $response['Authorization'];
        } catch (LocalizedException $e) {
            throw new AuthenticationException(__('Unable to retrieve Aplazo API token. ' . $e->getMessage()));
        }
        return $authToken;
    }

    /**
     * @return bool
     */
    public function getLoanStatus($cartId)
    {
        try {
            $response = $this->requestRefund($this->getLoanStatusUrl($cartId), '', [], 'GET');
        } catch (LocalizedException $e) {
            $this->aplazoHelper->log("Aplazo communication failed in cartId loan status $cartId " . $e->getMessage());
            return false;
        }
        // An Aplazo response could have more than one loan with the same increment_id. With at least one in OUTSTANDING status, the order means that is paid.
        foreach($response as $index => $loan){
            if (isset($loan['status'])) {
                if($loan['status'] === self::LOAN_SUCCESS_STATUS){
                    $this->aplazoHelper->log("Loan status for index [$index] is OUTSTANDING. Cart ID $cartId must not be cancelled.");
                    return true;
                }
                $this->aplazoHelper->log("Loan status is for index [$index] " . $loan['status'] . ". Cart ID $cartId must be cancelled.");
            } else {
                $this->aplazoHelper->log("Loan not found. Cart ID $cartId must be cancelled.");
            }
        }
        return false;
    }


    /**
     * @return string
     */
    private function getAuthorizationUrl()
    {
        return $this->aplazoHelper->getServiceUrl() . '/api/auth';
    }

    /**
     * @return string
     */
    private function getCreateLoanUrl()
    {
        return $this->aplazoHelper->getServiceUrl() . '/api/loan';
    }

    /**
     * @return string
     */
    private function getRefundLoanUrl()
    {
        return $this->aplazoHelper->getServiceUrl() . '/api/pos/loan/refund';
    }

    /**
     * @return string
     */
    private function getCancelLoanUrl()
    {
        return $this->aplazoHelper->getServiceUrl() . '/api/pos/loan/cancel';
    }

    /**
     * @return string
     */
    private function getLoanStatusUrl($cartId)
    {
        return $this->aplazoHelper->getServiceUrl() . "/api/pos/loan/$cartId";
    }

    /**
     * @param $url
     * @param $body
     * @param array $headers
     * @param string $method
     * @return array
     * @throws LocalizedException
     */
    private function request($url, $body, $headers = [], $method = 'POST')
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
        ));

        $response = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);

        $this->aplazoHelper->log("From: \Aplazo\AplazoPayment\Service\ApiService::request\nURL: $url\nMETHOD: $method\nREQUEST: $body\nRESPONSE:$response");

        if (!$response) {
            throw new LocalizedException(__('No response from request to ' . $url));
        }

        if (!empty($error)) {
            throw new LocalizedException(__('Error returned with request to ' . $url . '. Error: ' . $error));
        }

        return json_decode($response,true);
    }

    private function requestRefund($url, $body, $headers = [], $method = 'POST')
    {
        $this->curl->setHeaders( [
            'merchant_id' => $this->aplazoHelper->getMerchantId(),
            'api_token' => $this->aplazoHelper->getApiToken(),
            'Content-Type' => 'application/json'
        ]);
        if($method === 'POST'){
            $this->curl->post($url, $body);
        } else {
            $this->curl->get($url);
        }

        $response = $this->curl->getBody();

        $this->aplazoHelper->log("From: \Aplazo\AplazoPayment\Service\ApiService::request\nURL: $url\nMETHOD: $method\nREQUEST: $body\nRESPONSE:$response");

        if (!$response) {
            throw new LocalizedException(__('No response from request to ' . $url));
        }

        if (!empty($error)) {
            throw new LocalizedException(__('Error returned with request to ' . $url . '. Error: ' . $error));
        }

        return json_decode($response,true);
    }
}
