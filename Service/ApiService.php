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
    public function createLoan($orderData, $tokenBearer)
    {
        $response = [];
        if ($tokenBearer) {
            $response = $this->requestService(
                $this->getCreateLoanUrl(),
                json_encode($orderData),
                self::API_POST,
                $tokenBearer
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
        try {
            $response = $this->requestService($this->getAuthorizationUrl(),
                json_encode(['apiToken' => $this->aplazoHelper->getApiToken(), 'merchantId' => $this->aplazoHelper->getMerchantId()]));
            if (!isset($response['Authorization'])) {
                $message = __('No token returned from ' . $this->getAuthorizationUrl());
                $this->aplazoHelper->log($message);
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
        return $response;
    }

    public function shouldCancelOrder($cartId)
    {
        $response = $this->getLoanStatus($cartId);
        if(is_array($response)){
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
        $response = $this->requestMagentoCurl($url, $body, $method, $bearer);

        if (!$response['success']) {
            $message = __('No response from request to ' . $url);
            if (strpos($url, "posbifrost") === false) {
                $this->sendLog($message . ' ' . $response['message'], AplazoHelper::LOGS_CATEGORY_ERROR, AplazoHelper::LOGS_SUBCATEGORY_REQUEST, ['method' => $method, 'body' => $body]);
            }
            $this->aplazoHelper->log("ERROR > From: \Aplazo\AplazoPayment\Service\ApiService::request\nURL: $url\nMETHOD: $method\nREQUEST: $body\nRESPONSE:".json_encode($response));
            throw new LocalizedException($message);
        }

        $this->aplazoHelper->log("From: \Aplazo\AplazoPayment\Service\ApiService::request\nURL: $url\nMETHOD: $method\nREQUEST: $body\nRESPONSE:".json_encode($response), AplazoHelper::LOGS_VVV);
        if (strpos($url, "posbifrost") === false && $url !== $this->getAuthorizationUrl()) {
            $this->sendLog("HttpRequest URL:".$url, AplazoHelper::LOGS_CATEGORY_INFO, AplazoHelper::LOGS_SUBCATEGORY_REQUEST,
            ['method' => $method, 'body' => $body, 'response' => $response]);
        }

        return $response['data'];
    }

    public function requestMagentoCurl($url, $body, $method, $bearer)
    {
        $headers = [
            'merchant_id' => $this->aplazoHelper->getMerchantId(),
            'api_token' => $this->aplazoHelper->getApiToken(),
            'Content-Type' => 'application/json'
        ];
        if($bearer){
            $headers['Authorization'] = $bearer;
        }
        try{
            $this->curl->setHeaders($headers);
            if ($method === self::API_POST) {
                $this->curl->post($url, $body);
            } else {
                $this->curl->get($url);
            }
            $statusCode = $this->curl->getStatus();
            switch ($statusCode) {
                case 200 || 201 || 202:
                    $response = $this->curl->getBody();
                    return ['success' => true, 'data' => json_decode($response, true)];
                default:
                    return ['success' => false, 'message' => 'Unexpected HTTP status: ' . $statusCode];
            }
        }catch (\Exception $e) {
            $this->aplazoHelper->log('HTTP Request Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'HTTP Request Exception: ' . $e->getMessage()];
        }
    }

    public function requestPHPCurl($url, $body, $method, $bearer){
        $curl = curl_init();

        $headers = [
            'merchant_id' => $this->aplazoHelper->getMerchantId(),
            'api_token' => $this->aplazoHelper->getApiToken(),
            'Content-Type' => 'application/json'
        ];
        if($bearer){
            $headers['Authorization'] = $bearer;
        }

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

        return json_decode($response,true);
    }

    public function sendLog($message, $category, $subcategory, $metadata = [], $secondChance = false)
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

        try {
            return $this->requestService($this->aplazoHelper->getServiceLogUrl(), json_encode($body));
        } catch (LocalizedException $e) {
            return 'error';
        }
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
