<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Controller\Adminhtml\Sale;

use Aplazo\AplazoPayment\Client\Client;
use Aplazo\AplazoPayment\Model\Api\AplazoOrder;
use Aplazo\AplazoPayment\Model\Data\Sale;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\QuoteRepository;

class Refund extends \Magento\Backend\App\Action
{
    protected $resultPageFactory;
    private $aplazoSaleOrderRepository;
    private $client;
    private $quoteRepository;

    /**
     * @param \Aplazo\AplazoPayment\Api\SaleRepositoryInterface $aplazoSaleOrderRepository
     * @param Client $client
     * @param \Magento\Backend\App\Action\Context $context
     * @param \Magento\Framework\View\Result\PageFactory $resultPageFactory
     */
    public function __construct(
        \Aplazo\AplazoPayment\Api\SaleRepositoryInterface $aplazoSaleOrderRepository,
        \Aplazo\AplazoPayment\Client\Client               $client,
        QuoteRepository                                   $quoteRepository,
        \Magento\Backend\App\Action\Context               $context,
        \Magento\Framework\View\Result\PageFactory        $resultPageFactory
    )
    {
        $this->aplazoSaleOrderRepository = $aplazoSaleOrderRepository;
        $this->client = $client;
        $this->quoteRepository = $quoteRepository;
        $this->resultPageFactory = $resultPageFactory;
        parent::__construct($context);
    }

    /**
     * Index action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $id = $this->getRequest()->getParam('id');

        try {
            $aplazoSaleOrder = $this->aplazoSaleOrderRepository->get($id);
            $quote = $this->quoteRepository->get($aplazoSaleOrder->getQuoteId());
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage(__('We can\'t find the aplazo sale order id.'));
            return $resultRedirect->setPath('aplazo_aplazopayment/sale/index');
        }

        $response = $this->client->refund([
            "cartId" => $aplazoSaleOrder->getReservedOrderId(),
            "totalAmount" => $quote->getGrandTotal(),
            "reason" => 'Magento error when creating order: ' . $aplazoSaleOrder->getMessage()
        ]);

        if (empty($response['refundId'])) {
            $this->messageManager->addErrorMessage(__('Order cancel refused.'));
        } else {
            $this->messageManager->addSuccessMessage(__('Refund or cancel accepted'));
            if($response['refundStatus'] == 'CANCELLED'){
                $aplazoSaleOrder->setStatus(Sale::STATUS_CANCELLED)
                    ->setMessage('Order cancelled in Aplazo');
            } else {
                $aplazoSaleOrder->setStatus(Sale::STATUS_REFUNDED)
                    ->setMessage('Order refunded in Aplazo');
            }
            $this->aplazoSaleOrderRepository->save($aplazoSaleOrder);
        }

        return $resultRedirect->setPath('aplazo_aplazopayment/sale/index');
    }
}

