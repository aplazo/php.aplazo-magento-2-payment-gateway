<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Controller\Adminhtml\Sale;

use Aplazo\AplazoPayment\Model\Data\Sale;
use Magento\Framework\Exception\LocalizedException;

class Recreate extends \Magento\Backend\App\Action
{

    protected $resultPageFactory;
    private $aplazoSaleOrderRepository;
    private $aplazoOrderApi;

    /**
     * @param \Aplazo\AplazoPayment\Model\Api\AplazoOrder $saleOrder
     * @param \Magento\Backend\App\Action\Context $context
     * @param \Magento\Framework\View\Result\PageFactory $resultPageFactory
     */
    public function __construct(
        \Aplazo\AplazoPayment\Model\Api\AplazoOrder $aplazoOrderApi,
        \Aplazo\AplazoPayment\Api\SaleRepositoryInterface $aplazoSaleOrderRepository,
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory
    ) {
        $this->aplazoOrderApi = $aplazoOrderApi;
        $this->aplazoSaleOrderRepository = $aplazoSaleOrderRepository;
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
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage(__('We can\'t find the aplazo sale order id.'));
            return $resultRedirect->setPath('aplazo_aplazopayment/sale/index');
        }

        $response = $this->aplazoOrderApi->submitOrder($aplazoSaleOrder->getQuoteId(), $aplazoSaleOrder->getReservedOrderId(), $aplazoSaleOrder->getLoanId());
        if($response['status'] === Sale::STATUS_PROCESSING){
            $this->messageManager->addSuccessMessage(__($response['response']['message']));
            $aplazoSaleOrder->setStatus(Sale::STATUS_PROCESSING)
                ->setMessage('Order recreated manually in Aplazo Orders');
            $this->aplazoSaleOrderRepository->save($aplazoSaleOrder);
        } elseif($response['status'] === Sale::STATUS_ERROR){
            $this->messageManager->addErrorMessage(__($response['response']['message']));
        }
        return $resultRedirect->setPath('aplazo_aplazopayment/sale/index');
    }
}

