<?php

namespace Aplazo\AplazoPayment\Controller\Index;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Quote\Model\QuoteManagement;
use Psr\Log\LoggerInterface;
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
            $quote = $this->_checkoutSession->getQuote();
            if(isset($auth['error']) && $auth['error'] == 1){
                $data = [
                    'error' => true,
                    'message' => __($auth['message']),
                    'transactionId' => null
                ];
            }
            else{
                if ($auth && is_array($auth)) {
                    $result = $this->client->create($auth, $quote);
                    if(isset($result['error']) && $result['error'] == 1){
                        $data = [
                            'error' => true,
                            'message' => __($result['message']),
                            'transactionId' => null
                        ];
                    }
                    $result_decode = json_decode($result, true);
                    $redirectUrl = $result_decode['url'];
                    if ($redirectUrl) {
                        $data = [
                            'error' => false,
                            'message' => '',
                            'redirecturl' => $redirectUrl
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            $this->_logger->debug($e->getMessage());
        }
        $resultJson->setData($data);
        return $resultJson;
    }

}
