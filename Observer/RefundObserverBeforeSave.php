<?php

namespace Aplazo\AplazoPayment\Observer;

use Aplazo\AplazoPayment\Client\Client;
use Aplazo\AplazoPayment\Logger\Logger as AplazoLogger;
use Aplazo\AplazoPayment\Model\Config;
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
    private $_client;


    /**
     * @param Context $context
     * @param Config $config
     * @param AplazoLogger $aplazoLogger
     * @param Client $client
     */
    public function __construct(
        Context $context,
        Config $config,
        AplazoLogger $aplazoLogger,
        Client $client
    )
    {
        $this->messageManager = $context->getMessageManager();
        $this->_config = $config;
        $this->_logger = $aplazoLogger;
        $this->_client = $client;

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
        if (!($paymentMethod == 'aplazo_payment')) {
            return;
        }

        $refundAvailable = $this->_config->getRefund();
        if (!$refundAvailable) {
            $this->messageManager->addErrorMessage('the refund will be made offline since the Aplazo refund option is not activated in the configuration');
            return;
        }

        $amountRefund = $creditMemo->getGrandTotal();

        $reason = '';
        foreach($creditMemo->getComments() as $index => $comment){
            $reason .=  $index . '. ' . $comment->getComment() . '.  ';
        }

        $response = $this->_client->refund([
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
                    $this->messageManager->addSuccessMessage('Aplazo refund was processed successfully. The status is Requested');
                }
            }
        } else {
            $this->throwRefundException("Bad service response.");
        }
    }

    /**
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function log($message, $data = array())
    {
        if($this->_config->getDebug()){
            $this->_logger->info("RefundObserverBeforeSave::sendRefundRequest - " . $message);
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
