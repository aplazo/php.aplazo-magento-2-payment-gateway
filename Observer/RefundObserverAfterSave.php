<?php

namespace Aplazo\AplazoPayment\Observer;

use Aplazo\AplazoPayment\Helper\Data;
use Aplazo\AplazoPayment\Model\RefundRequest;
use Aplazo\AplazoPayment\Model\RefundRequestFactory;
use Aplazo\AplazoPayment\Model\Ui\ConfigProvider;
use Aplazo\AplazoPayment\Api\RefundRequestRepositoryInterface;
use Aplazo\AplazoPayment\Service\LogService;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Aplazo\AplazoPayment\Service\TrackingService;

class RefundObserverAfterSave implements ObserverInterface
{
    private const TYPE_CREDITMEMO = 'creditmemo';

    public function __construct(
        private RefundRequestFactory $refundRequestFactory,
        private RefundRequestRepositoryInterface $refundRequestRepository,
        private OrderRepositoryInterface $orderRepository,
        private ManagerInterface $messageManager,
        private Data $data,
        private TrackingService $trackingService,
        private LogService $logService
    ) {
    }

    public function execute(Observer $observer)
    {
        /** @var Creditmemo|null $creditMemo */
        $creditMemo = $observer->getData('creditmemo');
        if (!$creditMemo) {
            return;
        }

        $order = $creditMemo->getOrder();
        if (!$order || !$order->getEntityId()) {
            return;
        }

        $payment = $order->getPayment();
        $paymentMethod = $payment ? (string)$payment->getMethod() : null;
        if ($paymentMethod !== ConfigProvider::CODE) {
            return;
        }

        if (!$this->data->getRefund()) {
            $this->messageManager->addErrorMessage(
                __('The refund will be made offline since the Aplazo refund option is not activated in the configuration')
            );
            return;
        }

        $useDisplayAmounts = $this->data->shouldUseDisplayAmounts($order);
        $amount = (float)($useDisplayAmounts ? $creditMemo->getGrandTotal() : $creditMemo->getBaseGrandTotal());
        $amountCents = (int)round($amount * 100);
        $currency = $useDisplayAmounts
            ? (string)($order->getOrderCurrencyCode() ?: $order->getBaseCurrencyCode() ?: '')
            : (string)($order->getBaseCurrencyCode() ?: $order->getOrderCurrencyCode() ?: '');

        $reason = '';
        foreach ($creditMemo->getComments() as $index => $comment) {
            $reason .= $index . '. ' . $comment->getComment() . '.  ';
        }
        $reasonHash = hash('sha256', (string)$reason);

        $itemsFingerprint = $this->buildCreditmemoItemsFingerprint($creditMemo);
        $idempotencyKey = hash('sha256', json_encode([
            'type' => self::TYPE_CREDITMEMO,
            'orderIncrementId' => (string)$order->getIncrementId(),
            'amountCents' => $amountCents,
            'currency' => $currency,
            'items' => $itemsFingerprint,
            'reasonHash' => $reasonHash,
        ], JSON_UNESCAPED_SLASHES));

        $payload = [
            'cartId' => (string)$order->getIncrementId(),
            'totalAmount' => $amount,
            'reason' => $reason,
        ];

        try {
            /** @var RefundRequest $refundRequest */
            $refundRequest = $this->refundRequestFactory->create();
            $refundRequest->setType(self::TYPE_CREDITMEMO);
            $refundRequest->setStatus(RefundRequest::STATUS_PENDING);
            $refundRequest->setOrderIncrementId((string)$order->getIncrementId());
            $refundRequest->setOrderId((int)$order->getEntityId());
            $refundRequest->setCreditmemoId((int)$creditMemo->getEntityId());
            $refundRequest->setAmountCents($amountCents);
            $refundRequest->setCurrency($currency);
            $refundRequest->setReason($reason);
            $refundRequest->setReasonHash($reasonHash);
            $refundRequest->setItemsHash(hash('sha256', json_encode($itemsFingerprint, JSON_UNESCAPED_SLASHES)));
            $refundRequest->setIdempotencyKey($idempotencyKey);
            $refundRequest->setPayloadJson(json_encode($payload, JSON_UNESCAPED_SLASHES));
            $refundRequest->setRetries(0);
            $refundRequest->setNextAttemptAt(null);

            $this->refundRequestRepository->save($refundRequest);

            $this->logService->send('info', 'Refund queued', ['module:refund'], ['order_id' => (string)$order->getIncrementId(), 'amount' => $amount, 'idempotency_key' => $idempotencyKey]);

            $message = __('Credit memo created. Aplazo refund has been queued and will be processed shortly.');
            $this->messageManager->addSuccessMessage($message);

            $order->addCommentToStatusHistory((string)$message . ' ' . __('Idempotency key: %1', $idempotencyKey));
            $this->orderRepository->save($order);

            try {
                $this->trackingService->sendEvent(
                    TrackingService::EVENT_REFUND_CREATED,
                    [
                        'orderId' => (int)$order->getEntityId(),
                        'orderIncrementId' => (string)$order->getIncrementId(),
                        'creditMemoId' => (int)$creditMemo->getEntityId(),
                        'amountCents' => $amountCents,
                        'currency' => $currency,
                        'idempotencyKey' => $idempotencyKey,
                    ],
                    []
                );
            } catch (\Throwable $e) {
                // Never block credit memo save because of tracking.
            }
        } catch (AlreadyExistsException $e) {
            // Dedupe hit: the refund request already exists.
            $message = __('Aplazo refund is already queued for this credit memo. Please wait and check the order history.');
            $this->messageManager->addWarningMessage($message);
        } catch (\Throwable $e) {
            $this->logService->send('error', 'Failed to queue refund: ' . $e->getMessage(), ['module:refund'], ['order_id' => (string)$order->getIncrementId(), 'amount' => $amount]);
            $this->messageManager->addErrorMessage(
                __('Credit memo created, but the Aplazo refund could not be queued. Please check logs. %1', $e->getMessage())
            );
        }
    }

    /**
     * @return array<int, array{orderItemId:int,qty:float,rowTotalCents:int}>
     */
    private function buildCreditmemoItemsFingerprint(Creditmemo $creditMemo): array
    {
        $items = [];
        foreach ($creditMemo->getAllItems() as $item) {
            $qty = (float)$item->getQty();
            if ($qty <= 0.0) {
                continue;
            }

            $items[] = [
                'orderItemId' => (int)$item->getOrderItemId(),
                'qty' => $qty,
                'rowTotalCents' => (int)round(((float)$item->getRowTotal()) * 100),
            ];
        }

        usort($items, static fn(array $a, array $b) => $a['orderItemId'] <=> $b['orderItemId']);
        return $items;
    }
}

