<?php

namespace Aplazo\AplazoPayment\Observer;

use Aplazo\AplazoPayment\Helper\Data;
use Aplazo\AplazoPayment\Service\ApiService as AplazoService;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\OrderItemRepositoryInterface;

class RmaObserverBeforeSave implements ObserverInterface
{
    const RMA_REFUND_RESOLUTION = 5;
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @var Data
     */
    private $_data;

    /**
     * @var AplazoService
     */
    private $_aplazoService;

    /**
     * @var OrderItemRepositoryInterface
     */
    private $_orderItemRepository;


    /**
     * @param Context $context
     * @param AplazoService $aplazoService
     * @param Data $data
     * @param OrderItemRepositoryInterface $itemRepository
     */
    public function __construct(
        Context $context,
        AplazoService $aplazoService,
        Data $data,
        OrderItemRepositoryInterface $itemRepository
    )
    {
        $this->messageManager = $context->getMessageManager();
        $this->_aplazoService = $aplazoService;
        $this->_data = $data;
        $this->_orderItemRepository = $itemRepository;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if(!$this->_data->getRmaRefund() ||
        !class_exists('Magento\Rma\Model\ItemFactory')){
            return;
        }

        /** @var \Magento\Rma\Model\Rma $rma */
        $rma = $observer->getData('rma');
        $itemFactory = \Magento\Framework\App\ObjectManager::getInstance()->get(\Magento\Rma\Model\ItemFactory::class);
        $order_increment_id = $rma->getOrderIncrementId();
        $items = $rma->getItems();

        foreach($items as $item){
            if($item->getResolution() == self::RMA_REFUND_RESOLUTION && $item->getStatus() == \Magento\Rma\Model\Rma\Source\Status::STATE_APPROVED){
                /** @var \Magento\Rma\Model\Item $item_model */
                $item_model = $itemFactory->create()->load($item->getEntityId());
                $qty_approved = $item->getQtyApproved();
                $qty_to_aplazo_refund = $item_model->getData('qty_aplazo_refunded');
                $qty_to_send = $qty_approved - (int)$qty_to_aplazo_refund;
                $item_id = $item->getOrderItemId();
                $order_item = $this->_orderItemRepository->get($item_id);

                // Aplazo has sent all the items to refund
                if(!$qty_to_send){
                    continue;
                }

                $response = $this->_aplazoService->createRefund([
                    "cartId"        => $order_increment_id,
                    "totalAmount"   => $order_item->getPrice() * $qty_to_send,
                    "reason"        => $item->getReason()
                ]);

                if (isset($response['status'])){
                    if($response['status'] === 0) {
                        $this->_aplazoService->sendLog("Rma Refund error " . $response['message'], Data::LOGS_CATEGORY_ERROR, Data::LOGS_SUBCATEGORY_REFUND);
                        $this->throwRefundException($response['message']);
                    }
                }

                if (!(empty($response['refundId']))) {
                    if($response['refundStatus'] === "REJECTED") {
                        $message = 'Credit memo is not available due to the Loan status';
                        $this->_aplazoService->sendLog("Rma Refund error " . $message, Data::LOGS_CATEGORY_ERROR, Data::LOGS_SUBCATEGORY_REFUND);
                        $this->throwRefundException($message);
                    } elseif($response['refundStatus'] === "REQUESTED") {
                        $message = 'Aplazo refund was processed successfully. The Aplazo status is Requested';
                        $this->messageManager->addSuccessMessage($message);
                        $this->_aplazoService->sendLog("Rma Refund success: " . $message, Data::LOGS_CATEGORY_INFO, Data::LOGS_SUBCATEGORY_REFUND);
                        $qty_to_aplazo_save = $qty_to_aplazo_refund + $qty_to_send;
                        $item_model->setData('qty_aplazo_refunded', $qty_to_aplazo_save);
                        $item_model->save();
                    }
                } else {
                    $this->throwRefundException("Bad service response.");
                }
            }
        }
    }

    protected function throwRefundException($message)
    {
        throw new \Magento\Framework\Exception\LocalizedException(new \Magento\Framework\Phrase('Aplazo rma refund error - ' . $message));
    }
}
