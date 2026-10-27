<?php

namespace Aplazo\AplazoPayment\Controller\Order;

use Aplazo\AplazoPayment\Api\CheckoutNotPaidManagementInterface;
use Aplazo\AplazoPayment\Helper\Data;
use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Aplazo\AplazoPayment\Model\CheckoutModeMarker;
use Aplazo\AplazoPayment\Model\PopupCheckout;
use Aplazo\AplazoPayment\Service\ApiService as AplazoService;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Aplazo\AplazoPayment\Model\Service\OrderService;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote\Item\OptionFactory;
use Magento\Quote\Model\Quote\ItemFactory;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Model\Order;

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
    private PopupCheckout $popupCheckout;


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
        CheckoutNotPaidManagementInterface $checkoutNotPaidManagement,
        PopupCheckout                      $popupCheckout
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
        $this->popupCheckout = $popupCheckout;
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
        // A popup request (mode=popup) is always validated as one, even if popup mode was turned off meanwhile; it is never
        // handled as a redirect. Both checks run before any stock compensation or redirect.
        $popup = $this->popupCheckout->isPopupRequested($this->getRequest());
        $order = $this->checkoutSession->getLastRealOrder();
        if ($popup) {
            if (!$this->popupCheckout->isPopupEnabled()) {
                $this->aplazoHelper->log('Operations>purchase: peticion popup con el modo popup desactivado', AplazoHelper::LOGS_VVV);
                return $this->popupCheckout->unavailablePage(__('Aplazo cannot be opened in its own window right now. Please return to the store page.'));
            }
            // Purchase keeps master's scope (the session's last order); the popup's order id must name that order.
            if (!$this->popupCheckout->isPurchaseTarget($order, (string)$this->getRequest()->getParam('order', ''))) {
                $this->aplazoHelper->log('Operations>purchase: la orden solicitada no es la ultima orden de la sesion', AplazoHelper::LOGS_VVV);
                return $this->popupCheckout->unavailablePage(__('We could not open Aplazo for this order from this window.'));
            }
            // The checkout URL is validated against the configured origin and built with mode=popup (provisional
            // contract, BNPL-1666) before the stock compensation; an unexpected URL is never opened in the popup.
            $popupCheckoutUrl = $this->popupCheckout->getPopupCheckoutUrl($order);
            if ($popupCheckoutUrl === null) {
                $this->aplazoHelper->log('Operations>purchase: url de checkout ausente o fuera del origen configurado', AplazoHelper::LOGS_VVV);
                return $this->popupCheckout->unavailablePage(__('Aplazo payment gateway is unavailable. Try again later.'));
            }
            $this->aplazoHelper->log('Operations>purchase: Orden ' . $order->getEntityId() . ' debe abrir el checkout de aplazo en la ventana popup.', AplazoHelper::LOGS_VVV);
            // Popup orders (marked at creation) keep Magento's native reservation: no plugin compensation (BNPL-1665).
            // Orders without the mark keep the previous behaviour.
            if (!CheckoutModeMarker::isPopupOrder($order)) {
                $this->orderService->reservingStockUntilPayment($order, 'aplazo_item_reserved');
            }
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setUrl($popupCheckoutUrl);
        }
        $this->aplazoHelper->log('Operations>purchase: Orden ' . $order->getEntityId() . ' debe redireccionar al checkout de aplazo.', AplazoHelper::LOGS_VVV);
        // An order marked as popup at creation gets no compensation here either, whatever URL reached purchase.
        if (!CheckoutModeMarker::isPopupOrder($order)) {
            $this->orderService->reservingStockUntilPayment($order, 'aplazo_item_reserved');
        }
        if (!empty($aplazoCheckout = $order->getAplazoCheckoutUrl())) {
            // Exploding cancel token and checkout Url
            if (strpos($aplazoCheckout, "||") !== false) {
                $aplazoCheckout = explode("||", $aplazoCheckout)[0];
            }
            $this->aplazoHelper->log('Operations>purchase: redireccion a ' . $aplazoCheckout , AplazoHelper::LOGS_VVV);
            $response = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setUrl($aplazoCheckout);
        } else {
            $this->aplazoHelper->log('Operations>purchase: No se encontro url ', AplazoHelper::LOGS_VVV);
            $this->messageManager->addErrorMessage(__('Aplazo payment gateway is unavailable. Try again later.'));
            $response = $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }
        return $response;
    }

    /**
     * "Go to cart" from the popup recovery notice, after an explicit click.
     *
     * It never cancels the order, cancels the loan or rebuilds the cart: the store page cannot know whether the buyer
     * paid, because Checkout's messages and a closed window are UI signals only. The order keeps its normal lifecycle
     * (the webhook, or the cancel cron when it is enabled), and the cart page says that the order may still be pending.
     */
    public function popupReturn()
    {
        $order = $this->popupCheckout->getSessionOrder((string)$this->getRequest()->getParam('order', ''));
        if ($order !== null && $order->getState() === Order::STATE_NEW) {
            $this->messageManager->addNoticeMessage(__(
                'Your order %1 was placed and may still be pending while Aplazo confirms the payment, so your cart is empty. If you completed the payment, you will receive the confirmation. Before placing another order, wait until the result of this one is confirmed.',
                $order->getIncrementId()
            ));
        }
        return $this->resultRedirectFactory->create()->setPath('checkout/cart');
    }

    /**
     * Where the store page goes after the popup's success message, for the order that popup was opened for.
     *
     * The message is a UI signal, never proof of payment: the order is not changed, and neither is the session's
     * last-order data. Magento's success page renders the session's last order, so it is used only when that is this
     * order. If another tab placed a newer order since, the success page would show that one; this order gets a neutral
     * notice instead. The notice is a Magento session message, so it is shown by the next page of the session that
     * renders messages, which may be in another tab.
     */
    public function popupSuccess()
    {
        $order = $this->popupCheckout->getSessionOrder((string)$this->getRequest()->getParam('order', ''));
        if ($order === null) {
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }
        if ($this->popupCheckout->isLastSessionOrder($order, $this->checkoutSession->getLastRealOrder())) {
            return $this->resultRedirectFactory->create()->setPath('checkout/onepage/success');
        }
        $this->messageManager->addNoticeMessage(__(
            'Aplazo reported the payment for your order %1 as completed. You will receive the order confirmation once Aplazo confirms it.',
            $order->getIncrementId()
        ));
        return $this->resultRedirectFactory->create()->setPath('checkout/cart');
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
