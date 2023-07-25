<?php

namespace Aplazo\AplazoPayment\Observer;

use Aplazo\AplazoPayment\Client\Client;
use Aplazo\AplazoPayment\Helper\Data;
use Aplazo\AplazoPayment\Logger\Logger as AplazoLogger;
use Aplazo\AplazoPayment\Model\Ui\ConfigProvider;
use Aplazo\AplazoPayment\Service\ApiService as AplazoService;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Event\ObserverInterface;

class RefundObserverBeforeSave implements ObserverInterface
{
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    private $_config;
    private $_logger;

    /**
     * @var Data
     */
    private $_data;

    /**
     * @var AplazoService
     */
    private $_aplazoService;


    /**
     * @param Context $context
     * @param AplazoLogger $aplazoLogger
     * @param Client $client
     */
    public function __construct(
        Context $context,
        AplazoLogger $aplazoLogger,
        AplazoService $aplazoService,
        Data $data
    )
    {
        $this->messageManager = $context->getMessageManager();
        $this->_logger = $aplazoLogger;
        $this->_aplazoService = $aplazoService;
        $this->_data = $data;

    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $creditMemo = $observer->getData('creditmemo');
        $order = $creditMemo->getOrder();
        $this->creditMemoRefundBeforeSave($order, $creditMemo);
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Creditmemo $creditMemo
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function creditMemoRefundBeforeSave(\Magento\Sales\Model\Order $order, \Magento\Sales\Model\Order\Creditmemo $creditMemo)
    {
        $paymentOrder = $order->getPayment();
        $paymentMethod = $paymentOrder->getMethodInstance()->getCode();
        if (!($paymentMethod == ConfigProvider::CODE)) {
            return;
        }

        $refundAvailable = $this->_data->getRefund();
        if (!$refundAvailable) {
            $this->messageManager->addErrorMessage(__('The refund will be made offline since the Aplazo refund option is not activated in the configuration'));
            return;
        }

        $amountRefund = $creditMemo->getGrandTotal();

        $reason = '';
        foreach($creditMemo->getComments() as $index => $comment){
            $reason .=  $index . '. ' . $comment->getComment() . '.  ';
        }

        $response = $this->_aplazoService->createRefund([
            "cartId"        => $order->getIncrementId(),
            "totalAmount"   => $amountRefund,
            "reason"        => $reason
        ]);

        if (isset($response['status'])){
            if($response['status'] === 0) {
                $this->throwRefundException($response['message']);
            }
        }

        if (!(empty($response['refundId']))) {
            if($response['refundStatus'] === "REJECTED") {
                $this->throwRefundException('Credit memo is not available due to the Loan status');
            } else {
                if($response['refundStatus'] === "REQUESTED") {
                    $this->messageManager->addSuccessMessage('Aplazo refund was processed successfully. The Aplazo status is Requested');
                    $order->addCommentToStatusHistory('Aplazo refund was processed successfully. The Aplazo status is Requested');
                    $order->save();
                }
            }
        } else {
            $this->throwRefundException("Bad service response.");
        }
    }

    /**
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function throwRefundException($message)
    {
        throw new \Magento\Framework\Exception\LocalizedException(new \Magento\Framework\Phrase('Aplazo refund error - ' . $message));
    }
}
