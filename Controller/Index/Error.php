<?php

namespace Aplazo\AplazoPayment\Controller\Index;

use Magento\Checkout\Model\Session as CheckoutSession;/*OK*/
use Magento\Framework\App\Action\Action;/*OK*/
use Magento\Framework\App\Action\Context;/*OK*/
#use Magento\Framework\App\Action\HttpGetActionInterface;/*NO EXISTE EN 2.0*/
#use Magento\Framework\App\CsrfAwareActionInterface;/*NO EXISTE EN 2.0*/
#use Magento\Framework\App\Request\InvalidRequestException;/*NO EXISTE EN 2.0*/
use Magento\Framework\App\RequestInterface;/*OK*/
use Magento\Framework\Controller\Result\JsonFactory;/*OK*/
use Magento\Framework\Controller\Result\RedirectFactory;/*OK*/
use Magento\Framework\Controller\ResultFactory;/*OK*/
use Magento\Framework\Phrase;/*OK*/
use Magento\Framework\Webapi\Exception;/*OK*/
use Magento\Quote\Api\CartRepositoryInterface;/*OK*/
use Magento\Quote\Model\QuoteFactory;/*??*/
use Magento\Quote\Model\QuoteManagement;/*OK*/
use Psr\Log\LoggerInterface;/*OK*/
use Magento\Framework\UrlInterface;

class Error extends Action #implements HttpGetActionInterface, CsrfAwareActionInterface
{
    const PARAM_NAME_TOKEN = 'token';

    /**
     * @var RedirectFactory
     */
    protected $_redirectFactory;

    /**
     * @var JsonFactory
     */
    protected $_jsonFactory;

    /**
     * @var CheckoutSession
     */
    protected $_checkoutSession;

    /**
     * @var CartRepositoryInterface
     */
    protected $_quoteRepository;

    /**
     * @var QuoteFactory
     */
    protected $_quoteFactory;

    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /**
     * @var QuoteManagement
     */
    protected $quoteManagement;

    /**
    * @var url
    */
    protected $url;

    /**
     * Create constructor.
     * @param Context $context
     * @param RedirectFactory $redirectFactory
     * @param JsonFactory $jsonFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        RedirectFactory $redirectFactory,
        JsonFactory $jsonFactory,
        LoggerInterface $logger,
        UrlInterface $url
    ) {
        $this->_logger = $logger;
        $this->_jsonFactory = $jsonFactory;
        $this->_redirectFactory = $redirectFactory;
        $this->_url = $url;
        parent::__construct($context);
    }


    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface|\Magento\Framework\View\Result\Layout
     */
    public function execute()
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        try {
            $this->messageManager->addErrorMessage(__('You Aplazo Payment was unsuccessful'));
            $result->setUrl($this->_url->getUrl('checkout/cart'));
            return $result;
        } catch (\Exception $e) {
            $this->_logger->debug($e->getMessage());
            $result->setUrl($this->_url->getUrl('checkout/cart'));
            return $result;
        }
    }
}
