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
use Magento\Store\Model\StoreManagerInterface;

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
    private StoreManagerInterface $storeManager;

    public function __construct(
        AplazoHelper $aplazoHelper,
        PlacedOrders $placedOrders,
        OrderRepositoryInterface $orderRepository,
        RawFactory $rawFactory,
        Escaper $escaper,
        SecureHtmlRenderer $secureRenderer,
        StoreManagerInterface $storeManager
    ) {
        $this->aplazoHelper = $aplazoHelper;
        $this->placedOrders = $placedOrders;
        $this->orderRepository = $orderRepository;
        $this->rawFactory = $rawFactory;
        $this->escaper = $escaper;
        $this->secureRenderer = $secureRenderer;
        $this->storeManager = $storeManager;
    }

    /**
     * The request comes from the popup flow (mode=popup), whatever the current configuration says. Such a request is
     * always validated as a popup request and is never handled as a redirect.
     */
    public function isPopupRequested(RequestInterface $request): bool
    {
        return $request->getParam('mode') === CheckoutMode::MODE_POPUP;
    }

    /**
     * Popup mode is effective for this store right now (configured, with a known checkout origin).
     */
    public function isPopupEnabled(): bool
    {
        return $this->aplazoHelper->getCheckoutMode() === CheckoutMode::MODE_POPUP;
    }

    /**
     * For showing an order's status only (popup_return): the Aplazo order with this entity id, when it was placed in
     * the buyer's own checkout session and belongs to the current store. An id from the request is never trusted on
     * its own. This does not allow running purchase for the order: see isPurchaseTarget().
     */
    public function getSessionOrder(string $orderId): ?OrderInterface
    {
        $entityId = PlacedOrders::normalizeId($orderId);
        if ($entityId === null) {
            return null;
        }
        try {
            $order = $this->orderRepository->get($entityId);
        } catch (NoSuchEntityException $e) {
            return null;
        }
        $payment = $order->getPayment();
        if ($payment === null || $payment->getMethod() !== ConfigProvider::CODE
            || (int)$order->getStoreId() !== (int)$this->storeManager->getStore()->getId()
            || !$this->placedOrders->contains($order)
        ) {
            return null;
        }

        return $order;
    }

    /**
     * Whether purchase may run for the order the popup names. Purchase keeps master's scope: only the checkout
     * session's last order (getLastRealOrder(), resolved by Magento for the current store). The id sent by the popup
     * must name that same order, so neither an older order of the session history nor another tab's newer order is
     * opened, and no stock compensation runs for them.
     */
    public function isPurchaseTarget(OrderInterface $lastRealOrder, string $requestedOrderId): bool
    {
        $requested = PlacedOrders::normalizeId($requestedOrderId);

        return $requested !== null
            && PlacedOrders::normalizeId($lastRealOrder->getEntityId()) === $requested
            && $this->placedOrders->contains($lastRealOrder);
    }

    /**
     * Whether Magento's standard success page would show this order: it renders the session's last order, so it is
     * only used when that is this same order. Only compares; it changes nothing.
     */
    public function isLastSessionOrder(OrderInterface $order, OrderInterface $lastRealOrder): bool
    {
        $entityId = PlacedOrders::normalizeId($order->getEntityId());

        return $entityId !== null && PlacedOrders::normalizeId($lastRealOrder->getEntityId()) === $entityId;
    }

    /**
     * The URL the popup may navigate to for this order, with mode=popup, or null when it must not be opened.
     *
     * The URL is the one stored at order creation from the loan API response (without the cancel token). It is used
     * only when it is an absolute https URL on the configured checkout origin (same scheme, host and effective port),
     * without credentials. Everything is parsed and built here, before the caller runs any stock compensation.
     */
    public function getPopupCheckoutUrl(OrderInterface $order): ?string
    {
        $stored = (string)$order->getAplazoCheckoutUrl();
        if ($stored === '') {
            return null;
        }
        $url = explode('||', $stored, 2)[0];
        $origin = $this->aplazoHelper->getCheckoutOrigin();
        if ($origin === null || !$this->isOnOrigin($url, $origin)) {
            return null;
        }
        try {
            return $this->withPopupMode($url);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Strict origin check: absolute https URL, no user info, same host (case-insensitive, no suffix or subdomain
     * match) and same effective port as the configured origin. Whitespace, control characters and backslashes are
     * rejected before parsing, because browsers and parsers can read them differently.
     */
    public function isOnOrigin(string $url, string $origin): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }
        try {
            $uri = new HttpUri($url);
            $expected = new HttpUri($origin);
        } catch (\Throwable $e) {
            return false;
        }
        if (!$uri->isValid() || !$uri->isAbsolute()
            || strtolower((string)$uri->getScheme()) !== 'https' || strtolower((string)$expected->getScheme()) !== 'https'
            || (string)$uri->getUserInfo() !== ''
        ) {
            return false;
        }
        $host = strtolower((string)$uri->getHost());
        if ($host === '' || $host !== strtolower((string)$expected->getHost())) {
            return false;
        }

        return ((int)$uri->getPort() ?: 443) === ((int)$expected->getPort() ?: 443);
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
