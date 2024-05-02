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

    public function __construct(
        OrderService  $orderService,
        AplazoHelper  $aplazoHelper,
        AplazoService $aplazoService,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\Quote\Api\CartRepositoryInterface $cartRepository,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Checkout\Model\Session $session,
        CheckoutNotPaidManagementResponseInterfaceFactory $responseInterfaceFactory
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
    }

    /**
     * {@inheritdoc}
     */
    public function postCheckoutNotPaid($incrementId)
    {
        $orderArray = $this->orderService->getOrderByIncrementId($incrementId);
        /** @var \Aplazo\AplazoPayment\Api\CheckoutNotPaidManagementResponseInterface $return */
        $return = $this->responseInterfaceFactory->create();
        $return->setMessage('')
            ->setQuoteId(null)
            ->setMessageError(null);
        if ($order = $orderArray['order']) {
            $this->orderService->cancelOrder($order->getId());
            $return->setMessage($this->aplazoHelper->getCancelMessage());
            if($this->aplazoHelper->getEnableRecoverCart()){
                $quoteId = $order->getQuoteId();
                try {
                    $quote = $this->cartRepository->get($quoteId);
                    /** @var \Magento\Quote\Model\Quote $newQuote */
                    $newQuote = $this->quoteFactory->create()->setStoreId($quote->getStoreId())->save();
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
                            $this->aplazoService->sendLog('No se pudo recuperar el producto ' . $product->getId() . ' ' . $product->getName(), AplazoHelper::LOGS_CATEGORY_WARNING, AplazoHelper::LOGS_SUBCATEGORY_ORDER,
                        ['method' => 'postCheckoutNotPaid', 'class' => '\Aplazo\AplazoPayment\Model\CheckoutNotPaidManagement', 'error' => $e->getMessage()]);
                        }
                    }
                    if($canRecoverQuote){
                        $newQuote->getBillingAddress();
                        $newQuote->getShippingAddress()->setCollectShippingRates(true);
                        $newQuote->collectTotals();
                        $this->cartRepository->save($newQuote);
                        $this->checkoutSession->setQuoteId($newQuote->getId());
                        $return->setQuoteId($newQuote->getId());
                    } else {
                        $return->setMessageError('No se pudo recuperar el carrito');
                    }
                } catch (\Exception $e) {
                    $return->setMessageError('No se pudo recuperar el carrito. Mensaje de error ' .$e->getMessage());
                    $this->aplazoService->sendLog('No se pudo crear el quote de ' . $incrementId, AplazoHelper::LOGS_CATEGORY_WARNING, AplazoHelper::LOGS_SUBCATEGORY_ORDER,
                        ['method' => 'cancel', 'class' => '\Aplazo\AplazoPayment\Controller\Order\Operations', 'error' => $e->getMessage()]);
                }
            }
        } else {
            $this->aplazoService->sendLog('No se pudo obtener la orden id ' . $incrementId . ', se procede ir a carrito', AplazoHelper::LOGS_CATEGORY_WARNING, AplazoHelper::LOGS_SUBCATEGORY_ORDER,
                ['method' => 'cancel', 'class' => '\Aplazo\AplazoPayment\Controller\Order\Operations']);
        }
        return $return;
    }
}
