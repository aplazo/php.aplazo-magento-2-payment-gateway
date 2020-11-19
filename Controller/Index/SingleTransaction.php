<?php

namespace Spro\AplazoPayment\Controller\Index;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Quote\Model\QuoteManagement;
use Psr\Log\LoggerInterface;
use Spro\AplazoPayment\Client\Client;
use Spro\AplazoPayment\Helper\Data;

class SingleTransaction extends Action
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /**
     * @var CheckoutSession
     */
    protected $_checkoutSession;

    /**
     * @var QuoteManagement
     */
    protected $quoteManagement;

    /**
     * @var Data
     */
    protected $aplazoHelper;

    /**
     * SingleTransaction constructor.
     * @param Context $context
     * @param CheckoutSession $checkoutSession
     * @param LoggerInterface $logger
     * @param Client $client
     * @param QuoteManagement $quoteManagement
     * @param Data $aplazoHelper
     */
    public function __construct(
        Context $context,
        CheckoutSession $checkoutSession,
        LoggerInterface $logger,
        Client $client,
        QuoteManagement $quoteManagement,
        Data $aplazoHelper
    ) {
        $this->_logger = $logger;
        $this->_checkoutSession = $checkoutSession;
        $this->client = $client;
        $this->quoteManagement = $quoteManagement;
        $this->aplazoHelper = $aplazoHelper;
        parent::__construct($context);
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface|\Magento\Framework\View\Result\Layout
     */
    public function execute()
    {
        $data = [
            'error' => true,
            'message' => __('Service temporarily unavailable. Please try again later.'),
            'transactionId' => null
        ];
        $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        try {
            $auth = $this->client->auth();
            $quote = $this->_checkoutSession->getQuote();

            if ($auth && is_array($auth)) {
                $this->leaveLastItem($quote);

                if (!$this->_checkoutSession->getQuote()->getCustomerId()){
                    $quote->setCustomerIsGuest(true);
                }
                $shippingAddress = $quote->getShippingAddress();
                if (!$shippingAddress || !$shippingAddress->getFirstname()) {
                    $this->aplazoHelper->fillDummyQuote($quote);
                }
                if (!$shippingAddress->getEmail()) {
                    $shippingAddress->setEmail($this->aplazoHelper->getCustomerEmail());
                    $quote->getBillingAddress()->setEmail($this->aplazoHelper->getCustomerEmail());
                }

                $order = $this->quoteManagement->submit($quote);

                $resultUrl = $this->client->create($auth, $quote);
                $this->setSuccessOrderData($quote, $order);

                if ($resultUrl) {
                    $data = [
                        'error' => false,
                        'message' => '',
                        'redirecturl' => $resultUrl
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->_logger->debug($e->getMessage());
        }
        $resultJson->setData($data);
        return $resultJson;
    }

    /**
     * @param $quote
     * @param $order
     */
    protected function setSuccessOrderData($quote, $order)
    {
        $this->_checkoutSession->setLastSuccessQuoteId($quote->getId());
        $this->_checkoutSession->setLastQuoteId($quote->getId());
        $this->_checkoutSession->setLastOrderId($order->getId());
        $this->_checkoutSession->setLastRealOrderId($order->getIncrementId());
    }

    protected function leaveLastItem(&$quote)
    {
        $quoteItems	=	$this->_checkoutSession->getQuote()->getAllVisibleItems();

        $max = 0;
        $lastItem = null;
        foreach ($quoteItems as $item) {
            if ($item->getId() > $max) {
                $max = $item->getId();
                $lastItem = $item;
            }
        }
        foreach ($quoteItems as $item) {
            if ($item->getId()!=$lastItem->getId()) {
                $quote->deleteItem($item);
            }
        }
    }
}
