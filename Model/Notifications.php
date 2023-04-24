<?php

namespace Aplazo\AplazoPayment\Model;

use Magento\Sales\Model\Order;
use Aplazo\AplazoPayment\Api\NotificationsInterface;
use Aplazo\AplazoPayment\Model\Service\OrderService;

class Notifications implements NotificationsInterface
{

    const HEADER_PAYLOAD_SIGNATURE = 'HTTP_PAYLOADSIGNATURE';
    const APLAZO_PAYLOAD_MERCHANT_ID_INDEX = 'sub';
    const APLAZO_PAYLOAD_LOAN_ID_INDEX = 'loanId';
    const APLAZO_PAYLOAD_STATUS_INDEX = 'status';
    const APLAZO_PAYLOAD_ORDER_ID_INDEX = 'cartId';


    /**
     * @var \Aplazo\AplazoPayment\Helper\Data
     */
    private $aplazoHelper;

    /**
     * @var bool
     */
    private $debugEnable;

    /**
     * @var OrderService
     */
    private $orderService;

    private $validationMessageError;

    public function __construct
    (
        OrderService                      $orderService,
        \Aplazo\AplazoPayment\Helper\Data $aplazoHelper
    )
    {
        $this->orderService = $orderService;
        $this->aplazoHelper = $aplazoHelper;
        $this->debugEnable = $this->aplazoHelper->isDebugEnabled();
    }

    public function notify($loanId, $status, $cartId)
    {
        $response = ['status' => true, 'message' => 'OK'];

        if ($aplazoData = $this->webhookValidator()) {
            try {
                $orderResult = $this->orderService->getOrderById($aplazoData[self::APLAZO_PAYLOAD_ORDER_ID_INDEX]);
                if ($orderResult['success']) {
                    /**
                     * @var Order $order
                     */
                    $order = $orderResult['order'];
                    if ($status == 'Activo') {
                        $order = $this->orderService->approveOrder($order->getId());
                    }
                    $orderPayment = $order->getPayment();
                    $orderPayment->setAdditionalInformation('aplazo_payment_id', $aplazoData[self::APLAZO_PAYLOAD_LOAN_ID_INDEX]);
                    $orderPayment->setAdditionalInformation('aplazo_status', $aplazoData[self::APLAZO_PAYLOAD_STATUS_INDEX]);
                    $this->addOperationCommentToStatusHistory($order, $aplazoData[self::APLAZO_PAYLOAD_STATUS_INDEX], $aplazoData[self::APLAZO_PAYLOAD_LOAN_ID_INDEX],);
                    $this->orderService->saveOrder($order);
                } else {
                    $response['status'] = false;
                    $response['message'] = $orderResult['message'];
                }
            } catch (\Exception $e) {
                $response['status'] = false;
                $response['message'] = $e->getMessage();
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

    private function addOperationCommentToStatusHistory($order, $status, $id)
    {
        $orderMessage = "Notificación automática de Aplazo: La operación fue %s.<br>";
        $orderMessage .= "Referencia de Pago: %s<br>";
        $orderMessage .= "Estado: %s<br>";
        $operationResult = '';
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

    private function webhookValidator()
    {
        $key = $_SERVER[self::HEADER_PAYLOAD_SIGNATURE];
        $parts = explode('.', $key);

        try {
            $decoded_hmac = base64_decode($parts[1]);
            $payload = json_decode($decoded_hmac, true);
        } catch (\Exception $e) {
            $this->validationMessageError = 'Malformed token: ' . $e->getMessage();
            return false;
        }
        if ($payload[self::APLAZO_PAYLOAD_MERCHANT_ID_INDEX] != $this->aplazoHelper->getMerchantId()) {
            $this->validationMessageError = 'Incorrect Merchant ID';
            return false;
        }

        return $payload;
    }
}
