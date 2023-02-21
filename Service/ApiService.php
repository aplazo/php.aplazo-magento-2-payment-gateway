<?php

namespace Aplazo\AplazoPayment\Service;

use Magento\Framework\App\Cache\Type\Config as Cache;
use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\AuthenticationException;

class ApiService
{
    const TOKEN_CACHE_KEY = 'aplazo_api_token';

    /**
     * @var Cache
     */
    private $cache;

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

    public function __construct
    (
        Cache $cacheManager,
        AplazoHelper $aplazoHelper
    )
    {
        $this->cache = $cacheManager;
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
        $response = $this->request(
            $this->getRefundLoanUrl(),
            json_encode($orderData),
            ['Content-Type: application/json',
                'merchant_id' => $this->aplazoHelper->getMerchantId(),
                'api_token' => $this->aplazoHelper->getApiToken(),]
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
            $authToken = $this->setAuthorizationToken($response['Authorization']);
        } catch (LocalizedException $e) {
            throw new AuthenticationException(__('Unable to retrieve Aplazo API token. ' . $e->getMessage()));
        }
        return $authToken;
    }

    /**
     * @return string
     */
    private function getTokenFromCache(): string
    {
        $authToken = '';
        if ($this->cache->test(self::TOKEN_CACHE_KEY)) {
            $authToken = $this->cache->load(self::TOKEN_CACHE_KEY);
            $authToken = $this->setAuthorizationToken($authToken);
        }
        return $authToken;
    }

    /**
     * @param string
     */
    private function setAuthorizationToken($authToken): string
    {
        $this->accessToken = $authToken;
        $this->cache->save($authToken, self::TOKEN_CACHE_KEY, [],10000);
        return $this->accessToken;
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
}
