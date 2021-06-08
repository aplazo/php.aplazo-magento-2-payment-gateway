<?php

namespace Aplazo\AplazoPayment\Controller\Index;

use Magento\Checkout\Model\Session as CheckoutSession;/*OK*/
use Magento\Framework\App\Action\Action;/*OK*/
use Magento\Framework\App\Action\Context;/*OK*/
use Magento\Framework\Controller\ResultFactory;/*OK*/
use Magento\Quote\Model\QuoteManagement;/*OK*/
use Psr\Log\LoggerInterface;/*OK*/
use Aplazo\AplazoPayment\Client\Client;
use Aplazo\AplazoPayment\Helper\Data;

class Onplaceorder extends Action
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
     * Transaction constructor.
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

        $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        try {
            $auth = $this->client->auth();
            $this->_logger->debug('Auth aplazo '.print_r($auth,true));

            $quote = $this->_checkoutSession->getQuote();
            if ($auth && is_array($auth)) {

                $resultUrl = $this->client->create($auth, $quote);

                if ($resultUrl) {
                    $data = [
                        'error' => false,
                        'message' => '',
                        'redirecturl' => $resultUrl
                    ];
                }
            }
        } catch (\Exception $e) {
            $data['message_catch'] = $e->getMessage();
            $this->_logger->debug($e->getMessage());

            $data = [
                'error' => true,
                'message' => __('Service temporarily unavailable. Please try again later. Error : '.$e->getMessage()),
                'transactionId' => null
            ];

        }
        $resultJson->setData($data);
        return $resultJson;
    }

}
