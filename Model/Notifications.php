<?php

namespace Aplazo\AplazoPayment\Model;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Magento\Sales\Model\Order;
use Aplazo\AplazoPayment\Api\NotificationsInterface;
use Aplazo\AplazoPayment\Model\Service\OrderService;
use Aplazo\AplazoPayment\Service\ApiService as AplazoService;
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

    public function __construct
    (
        OrderService                      $orderService,
        \Aplazo\AplazoPayment\Helper\Data $aplazoHelper,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        AplazoService                     $aplazoService
    )
    {
        $this->orderService = $orderService;
        $this->aplazoService = $aplazoService;
        $this->aplazoHelper = $aplazoHelper;
        $this->orderSender = $orderSender;
        $this->debugEnable = $this->aplazoHelper->isDebugEnabled();
    }

    public function notify($loanId, $status, $cartId)
    {
        $this->aplazoHelper->log("Webhook triggered.");
        $this->aplazoService->sendLog("Webhook triggered", AplazoHelper::LOGS_CATEGORY_INFO, AplazoHelper::LOGS_SUBCATEGORY_WEBHOOK,
            ['cartId' => $cartId, 'loanId' => $loanId, 'status' => $status]);
        $response = ['status' => true, 'message' => 'OK'];
        if ($aplazoData = $this->validateJWT()) {
            try {
                $orderResult = $this->orderService->getOrderByIncrementId($aplazoData[self::APLAZO_PAYLOAD_ORDER_ID_INDEX]);
                if ($orderResult['success']) {
                    /** @var Order $order */
                    $order = $orderResult['order'];
                    if ($status == 'Activo') {
                        $orderService = $this->orderService->approveOrder($order->getId());
                        $order = $orderService['order'];
                        if(!empty($orderService['message'])){
                            $response['message'] = $orderService['message'];
                        }
                        if($this->aplazoHelper->getSendEmail()){
                            $this->orderSender->send($order, true);
                        }
                    }
                    $orderPayment = $order->getPayment();
                    $orderPayment->setAdditionalInformation('aplazo_payment_id', $aplazoData[self::APLAZO_PAYLOAD_LOAN_ID_INDEX]);
                    $orderPayment->setAdditionalInformation('aplazo_status', $aplazoData[self::APLAZO_PAYLOAD_STATUS_INDEX]);
                    $this->addOperationCommentToStatusHistory($order, $aplazoData[self::APLAZO_PAYLOAD_STATUS_INDEX], $aplazoData[self::APLAZO_PAYLOAD_LOAN_ID_INDEX], $orderService['message']);
                    $this->orderService->saveOrder($order);
                    $this->aplazoService->sendLog("Se avanza la orden a través del webhook", AplazoHelper::LOGS_CATEGORY_INFO, AplazoHelper::LOGS_SUBCATEGORY_WEBHOOK,
                        ['cartId' => $cartId, 'loanId' => $loanId]);
                } else {
                    $response['status'] = false;
                    $response['message'] = $orderResult['message'];
                }
            } catch (\Exception $e) {
                $response['status'] = false;
                $response['message'] = $e->getMessage();
                $this->aplazoService->sendLog('Webhook error: : ' . $e->getMessage(), AplazoHelper::LOGS_CATEGORY_ERROR, AplazoHelper::LOGS_SUBCATEGORY_WEBHOOK, ['trace' => $e->getTrace()[0]['line'] . $e->getLine()]);
            }

            $request = json_encode(['loanid' => $aplazoData[self::APLAZO_PAYLOAD_LOAN_ID_INDEX], 'status' => $aplazoData[self::APLAZO_PAYLOAD_STATUS_INDEX], 'cartid' => $aplazoData[self::APLAZO_PAYLOAD_ORDER_ID_INDEX]]);
            $response = json_encode($response);
            $this->aplazoHelper->log("From: \Aplazo\AplazoPayment\Model\Notifications::notify\nREQUEST: $request\nRESPONSE:$response");
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
            $this->aplazoService->sendLog('JWT Validation error: : ' . $e->getMessage(), AplazoHelper::LOGS_CATEGORY_ERROR, AplazoHelper::LOGS_SUBCATEGORY_WEBHOOK, ['trace' => $this->validationMessageError]);
            return false;
        }
    }
}
