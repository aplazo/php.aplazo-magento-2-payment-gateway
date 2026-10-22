<?php

namespace Aplazo\AplazoPayment\Controller\Order;

use Aplazo\AplazoPayment\Api\CheckoutNotPaidManagementInterface;
use Aplazo\AplazoPayment\Helper\Data;
use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Aplazo\AplazoPayment\Model\Config\Source\CheckoutMode;
use Aplazo\AplazoPayment\Service\ApiService as AplazoService;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Aplazo\AplazoPayment\Model\Service\OrderService;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote\Item\OptionFactory;
use Magento\Quote\Model\Quote\ItemFactory;
use Magento\Quote\Model\QuoteFactory;

class Operations extends \Magento\Framework\App\Action\Action
{
    const ACTION_URL = 'aplazo/order/operations';

    /**
     * @var OrderService
     */
    protected $orderService;

    /**
     * @var Session
     */
    protected $checkoutSession;

    /**
     * @var ResultFactory
     */
    protected $resultFactory;

    /**
     * @var AplazoService
     */
    private $aplazoService;
    /**
     * @var AplazoHelper
     */
    private $aplazoHelper;
    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;
    private $quoteFactory;
    private $itemFactory;
    private $itemOptionFactory;
    private $checkoutNotPaidManagement;


    public function __construct(
        Context                            $context,
        OrderService                       $orderService,
        Session                            $checkoutSession,
        AplazoService                      $aplazoService,
        Data                               $aplazoHelper,
        CartRepositoryInterface            $cartRepository,
        QuoteFactory                       $quoteFactory,
        ItemFactory                        $itemFactory,
        OptionFactory                      $itemOptionFactory,
        ResultFactory                      $resultFactory,
        CheckoutNotPaidManagementInterface $checkoutNotPaidManagement
    )
    {
        parent::__construct($context);
        $this->orderService = $orderService;
        $this->checkoutSession = $checkoutSession;
        $this->aplazoService = $aplazoService;
        $this->aplazoHelper = $aplazoHelper;
        $this->resultFactory = $resultFactory;
        $this->quoteFactory = $quoteFactory;
        $this->itemFactory = $itemFactory;
        $this->itemOptionFactory = $itemOptionFactory;
        $this->cartRepository = $cartRepository;
        $this->checkoutNotPaidManagement = $checkoutNotPaidManagement;
    }


    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\Result\Json|\Magento\Framework\Controller\ResultInterface|string
     */
    public function execute()
    {
        $operation = $this->getRequest()->getParam('operation');
        $functionName = lcfirst(str_replace('_', '', ucwords($operation, '_')));
        if (method_exists(\Aplazo\AplazoPayment\Controller\Order\Operations::class, $functionName)) {
            $response = $this->{$functionName}();
            if (is_array($response)) {
                return $this->resultFactory->create(ResultFactory::TYPE_JSON)->setData($response);
            } else {
                return $response;
            }
        }
        return '';
    }

    /**
     * @return array
     */
    public function purchase()
    {
        $popup = $this->getRequest()->getParam('mode') === CheckoutMode::MODE_POPUP
            && $this->aplazoHelper->getCheckoutMode() === CheckoutMode::MODE_POPUP;
        $requestedOrderId = (string)$this->getRequest()->getParam('order', '');
        if ($requestedOrderId !== '') {
            // The popup names the order it was opened for; it must be one placed in this same checkout session.
            $order = $this->orderService->getOrderByEntityId($requestedOrderId);
            if ($order === null || !$this->getPlacedOrders()->contains($order)) {
                $this->aplazoHelper->log('Operations>purchase: orden no pertenece a la sesion', AplazoHelper::LOGS_VVV);
                return $this->purchaseUnavailable($popup, __('We could not find this order in your session.'));
            }
        } else {
            $order = $this->checkoutSession->getLastRealOrder();
        }
        $this->aplazoHelper->log('Operations>purchase: Orden ' . $order->getEntityId() . ' debe redireccionar al checkout de aplazo.', AplazoHelper::LOGS_VVV);
        // Only an order still waiting for payment opens Checkout; reopening never repeats the stock compensation.
        if (!$order->getEntityId()
            || $this->orderService->preparePurchase($order) === OrderService::PURCHASE_NOT_ELIGIBLE
        ) {
            $this->aplazoHelper->log('Operations>purchase: la orden ya no esta pendiente de pago', AplazoHelper::LOGS_VVV);
            return $this->purchaseUnavailable(
                $popup,
                __('This order is no longer waiting for payment, so Aplazo cannot be opened again.')
            );
        }
        if (!empty($aplazoCheckout = $order->getAplazoCheckoutUrl())) {
            // Exploding cancel token and checkout Url
            if (strpos($aplazoCheckout, "||") !== false) {
                $aplazoCheckout = explode("||", $aplazoCheckout)[0];
            }
            // Provisional contract (BNPL-1607): tell Checkout it was opened as a popup.
            if ($popup) {
                $aplazoCheckout = $this->withPopupMode($aplazoCheckout);
            }
            $this->aplazoHelper->log('Operations>purchase: redireccion a ' . $aplazoCheckout , AplazoHelper::LOGS_VVV);
            $response = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setUrl($aplazoCheckout);
        } else {
            $this->aplazoHelper->log('Operations>purchase: No se encontro url ', AplazoHelper::LOGS_VVV);
            $response = $this->purchaseUnavailable($popup, __('Aplazo payment gateway is unavailable. Try again later.'));
        }
        return $response;
    }

    private function getPlacedOrders(): \Aplazo\AplazoPayment\Model\PlacedOrders
    {
        return \Magento\Framework\App\ObjectManager::getInstance()->get(\Aplazo\AplazoPayment\Model\PlacedOrders::class);
    }

    /**
     * Redirect mode keeps today's behaviour (cart with an error message). Inside the popup the cart would load in
     * Aplazo's window, so it shows a short page the buyer can close instead.
     */
    private function purchaseUnavailable(bool $popup, \Magento\Framework\Phrase $message)
    {
        if (!$popup) {
            $this->messageManager->addErrorMessage($message);
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }
        $text = htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8');
        $close = htmlspecialchars((string)__('Close this window'), ENT_QUOTES, 'UTF-8');
        return $this->resultFactory->create(ResultFactory::TYPE_RAW)
            ->setHeader('Content-Type', 'text/html; charset=utf-8', true)
            ->setContents(
                '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
                . '<title>Aplazo</title></head><body style="font-family:sans-serif;padding:24px">'
                . '<p>' . $text . '</p><button type="button" onclick="window.close()">' . $close . '</button>'
                . '</body></html>'
            );
    }

    /**
     * Sets mode=popup on the checkout URL, keeping the rest of the query and the fragment as they are.
     */
    private function withPopupMode(string $url): string
    {
        $fragment = '';
        $hashPosition = strpos($url, '#');
        if ($hashPosition !== false) {
            $fragment = substr($url, $hashPosition);
            $url = substr($url, 0, $hashPosition);
        }
        $query = '';
        $queryPosition = strpos($url, '?');
        if ($queryPosition !== false) {
            $query = substr($url, $queryPosition + 1);
            $url = substr($url, 0, $queryPosition);
        }
        $params = array_filter(explode('&', $query), function ($pair) {
            return urldecode(explode('=', $pair, 2)[0]) !== 'mode';
        });
        $query = rtrim(implode('&', $params), '&');
        return $url . '?' . ($query === '' ? '' : $query . '&') . 'mode=' . CheckoutMode::MODE_POPUP . $fragment;
    }

    public function cancel()
    {
        $incrementId = $this->getRequest()->getParam('incrementid');
        $token = $this->getRequest()->getParam('token');
        $result = $this->orderService->getOrderByIncrementId($incrementId);
        if ($result['success'] && !empty($aplazoCheckout = $result['order']->getAplazoCheckoutUrl())) {
            if (strpos($aplazoCheckout, "||") !== false) {
                $aplazoCheckout = explode("||", $aplazoCheckout)[1];
                if ($token == $aplazoCheckout){
                    $response = $this->checkoutNotPaidManagement->postCheckoutNotPaid($incrementId);
                    if (!empty($response->getMessage())) {
                        $this->messageManager->addWarningMessage($response->getMessage());
                    }
                }
            }
        }
        return $this->resultRedirectFactory->create()->setPath('checkout/cart');
    }

    public function redirectToOnepage()
    {
        $orderid = $this->getRequest()->getParam('orderid');
        $onepage = $this->getRequest()->getParam('onepage');
        $url = 'checkout/onepage/failure/';
        if ($orderid) {
            $response = $this->orderService->getQuoteIdByOrderId($orderid);
            if ($response['success']) {
                if (isset($response['quote_id'])) {
                    if ($onepage == 'success') {
                        $this->checkoutSession->setLastSuccessQuoteId($response['quote_id']);
                        $url = 'checkout/onepage/success/';
                    }
                    $this->checkoutSession->setLastQuoteId($response['quote_id']);
                    $this->checkoutSession->setLastOrderId($response['order_id']);
                }
            }
        }
        return $this->resultRedirectFactory->create()->setPath($url);
    }
}
