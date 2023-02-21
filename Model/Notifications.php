<?php

namespace Aplazo\AplazoPayment\Model;


use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Aplazo\AplazoPayment\Api\NotificationsInterface;
use Aplazo\AplazoPayment\Model\Service\OrderService;

class Notifications implements NotificationsInterface
{

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

    public function __construct
    (
        OrderService $orderService,
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
        try {
            $orderResult = $this->orderService->getOrderById($cartId);
            if ($orderResult['success']) {
                /**
                 * @var Order $order
                 */
                $order = $orderResult['order'];
                if ($status == 'Activo') {
                    $order = $this->orderService->approveOrder($order->getId());
                }
                $orderPayment = $order->getPayment();
                $orderPayment->setAdditionalInformation('aplazo_payment_id', $loanId);
                $orderPayment->setAdditionalInformation('aplazo_status', $status);
                $this->addOperationCommentToStatusHistory($order, $status, $loanId);
                $this->orderService->saveOrder($order);
            } else {
                $response['status'] = false;
                $response['message'] = $orderResult['message'];
            }
        } catch (\Exception $e) {
            $response['status'] = false;
            $response['message'] = $e->getMessage();
        }

        $request = json_encode(['loanid' => $loanId, 'status' => $status, 'cartid' => $cartId]);
        $response = json_encode($response);
        $this->aplazoHelper->log("From: \Aplazo\AplazoPayment\Model\Notifications::notify\nREQUEST: $request\nRESPONSE:$response");

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
}
