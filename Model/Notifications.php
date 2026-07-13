<?php

namespace Aplazo\AplazoPayment\Model;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Magento\Sales\Model\Order;
use Aplazo\AplazoPayment\Api\NotificationsInterface;
use Aplazo\AplazoPayment\Model\Service\OrderService;
use Aplazo\AplazoPayment\Service\ApiService as AplazoService;
use Aplazo\AplazoPayment\Service\LogService;
use Aplazo\AplazoPayment\Service\TrackingService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Notifications implements NotificationsInterface
{

    const HEADER_BEARER = 'HTTP_AUTHORIZATION';
    const BEARER_STRING = 'Bearer ';
    const APLAZO_PAYLOAD_LOAN_ID_INDEX = 'loanId';
    const APLAZO_PAYLOAD_STATUS_INDEX = 'status';
    const APLAZO_PAYLOAD_ORDER_ID_INDEX = 'cartId';


    /**
     * @var \Aplazo\AplazoPayment\Helper\Data
     */
    private $aplazoHelper;

    /**
     * @var OrderService
     */
    private $orderService;
    private $orderSender;

    private $validationMessageError;
    private $debugEnable;
    private $aplazoService;
    private TrackingService $trackingService;
    private LogService $logService;

    public function __construct
    (
        OrderService                      $orderService,
        \Aplazo\AplazoPayment\Helper\Data $aplazoHelper,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        AplazoService                     $aplazoService,
        TrackingService                   $trackingService,
        LogService                        $logService
    )
    {
        $this->orderService = $orderService;
        $this->aplazoService = $aplazoService;
        $this->aplazoHelper = $aplazoHelper;
        $this->orderSender = $orderSender;
        $this->trackingService = $trackingService;
        $this->logService = $logService;
        $this->debugEnable = $this->aplazoHelper->getDebugVerbosity();
    }

    public function notify($loanId, $status, $cartId)
    {
        $this->logService->resetRequestId();
        $this->aplazoHelper->log("Webhook triggered.");
        $this->logService->send('info', 'Webhook received', ['module:webhook'], ['loan_id' => $loanId, 'status' => $status, 'cart_id' => $cartId]);
        $response = ['status' => true, 'message' => 'OK'];
        if ($aplazoData = $this->validateJWT()) {
            $this->logService->send('info', 'JWT validated, processing webhook payload', ['module:webhook'], ['payload_loan_id' => $aplazoData[self::APLAZO_PAYLOAD_LOAN_ID_INDEX] ?? '', 'payload_status' => $aplazoData[self::APLAZO_PAYLOAD_STATUS_INDEX] ?? '', 'payload_cart_id' => $aplazoData[self::APLAZO_PAYLOAD_ORDER_ID_INDEX] ?? '']);
            try {
                $orderResult = $this->orderService->getOrderByIncrementId($aplazoData[self::APLAZO_PAYLOAD_ORDER_ID_INDEX]);
                if ($orderResult['success']) {
                    /** @var Order $order */
                    $order = $orderResult['order'];
                    $this->logService->send('info', 'Order found for webhook', ['module:webhook'], ['order_id' => $order->getIncrementId(), 'current_state' => $order->getState(), 'current_status' => $order->getStatus()]);
                    if ($status == 'Activo') {
                        $orderService = $this->orderService->approveOrder($order->getId());
                        $order = $orderService['order'];
                        if(!empty($orderService['message'])){
                            $response['message'] = $orderService['message'];
                        }
                        if($this->aplazoHelper->getSendEmail()){
                            $this->orderSender->send($order, true);
                            $this->logService->send('info', 'Order confirmation email sent', ['module:webhook'], ['order_id' => $order->getIncrementId()]);
                        }
                        $orderPayment = $order->getPayment();
                        $orderPayment->setAdditionalInformation('aplazo_payment_id', $aplazoData[self::APLAZO_PAYLOAD_LOAN_ID_INDEX]);
                        $orderPayment->setAdditionalInformation('aplazo_status', $aplazoData[self::APLAZO_PAYLOAD_STATUS_INDEX]);
                        $this->addOperationCommentToStatusHistory($order, $aplazoData[self::APLAZO_PAYLOAD_STATUS_INDEX], $aplazoData[self::APLAZO_PAYLOAD_LOAN_ID_INDEX], $orderService['message']);
                        $this->orderService->saveOrder($order);
                        try {
                            $this->trackingService->trackOrderPaid(
                                $order,
                                (string)$aplazoData[self::APLAZO_PAYLOAD_LOAN_ID_INDEX],
                                (string)$aplazoData[self::APLAZO_PAYLOAD_STATUS_INDEX]
                            );
                        } catch (\Throwable $e) {
                            // Never block webhook processing because of tracking.
                        }
                        $this->logService->send('info', 'Order advanced via webhook', ['module:webhook'], ['order_id' => $order->getIncrementId(), 'loan_id' => $loanId, 'cart_id' => $cartId, 'final_status' => $order->getStatus()]);
                    } else {
                        $this->logService->send('info', 'Webhook status is not Activo, no action taken', ['module:webhook'], ['order_id' => $order->getIncrementId(), 'webhook_status' => $status]);
                    }
                } else {
                    $response['status'] = false;
                    $response['message'] = $orderResult['message'];
                    $request = json_encode(['loanid' => $aplazoData[self::APLAZO_PAYLOAD_LOAN_ID_INDEX], 'status' => $aplazoData[self::APLAZO_PAYLOAD_STATUS_INDEX], 'cartid' => $aplazoData[self::APLAZO_PAYLOAD_ORDER_ID_INDEX]]);
                    $this->aplazoHelper->log("From: \Aplazo\AplazoPayment\Model\Notifications::notify\nREQUEST: $request\nRESPONSE:" . json_encode($response));
                    $this->logService->send('error', 'Order not found for webhook', ['module:webhook'], ['cart_id' => $aplazoData[self::APLAZO_PAYLOAD_ORDER_ID_INDEX], 'error' => $orderResult['message']]);
                    $response = json_encode($response);
                }
            } catch (\Exception $e) {
                $response['status'] = false;
                $response['message'] = $e->getMessage();
                $this->logService->send('error', 'Webhook processing error: ' . $e->getMessage(), ['module:webhook'], ['loan_id' => $loanId, 'cart_id' => $cartId, 'trace' => $e->getFile() . ':' . $e->getLine()]);
            }
        } else {
            $response['status'] = false;
            $response['message'] = $this->validationMessageError;
        }

        return $response;
    }

    private function addOperationCommentToStatusHistory($order, $status, $id, $message = false)
    {
        $orderMessage = "Notificación automática de Aplazo: La operación fue %s.<br>";
        $orderMessage .= "Referencia de Pago: %s<br>";
        $orderMessage .= "Estado: %s<br>";
        if($message){
            $orderMessage .= "Mensaje: No se pudo generar el invoice de la orden.";
        }
        switch ($status) {
            case 'New':
                $operationResult = 'Creada';
                break;
            case 'Activo':
                $operationResult = 'Aceptada';
                break;
            default:
                $operationResult = $status;
                break;
        }
        $order->addCommentToStatusHistory(sprintf($orderMessage, $operationResult, $id, $status));
        return $order;
    }

    private function validateJWT()
    {
        try{
            $jwt = trim(str_replace(self::BEARER_STRING, '', $_SERVER[self::HEADER_BEARER]));
            return (array) JWT::decode($jwt, new Key($this->aplazoHelper->getApiToken(), 'HS512'));
        } catch (\Exception $e) {
            $this->aplazoHelper->log("JWT Validation error: " . $e->getMessage());
            $this->validationMessageError = 'Something went wrong '. $e->getTrace()[0]['line'] . $e->getLine();
            $this->logService->send('error', 'JWT validation error: ' . $e->getMessage(), ['module:webhook'], ['trace' => $this->validationMessageError]);
            return false;
        }
    }
}
