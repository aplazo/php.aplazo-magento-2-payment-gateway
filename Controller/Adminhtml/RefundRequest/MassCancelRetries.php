<?php

namespace Aplazo\AplazoPayment\Controller\Adminhtml\RefundRequest;

use Aplazo\AplazoPayment\Model\RefundRequest;
use Aplazo\AplazoPayment\Model\ResourceModel\RefundRequest\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Ui\Component\MassAction\Filter;

class MassCancelRetries extends Action
{
    public const ADMIN_RESOURCE = 'Aplazo_AplazoPayment::aplazo_refund_queue';

    public function __construct(
        Context $context,
        private Filter $filter,
        private CollectionFactory $collectionFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());

        $updated = 0;
        foreach ($collection as $request) {
            /** @var RefundRequest $request */
            $request->setStatus(RefundRequest::STATUS_CANCELLED);
            $request->setNextAttemptAt(null);
            $request->setLastError('Cancelled manually by admin user.');
            $request->save();
            $updated++;
        }

        $this->messageManager->addSuccessMessage(__('Cancelled retries for %1 request(s).', $updated));

        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $resultRedirect->setPath('aplazo/refundrequest/index');
    }
}

