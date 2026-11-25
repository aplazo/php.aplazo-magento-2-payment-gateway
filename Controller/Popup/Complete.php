<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Controller\Popup;

use Aplazo\AplazoPayment\Model\PaymentFirst\AttemptStore;
use Aplazo\AplazoPayment\Model\PaymentFirst\Finalizer;
use Aplazo\AplazoPayment\Model\PaymentFirst\SessionAttempts;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * POST aplazo/popup/complete (BNPL-1664): what the checkout page asks after any signal from Aplazo's window (a message,
 * the window closing, the page regaining focus). Idempotent: it runs the shared finalization, which confirms the
 * payment with Aplazo; a signal from the window is never taken as payment.
 *
 * Only attempts started by this session (and customer) are answered. On completion the session's last order data is
 * set here, for the buyer, as PayPal's OnAuthorization does; the webhook and the reconciliation never touch a session.
 * When the session already holds another active cart with items, Magento's success page is not used, because it
 * clears the session's cart: the order number is shown on the checkout page instead.
 *
 * Results: completed (+ redirect, or + notice) · not_paid · pending · rejected · review · unknown_attempt.
 */
class Complete implements HttpPostActionInterface, CsrfAwareActionInterface
{
    use FormKeyRequired;

    public function __construct(
        private JsonFactory $jsonFactory,
        private FormKeyValidator $formKeyValidator,
        private RequestInterface $request,
        private AttemptStore $attemptStore,
        private SessionAttempts $sessionAttempts,
        private Finalizer $finalizer,
        private CheckoutSession $checkoutSession,
        private CartRepositoryInterface $cartRepository,
        private OrderRepositoryInterface $orderRepository,
        private UrlInterface $url
    ) {
    }

    public function execute()
    {
        return $this->jsonFactory->create()->setData($this->complete());
    }

    private function complete(): array
    {
        $attempt = $this->attemptStore->getByPublicId((string)$this->request->getParam('attempt', ''));
        if (!$this->sessionAttempts->owns($attempt)) {
            return ['status' => 'unknown_attempt', 'message' => (string)__('We could not find this payment in your session. If you paid, you will receive the confirmation by email.')];
        }
        $result = $this->finalizer->finalize($attempt, Finalizer::SOURCE_BROWSER, 0);
        switch ($result['status']) {
            case Finalizer::COMPLETED:
                return $this->completed($result['attempt']);
            case Finalizer::NOT_PAID:
                return ['status' => 'not_paid'];
            case Finalizer::REJECTED:
                return ['status' => 'rejected', 'message' => (string)__('Your payment was received but we could not create your order with this cart. We are reviewing it and will contact you; we have not confirmed a reversal yet.')];
            case Finalizer::NEEDS_REVIEW:
                return ['status' => 'review', 'message' => (string)__('We are reviewing your payment. You will receive the confirmation by email.')];
            default:
                // busy or unknown: the payment status could not be decided now.
                return ['status' => 'pending'];
        }
    }

    private function completed(array $attempt): array
    {
        $order = $this->orderRepository->get((int)$attempt['order_id']);
        $quoteId = (int)$attempt['quote_id'];
        if ((int)$order->getQuoteId() !== $quoteId) {
            return ['status' => 'review', 'message' => (string)__('We are reviewing your payment. You will receive the confirmation by email.')];
        }
        $sessionQuoteId = (int)$this->checkoutSession->getQuoteId();
        if ($sessionQuoteId && $sessionQuoteId !== $quoteId && $this->hasActiveItems($sessionQuoteId)) {
            return ['status' => 'completed', 'notice' => (string)__('Your order %1 was confirmed. You will receive the confirmation by email.', $order->getIncrementId())];
        }
        $this->checkoutSession->setLastQuoteId($quoteId)->setLastSuccessQuoteId($quoteId);
        $this->checkoutSession->setLastOrderId($order->getEntityId())
            ->setLastRealOrderId($order->getIncrementId())
            ->setLastOrderStatus($order->getStatus());

        return ['status' => 'completed', 'redirect' => $this->url->getUrl('checkout/onepage/success')];
    }

    private function hasActiveItems(int $quoteId): bool
    {
        try {
            $quote = $this->cartRepository->get($quoteId);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            return false;
        }

        return (bool)$quote->getIsActive() && (int)$quote->getItemsCount() > 0;
    }
}
