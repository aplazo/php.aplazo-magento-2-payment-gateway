<?php

namespace Aplazo\AplazoPayment\Observer;

use Aplazo\AplazoPayment\Helper\Data;
use Aplazo\AplazoPayment\Api\RefundRequestRepositoryInterface;
use Aplazo\AplazoPayment\Model\RefundRequest;
use Aplazo\AplazoPayment\Model\RefundRequestFactory;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class RmaObserverAfterSave implements ObserverInterface
{
    private const TYPE_RMA = 'rma';
    private const RMA_REFUND_RESOLUTION = 5;
    private const RMA_STATUS_APPROVED = 'approved';

    public function __construct(
        private RefundRequestFactory $refundRequestFactory,
        private RefundRequestRepositoryInterface $refundRequestRepository,
        private OrderItemRepositoryInterface $orderItemRepository,
        private OrderRepositoryInterface $orderRepository,
        private ManagerInterface $messageManager,
        private Data $data
    ) {
    }

    public function execute(Observer $observer)
    {
        if (
            !$this->data->getRmaRefund()
            || !class_exists('Magento\\Rma\\Model\\ItemFactory')
        ) {
            return;
        }

        /** @var \Magento\Rma\Model\Rma|null $rma */
        $rma = $observer->getData('rma');
        if (!$rma) {
            return;
        }

        $orderIncrementId = (string)$rma->getOrderIncrementId();
        $rmaEntityId = (int)$rma->getEntityId();

        /** @var \Magento\Rma\Model\ItemFactory $itemFactory */
        $itemFactory = \Magento\Framework\App\ObjectManager::getInstance()->get('Magento\\Rma\\Model\\ItemFactory');

        // Aplazo always settles in MXN; resolve whether to send display or base amounts
        // from the order's currencies. Falls back to display (previous behavior) if the
        // order cannot be loaded.
        $useDisplayAmounts = true;
        $currency = '';
        try {
            $order = $this->orderRepository->get((int)$rma->getOrderId());
            $useDisplayAmounts = $this->data->shouldUseDisplayAmounts($order);
            $currency = $useDisplayAmounts
                ? (string)($order->getOrderCurrencyCode() ?: '')
                : (string)($order->getBaseCurrencyCode() ?: '');
        } catch (\Throwable $e) {
            // Could not resolve order currency; keep display amounts and empty currency.
        }

        foreach ((array)$rma->getItems() as $item) {
            if (
                (int)$item->getResolution() !== self::RMA_REFUND_RESOLUTION
                || (string)$item->getStatus() !== self::RMA_STATUS_APPROVED
            ) {
                continue;
            }

            /** @var \Magento\Rma\Model\Item $itemModel */
            $itemModel = $itemFactory->create()->load((int)$item->getEntityId());

            $qtyApproved = (float)$item->getQtyApproved();
            $qtyAplazoRefunded = (int)$itemModel->getData('qty_aplazo_refunded');
            $qtyToSend = $qtyApproved - $qtyAplazoRefunded;

            // Aplazo has already refunded all approved qty.
            if ($qtyToSend <= 0) {
                continue;
            }

            $orderItemId = (int)$item->getOrderItemId();
            $orderItem = $this->orderItemRepository->get($orderItemId);

            $unitPrice = (float)($useDisplayAmounts ? $orderItem->getPrice() : $orderItem->getBasePrice());
            $amount = $unitPrice * $qtyToSend;
            $amountCents = (int)round($amount * 100);

            $reason = (string)$item->getReason();
            $reasonHash = hash('sha256', $reason);

            $itemsFingerprint = [
                'rmaItemId' => (int)$item->getEntityId(),
                'orderItemId' => $orderItemId,
                'qtyToSend' => (float)$qtyToSend,
                'unitPriceCents' => (int)round($unitPrice * 100),
            ];

            $idempotencyKey = hash('sha256', json_encode([
                'type' => self::TYPE_RMA,
                'orderIncrementId' => $orderIncrementId,
                'rmaEntityId' => $rmaEntityId,
                'item' => $itemsFingerprint,
                'amountCents' => $amountCents,
                'reasonHash' => $reasonHash,
            ], JSON_UNESCAPED_SLASHES));

            $payload = [
                'cartId' => $orderIncrementId,
                'totalAmount' => $amount,
                'reason' => $reason,
            ];

            try {
                /** @var RefundRequest $refundRequest */
                $refundRequest = $this->refundRequestFactory->create();
                $refundRequest->setType(self::TYPE_RMA);
                $refundRequest->setStatus(RefundRequest::STATUS_PENDING);
                $refundRequest->setOrderIncrementId($orderIncrementId);
                $refundRequest->setOrderId(null);
                $refundRequest->setCreditmemoId(null);
                $refundRequest->setRmaEntityId($rmaEntityId);
                $refundRequest->setRmaItemId((int)$item->getEntityId());
                $refundRequest->setOrderItemId($orderItemId);
                $refundRequest->setQty((float)$qtyToSend);
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
            } catch (AlreadyExistsException $e) {
                // Already queued.
                continue;
            } catch (\Throwable $e) {
                // Do not block RMA save.
                $this->messageManager->addErrorMessage(
                    __('RMA saved, but the Aplazo refund could not be queued. Please check logs. %1', $e->getMessage())
                );
            }
        }
    }
}

