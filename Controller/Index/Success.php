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
use Magento\Sales\Model\Service\InvoiceService;/*OK*/
use Magento\Framework\DB\TransactionFactory;/*??*/
use Psr\Log\LoggerInterface;/*OK*/
use Magento\Framework\UrlInterface;

class Success extends Action #implements HttpGetActionInterface, CsrfAwareActionInterface
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
     * @var InvoiceService
     */
    protected $invoiceService;

    /**
     * @var TransactionFactory
     */
    protected $transactionFactory;

    /**
    * @var url
    */
    protected $url;

    /**
     * Success constructor.
     * @param Context $context
     * @param RedirectFactory $redirectFactory
     * @param JsonFactory $jsonFactory
     * @param CheckoutSession $checkoutSession
     * @param CartRepositoryInterface $quoteRepository
     * @param QuoteFactory $quoteFactory
     * @param LoggerInterface $logger
     * @param QuoteManagement $quoteManagement
     * @param InvoiceService $invoiceService
     * @param TransactionFactory $transactionFactory
     */
    public function __construct(
        Context $context,
        RedirectFactory $redirectFactory,
        JsonFactory $jsonFactory,
        CheckoutSession $checkoutSession,
        CartRepositoryInterface $quoteRepository,
        QuoteFactory $quoteFactory,
        LoggerInterface $logger,
        QuoteManagement $quoteManagement,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        UrlInterface $url
    ) {
        $this->_logger = $logger;
        $this->_quoteFactory = $quoteFactory;
        $this->_quoteRepository = $quoteRepository;
        $this->_checkoutSession = $checkoutSession;
        $this->_jsonFactory = $jsonFactory;
        $this->_redirectFactory = $redirectFactory;
        $this->quoteManagement = $quoteManagement;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
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
            $lastOrder = $this->_checkoutSession->getLastRealOrder();
            //print_r($this->_checkoutSession->getQuote()->getData());
            if ($this->_checkoutSession->getQuote()->getReservedOrderId()){

                $invoice = $this->invoiceService->prepareInvoice($lastOrder);
                $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                $invoice->register();
                $transaction = $this->transactionFactory->create()
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder());

                $transaction->save();
            }
            $result->setUrl($this->_url->getUrl('checkout/onepage/success'));
            return $result;
        } catch (\Exception $e) {

            $this->_logger->debug($e->getMessage());
            $result->setUrl($this->_url->getUrl('checkout/cart'));
            return $result;
        }
    }
}
