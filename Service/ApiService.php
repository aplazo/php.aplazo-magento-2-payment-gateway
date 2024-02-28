<?php

namespace Aplazo\AplazoPayment\Service;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\HTTP\Client\Curl;

class ApiService
{
    const TOKEN_CACHE_KEY = 'aplazo_api_token';
    const API_POST = 'POST';
    const LOAN_SUCCESS_STATUS = 'OUTSTANDING';

    /**
     * @var AplazoHelper
     */
    private $aplazoHelper;

    /**
     * @var Curl
     */
    private $curl;

    public function __construct
    (
        Curl         $curl,
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
    public function createLoan($orderData)
    {
        $response = [];
        if ($authToken = $this->getAuthorizationToken()) {
            $response = $this->requestService(
                $this->getCreateLoanUrl(),
                json_encode($orderData),
                self::API_POST,
                $authToken
            );
        }

        return $response;
    }

    /**
     * @param $orderData
     * @return array
     * @throws LocalizedException
     */
    public function createRefund($orderData)
    {
        $response = $this->requestService(
            $this->getRefundLoanUrl(),
            json_encode($orderData)
        );

        return $response;
    }

    /**
     * @param $orderData
     * @return array
     * @throws LocalizedException
     */
    public function cancelLoan($orderData)
    {
        $response = $this->requestService(
            $this->getCancelLoanUrl(),
            json_encode($orderData)
        );

        return $response;
    }

    /**
     * @return string
     * @throws LocalizedException
     */
    public function getAuthorizationToken()
    {
        return $this->createToken();
    }

    /**
     * @return string
     * @throws LocalizedException
     */
    private function createToken()
    {
        try {
            $response = $this->requestService($this->getAuthorizationUrl(), json_encode(['apiToken' => $this->aplazoHelper->getApiToken(), 'merchantId' => $this->aplazoHelper->getMerchantId()]));
            if (!isset($response['Authorization'])) {
                $message = __('No token returned from ' . $this->getAuthorizationUrl());
                $this->sendLog($message, AplazoHelper::LOGS_CATEGORY_ERROR, AplazoHelper::LOGS_SUBCATEGORY_AUTH, ['token' => $this->aplazoHelper->getApiToken()]);
                throw new AuthenticationException($message);
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
            $response = $this->requestService($this->getLoanStatusUrl($cartId), '', 'GET');
        } catch (LocalizedException $e) {
            $message = "Aplazo communication failed in cartId $cartId get loan status from Aplazo " . $e->getMessage();
            $this->aplazoHelper->log($message);
            $this->sendLog($message, AplazoHelper::LOGS_CATEGORY_ERROR, AplazoHelper::LOGS_SUBCATEGORY_LOAN);
            return false;
        }
        // An Aplazo response could have more than one loan with the same increment_id. With at least one in OUTSTANDING status, the order means that is paid.
        foreach ($response as $index => $loan) {
            if (isset($loan['status'])) {
                if ($loan['status'] === self::LOAN_SUCCESS_STATUS) {
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

    private function requestService($url, $body, $method = self::API_POST, $bearer = false)
    {
        $response = $this->requestCurl($url, $body, $method, $bearer);

        $this->aplazoHelper->log("From: \Aplazo\AplazoPayment\Service\ApiService::request\nURL: $url\nMETHOD: $method\nREQUEST: $body\nRESPONSE:$response");
        if($url !== $this->getAuthorizationUrl()){
            $this->sendLog("HttpRequest URL:".$url, AplazoHelper::LOGS_CATEGORY_INFO, AplazoHelper::LOGS_SUBCATEGORY_REQUEST,
            ['method' => $method, 'body' => $body, 'response' => $response]);
        }

        if (!$response) {
            $message = __('No response from request to ' . $url);
            $this->sendLog($message, AplazoHelper::LOGS_CATEGORY_ERROR, AplazoHelper::LOGS_SUBCATEGORY_REQUEST, ['method' => $method, 'body' => $body]);
            throw new LocalizedException($message);
        }

        if (!empty($error)) {
            $message = __('Error returned with request to ' . $url . '. Error: ' . $error);
            $this->sendLog($message, AplazoHelper::LOGS_CATEGORY_ERROR, AplazoHelper::LOGS_SUBCATEGORY_REQUEST, ['method' => $method, 'body' => $body]);
            throw new LocalizedException($message);
        }

        return json_decode($response, true);
    }

    public function requestCurl($url, $body, $method = self::API_POST, $bearer = false)
    {
        $headers = [
            'merchant_id' => $this->aplazoHelper->getMerchantId(),
            'api_token' => $this->aplazoHelper->getApiToken(),
            'Content-Type' => 'application/json'
        ];
        if($bearer){
            $headers['Authorization'] = $bearer;
        }
        $this->curl->setHeaders($headers);
        if ($method === self::API_POST) {
            $this->curl->post($url, $body);
        } else {
            $this->curl->get($url);
        }
        return $this->curl->getBody();
    }

    public function sendLog($message, $category, $subcategory, $metadata = [])
    {
        $metadata = array_merge($metadata, [
            "merchantId" => $this->aplazoHelper->getMerchantId(),
            "log" => $message
        ]);
        $body = [
            "eventType"=> "tag_plugin_w",
            "origin"=> "MGT2",
            "category"=> $category,
            "subcategory"=> $subcategory,
            "metadata"=> $metadata
        ];

        $this->requestCurl($this->aplazoHelper->getServiceLogUrl(), json_encode($body));
    }

    public function getOrderImportantDataToLog($order)
    {
        return ['incrementId' => $order->getIncrementId(),
            'entityId' => $order->getEntityId(),
            'status' => $order->getStatus(),
            'state' => $order->getState()
        ];
    }
}
