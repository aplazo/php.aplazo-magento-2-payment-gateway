<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model;

use Aplazo\AplazoPayment\Api\CheckoutNotPaidManagementInterface;
use Aplazo\AplazoPayment\Api\CheckoutNotPaidManagementResponseInterfaceFactory;
use Aplazo\AplazoPayment\Model\Service\OrderService;
use Aplazo\AplazoPayment\Service\ApiService as AplazoService;
use Aplazo\AplazoPayment\Service\LogService;
use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;

class CheckoutNotPaidManagement implements CheckoutNotPaidManagementInterface
{
    /**
     * @var AplazoService
     */
    private $aplazoService;

    /**
     * @var AplazoHelper
     */
    private $aplazoHelper;

    /**
     * @var OrderService
     */
    private $orderService;
    private $productRepository;
    private $cartRepository;
    private $quoteFactory;
    private $checkoutSession;
    private $responseInterfaceFactory;
    private LogService $logService;

    public function __construct(
        OrderService  $orderService,
        AplazoHelper  $aplazoHelper,
        AplazoService $aplazoService,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\Quote\Api\CartRepositoryInterface $cartRepository,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Checkout\Model\Session $session,
        CheckoutNotPaidManagementResponseInterfaceFactory $responseInterfaceFactory,
        LogService $logService
    )
    {
        $this->orderService = $orderService;
        $this->aplazoHelper = $aplazoHelper;
        $this->aplazoService = $aplazoService;
        $this->productRepository = $productRepository;
        $this->cartRepository = $cartRepository;
        $this->quoteFactory = $quoteFactory;
        $this->checkoutSession = $session;
        $this->responseInterfaceFactory = $responseInterfaceFactory;
        $this->logService = $logService;
    }

    /**
     * {@inheritdoc}
     */
    public function postCheckoutNotPaid($incrementId)
    {
        $this->logService->resetRequestId();
        $this->logService->send('info', 'Checkout not paid: processing', ['module:cancel'], ['increment_id' => $incrementId, 'recover_cart_enabled' => $this->aplazoHelper->getEnableRecoverCart() ? 'yes' : 'no']);
        $orderArray = $this->orderService->getOrderByIncrementId($incrementId);
        /** @var \Aplazo\AplazoPayment\Api\CheckoutNotPaidManagementResponseInterface $return */
        $return = $this->responseInterfaceFactory->create();
        $return->setMessage('')
            ->setQuoteId(null)
            ->setMessageError(null);
        if ($order = $orderArray['order']) {
            $this->logService->send('info', 'Checkout not paid: cancelling order', ['module:cancel'], ['increment_id' => $incrementId, 'order_status' => $order->getStatus()]);
            $this->orderService->cancelOrder($order->getId());
            $this->checkoutSession->unsLastRealOrderId();
            $this->checkoutSession->clearHelperData();
            $return->setMessage($this->aplazoHelper->getCancelMessage());
            if($this->aplazoHelper->getEnableRecoverCart()){
                $quoteId = $order->getQuoteId();
                try {
                    $quote = $this->cartRepository->get($quoteId);
                    /** @var \Magento\Quote\Model\Quote $newQuote */
                    $newQuote = $this->quoteFactory->create();
                    $newQuote->setStoreId($quote->getStoreId());
                    $newQuote->setCustomerId($quote->getCustomerId());
                    $newQuote->setCustomerEmail($quote->getCustomerEmail());
                    $newQuote->setCustomerFirstname($quote->getCustomerFirstname());
                    $newQuote->setCustomerLastname($quote->getCustomerLastname());
                    $newQuote->setCustomerGroupId($quote->getCustomerGroupId());
                    $newQuote->setCustomerIsGuest($quote->getCustomerIsGuest());
                    $newQuote->setIsActive(true);
                    $this->logService->send('info', 'New quote created with customer context', ['module:cancel'], [
                        'increment_id' => $incrementId,
                        'old_quote_id' => $quoteId,
                        'customer_id' => $quote->getCustomerId(),
                        'customer_email' => $quote->getCustomerEmail(),
                        'is_guest' => $quote->getCustomerIsGuest()
                    ]);
                    $canRecoverQuote = false;
                    foreach ($quote->getAllVisibleItems() as $item) {
                        try{
                            $product = $this->productRepository->getById($item->getProduct()->getId());
                            $options = $item->getOptions();
                            foreach($options as $option){
                                if($option->getCode() === 'info_buyRequest'){
                                    $request = new \Magento\Framework\DataObject(json_decode($option->getValue(), true));
                                    $newQuote->addProduct($product, $request);
                                    $canRecoverQuote = true;
                                    $this->checkoutSession->setLastAddedProductId($product->getId());
                                    break;
                                }
                            }
                        } catch (\Exception $e) {
                            $this->logService->send('warn', 'Could not recover product for cart', ['module:cancel'], ['increment_id' => $incrementId, 'product_id' => $item->getProduct()->getId(), 'error' => $e->getMessage()]);
                        }
                    }
                    if($canRecoverQuote){
                        $newQuote->getBillingAddress();
                        $newQuote->getShippingAddress()->setCollectShippingRates(true);
                        $newQuote->collectTotals();
                        $this->cartRepository->save($newQuote);
                        $this->checkoutSession->replaceQuote($newQuote);
                        $return->setQuoteId($newQuote->getId());
                        $this->logService->send('info', 'Cart recovered successfully', ['module:cancel'], [
                            'increment_id' => $incrementId,
                            'new_quote_id' => $newQuote->getId(),
                            'customer_id' => $newQuote->getCustomerId(),
                            'is_guest' => $newQuote->getCustomerIsGuest(),
                            'items_count' => $newQuote->getItemsCount(),
                            'grand_total' => $newQuote->getGrandTotal()
                        ]);
                    } else {
                        $return->setMessageError('No se pudo recuperar el carrito');
                        $this->logService->send('warn', 'Cart recovery failed: no recoverable items', ['module:cancel'], ['increment_id' => $incrementId]);
                    }
                } catch (\Exception $e) {
                    $return->setMessageError('No se pudo recuperar el carrito. Mensaje de error ' .$e->getMessage());
                    $this->logService->send('error', 'Cart recovery exception', ['module:cancel'], ['increment_id' => $incrementId, 'error' => $e->getMessage()]);
                }
            }
        } else {
            $this->logService->send('warn', 'Order not found, redirecting to cart', ['module:cancel'], ['increment_id' => $incrementId]);
        }
        return $return;
    }
}
