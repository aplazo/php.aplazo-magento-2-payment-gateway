<?php

namespace Aplazo\AplazoPayment\Model;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Aplazo\AplazoPayment\Model\Config\Source\CheckoutMode;
use Aplazo\AplazoPayment\Model\Ui\ConfigProvider;
use Laminas\Uri\Http as HttpUri;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Server side of the popup checkout mode (BNPL-1664). Kept out of the Operations controller because the controller
 * dispatches any of its own method names taken from the request.
 */
class PopupCheckout
{
    private AplazoHelper $aplazoHelper;
    private PlacedOrders $placedOrders;
    private OrderRepositoryInterface $orderRepository;
    private RawFactory $rawFactory;
    private Escaper $escaper;
    private SecureHtmlRenderer $secureRenderer;

    public function __construct(
        AplazoHelper $aplazoHelper,
        PlacedOrders $placedOrders,
        OrderRepositoryInterface $orderRepository,
        RawFactory $rawFactory,
        Escaper $escaper,
        SecureHtmlRenderer $secureRenderer
    ) {
        $this->aplazoHelper = $aplazoHelper;
        $this->placedOrders = $placedOrders;
        $this->orderRepository = $orderRepository;
        $this->rawFactory = $rawFactory;
        $this->escaper = $escaper;
        $this->secureRenderer = $secureRenderer;
    }

    /**
     * True only when the page asked for popup mode and popup mode is effective for this store.
     */
    public function isPopupRequest(RequestInterface $request): bool
    {
        return $request->getParam('mode') === CheckoutMode::MODE_POPUP
            && $this->aplazoHelper->getCheckoutMode() === CheckoutMode::MODE_POPUP;
    }

    /**
     * The Aplazo order with this entity id, only when it was placed in the buyer's own checkout session.
     * An order id taken from the request is never trusted on its own.
     */
    public function getSessionOrder(string $orderId): ?OrderInterface
    {
        if ($orderId === '' || !ctype_digit($orderId)) {
            return null;
        }
        try {
            $order = $this->orderRepository->get((int)$orderId);
        } catch (NoSuchEntityException $e) {
            return null;
        }
        $payment = $order->getPayment();
        if ($payment === null || $payment->getMethod() !== ConfigProvider::CODE || !$this->placedOrders->contains($order)) {
            return null;
        }

        return $order;
    }

    /**
     * Sets mode=popup on the checkout URL. The rest of the query is kept exactly as encoded, and so is the fragment.
     */
    public function withPopupMode(string $url): string
    {
        $uri = new HttpUri($url);
        $pairs = array_filter(explode('&', (string)$uri->getQuery()), function ($pair) {
            return $pair !== '' && urldecode(explode('=', $pair, 2)[0]) !== 'mode';
        });
        $pairs[] = 'mode=' . CheckoutMode::MODE_POPUP;
        $uri->setQuery(implode('&', $pairs));

        return $uri->toString();
    }

    /**
     * Shown inside Aplazo's window when Checkout cannot be opened: the cart would otherwise load in the popup.
     * The style and the button's listener go through SecureHtmlRenderer, so Magento_Csp can allow them by hash
     * instead of relying on inline attributes.
     */
    public function unavailablePage(Phrase $message): Raw
    {
        return $this->rawFactory->create()
            ->setHeader('Content-Type', 'text/html; charset=utf-8', true)
            ->setContents(
                '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
                . '<title>Aplazo</title>'
                . $this->secureRenderer->renderTag('style', [], 'body{font-family:sans-serif;padding:24px}', false)
                . '</head><body>'
                . '<p>' . $this->escaper->escapeHtml((string)$message) . '</p>'
                . '<button type="button" id="aplazo-popup-close">'
                . $this->escaper->escapeHtml((string)__('Close this window')) . '</button>'
                . $this->secureRenderer->renderEventListenerAsTag('onclick', 'window.close()', '#aplazo-popup-close')
                . '</body></html>'
            );
    }
}
