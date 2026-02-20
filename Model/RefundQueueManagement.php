<?php

namespace Aplazo\AplazoPayment\Model;

use Aplazo\AplazoPayment\Api\RefundQueueManagementInterface;
use Aplazo\AplazoPayment\Api\RefundRequestRepositoryInterface;
use Aplazo\AplazoPayment\Helper\Data;
use Aplazo\AplazoPayment\Model\ResourceModel\RefundRequest\CollectionFactory;
use Aplazo\AplazoPayment\Service\ApiService;
use Magento\Sales\Api\OrderRepositoryInterface;

class RefundQueueManagement implements RefundQueueManagementInterface
{
    private const MAX_RETRIES = 5;
    public function __construct(
        private CollectionFactory $collectionFactory,
        private RefundRequestRepositoryInterface $refundRequestRepository,
        private ApiService $apiService,
        private RefundLock $refundLock,
        private Data $data,
        private OrderRepositoryInterface $orderRepository
    ) {
    }

    public function process(int $batchSize = 20): int
    {
        $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', ['eq' => 'pending']);
        $collection->getSelect()->where('(next_attempt_at IS NULL OR next_attempt_at <= ?)', $nowUtc);
        $collection->setOrder('created_at', 'ASC');
        $collection->setPageSize(max(1, (int)$batchSize));
        $collection->setCurPage(1);

        $attempted = 0;
        foreach ($collection as $request) {
            $attempted++;
            $this->processOne($request);
        }

        return $attempted;
    }

    private function processOne(RefundRequest $request): void
    {
        // Lock first (prevents parallel processing in multi-cron environments).
        $lockKey = $request->getType() . '|' . $request->getIdempotencyKey();
        if (!$this->refundLock->acquire($lockKey, 0)) {
            // Lock contention is expected in multi-cron environments and should NOT consume retry budget.
            $this->data->log('Refund queue lock already acquired; skipping entity_id=' . $request->getId());
            return;
        }

        try {
            $request->setStatus(RefundRequest::STATUS_PROCESSING);
            $this->refundRequestRepository->save($request);

            $payload = json_decode($request->getPayloadJson(), true) ?: [];
            $response = $this->apiService->createRefund($payload, $request->getIdempotencyKey());
            $this->handleRefundResponse($request, $response);
        } catch (\Throwable $e) {
            $this->scheduleRetry($request, $e->getMessage(), $this->computeBackoffMinutes($request->getRetries()));
        } finally {
            $this->refundLock->release($lockKey);
        }
    }

    private function handleRefundResponse(RefundRequest $request, array $response): void
    {
        $refundId = $response['refundId'] ?? null;
        $refundStatus = $response['refundStatus'] ?? null;

        if (!$refundId) {
            $this->scheduleRetry($request, 'Bad service response.', 1, $response);
            return;
        }

        if ($refundStatus === 'REJECTED') {
            $request->setStatus(RefundRequest::STATUS_FAILED);
            $request->setLastError('Refund rejected by Aplazo');
            $request->setAplazoRefundId((string)$refundId);
            $request->setAplazoRefundStatus((string)$refundStatus);
            $request->setResponseJson(json_encode($response, JSON_UNESCAPED_SLASHES));
            $this->refundRequestRepository->save($request);

            $this->appendOrderHistory($request, __('Aplazo refund rejected. RefundId: %1', (string)$refundId));
            return;
        }

        if ($refundStatus !== 'REQUESTED') {
            $this->scheduleRetry($request, 'Unexpected refundStatus: ' . (string)$refundStatus, 5, $response);
            return;
        }

        $request->setStatus(RefundRequest::STATUS_SUCCESS);
        $request->setAplazoRefundId((string)$refundId);
        $request->setAplazoRefundStatus((string)$refundStatus);
        $request->setResponseJson(json_encode($response, JSON_UNESCAPED_SLASHES));
        $this->refundRequestRepository->save($request);

        if ($request->getType() === 'rma') {
            $this->applyRmaQtyRefunded($request);
        }

        $this->appendOrderHistory($request, __('Aplazo refund processed successfully. RefundId: %1', (string)$refundId));
    }

    private function scheduleRetry(
        RefundRequest $request,
        string $error,
        int $backoffMinutes,
        array $response = []
    ): void {
        if ($request->getRetries() >= self::MAX_RETRIES) {
            $request->setStatus(RefundRequest::STATUS_FAILED);
            $request->setLastError($error);
            $request->setResponseJson($response ? json_encode($response, JSON_UNESCAPED_SLASHES) : null);
            $this->refundRequestRepository->save($request);
            $this->appendOrderHistory(
                $request,
                __('Aplazo refund failed permanently after %1 retries. %2', (string)self::MAX_RETRIES, $error)
            );
            return;
        }

        $nextAttemptAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . max(1, (int)$backoffMinutes) . ' minutes')
            ->format('Y-m-d H:i:s');

        $request->setStatus(RefundRequest::STATUS_PENDING);
        $request->setRetries($request->getRetries() + 1);
        $request->setLastError($error);
        $request->setNextAttemptAt($nextAttemptAt);
        $request->setResponseJson($response ? json_encode($response, JSON_UNESCAPED_SLASHES) : null);
        $this->refundRequestRepository->save($request);

        $this->data->log('Refund queue retry scheduled for entity_id=' . $request->getId() . " in {$backoffMinutes}m. Error: $error");
    }

    private function computeBackoffMinutes(int $retries): int
    {
        return match (true) {
            $retries <= 0 => 1,
            $retries === 1 => 5,
            $retries === 2 => 15,
            $retries === 3 => 60,
            default => 180,
        };
    }

    private function appendOrderHistory(RefundRequest $request, \Magento\Framework\Phrase $message): void
    {
        $orderId = $request->getOrderId();
        if (!$orderId) {
            return;
        }

        try {
            $order = $this->orderRepository->get($orderId);
            if (!$order instanceof \Magento\Sales\Model\Order) {
                return;
            }
            $order->addCommentToStatusHistory((string)$message);
            $this->orderRepository->save($order);
        } catch (\Throwable $e) {
            $this->data->log('Unable to append order history: ' . $e->getMessage());
        }
    }

    private function applyRmaQtyRefunded(RefundRequest $request): void
    {
        if (!class_exists('Magento\\Rma\\Model\\ItemFactory')) {
            return;
        }

        $rmaItemId = $request->getRmaItemId();
        if (!$rmaItemId) {
            return;
        }

        $qty = $request->getQty();
        if (!$qty || $qty <= 0) {
            return;
        }
        $qtyInt = (int)round($qty);

        /** @var \Magento\Rma\Model\ItemFactory $itemFactory */
        $itemFactory = \Magento\Framework\App\ObjectManager::getInstance()->get('Magento\\Rma\\Model\\ItemFactory');
        /** @var \Magento\Rma\Model\Item $itemModel */
        $itemModel = $itemFactory->create()->load($rmaItemId);
        if (!$itemModel->getId()) {
            return;
        }

        $current = (int)$itemModel->getData('qty_aplazo_refunded');
        $itemModel->setData('qty_aplazo_refunded', $current + $qtyInt);
        $itemModel->save();
    }
}

