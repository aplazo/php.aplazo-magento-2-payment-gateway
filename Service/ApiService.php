<?php

namespace Aplazo\AplazoPayment\Service;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\HTTP\Client\Curl;

class ApiService
{
    const TOKEN_CACHE_KEY = 'aplazo_api_token';

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
     * @return string
     * @throws LocalizedException
     */
    public function getAuthorizationToken(): string
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
     * @return string
     */
    private function getAuthorizationUrl(): string
    {
        return $this->aplazoHelper->getServiceUrl() . '/api/auth';
    }

    /**
     * @return string
     */
    private function getCreateLoanUrl(): string
    {
        return $this->aplazoHelper->getServiceUrl() . '/api/loan';
    }

    /**
     * @return string
     */
    private function getRefundLoanUrl(): string
    {
        return $this->aplazoHelper->getServiceUrl() . '/api/pos/loan/refund';
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
            'Content-Type: application/json'
        ]);
        $this->curl->post($url, $body);

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
