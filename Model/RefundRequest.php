<?php

namespace Aplazo\AplazoPayment\Model;

use Aplazo\AplazoPayment\Api\Data\RefundRequestInterface;
use Magento\Framework\Model\AbstractExtensibleModel;

class RefundRequest extends AbstractExtensibleModel implements RefundRequestInterface
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected function _construct()
    {
        $this->_init(\Aplazo\AplazoPayment\Model\ResourceModel\RefundRequest::class);
    }

    public function getId(): ?int
    {
        $value = parent::getId();
        return $value === null ? null : (int)$value;
    }

    public function setId($id): RefundRequestInterface
    {
        parent::setId($id);
        return $this;
    }

    public function getType(): string
    {
        return (string)$this->getData(self::TYPE);
    }

    public function setType(string $type): RefundRequestInterface
    {
        return $this->setData(self::TYPE, $type);
    }

    public function getStatus(): string
    {
        return (string)$this->getData(self::STATUS);
    }

    public function setStatus(string $status): RefundRequestInterface
    {
        return $this->setData(self::STATUS, $status);
    }

    public function getOrderIncrementId(): string
    {
        return (string)$this->getData(self::ORDER_INCREMENT_ID);
    }

    public function setOrderIncrementId(string $orderIncrementId): RefundRequestInterface
    {
        return $this->setData(self::ORDER_INCREMENT_ID, $orderIncrementId);
    }

    public function getOrderId(): ?int
    {
        $value = $this->getData(self::ORDER_ID);
        return $value === null ? null : (int)$value;
    }

    public function setOrderId(?int $orderId): RefundRequestInterface
    {
        return $this->setData(self::ORDER_ID, $orderId);
    }

    public function getCreditmemoId(): ?int
    {
        $value = $this->getData(self::CREDITMEMO_ID);
        return $value === null ? null : (int)$value;
    }

    public function setCreditmemoId(?int $creditmemoId): RefundRequestInterface
    {
        return $this->setData(self::CREDITMEMO_ID, $creditmemoId);
    }

    public function getRmaEntityId(): ?int
    {
        $value = $this->getData(self::RMA_ENTITY_ID);
        return $value === null ? null : (int)$value;
    }

    public function setRmaEntityId(?int $rmaEntityId): RefundRequestInterface
    {
        return $this->setData(self::RMA_ENTITY_ID, $rmaEntityId);
    }

    public function getRmaItemId(): ?int
    {
        $value = $this->getData(self::RMA_ITEM_ID);
        return $value === null ? null : (int)$value;
    }

    public function setRmaItemId(?int $rmaItemId): RefundRequestInterface
    {
        return $this->setData(self::RMA_ITEM_ID, $rmaItemId);
    }

    public function getOrderItemId(): ?int
    {
        $value = $this->getData(self::ORDER_ITEM_ID);
        return $value === null ? null : (int)$value;
    }

    public function setOrderItemId(?int $orderItemId): RefundRequestInterface
    {
        return $this->setData(self::ORDER_ITEM_ID, $orderItemId);
    }

    public function getQty(): ?float
    {
        $value = $this->getData(self::QTY);
        return $value === null ? null : (float)$value;
    }

    public function setQty(?float $qty): RefundRequestInterface
    {
        return $this->setData(self::QTY, $qty);
    }

    public function getAmountCents(): int
    {
        return (int)$this->getData(self::AMOUNT_CENTS);
    }

    public function setAmountCents(int $amountCents): RefundRequestInterface
    {
        return $this->setData(self::AMOUNT_CENTS, $amountCents);
    }

    public function getCurrency(): ?string
    {
        $value = $this->getData(self::CURRENCY);
        return $value === null ? null : (string)$value;
    }

    public function setCurrency(?string $currency): RefundRequestInterface
    {
        return $this->setData(self::CURRENCY, $currency);
    }

    public function getReason(): ?string
    {
        $value = $this->getData(self::REASON);
        return $value === null ? null : (string)$value;
    }

    public function setReason(?string $reason): RefundRequestInterface
    {
        return $this->setData(self::REASON, $reason);
    }

    public function getReasonHash(): ?string
    {
        $value = $this->getData(self::REASON_HASH);
        return $value === null ? null : (string)$value;
    }

    public function setReasonHash(?string $reasonHash): RefundRequestInterface
    {
        return $this->setData(self::REASON_HASH, $reasonHash);
    }

    public function getItemsHash(): ?string
    {
        $value = $this->getData(self::ITEMS_HASH);
        return $value === null ? null : (string)$value;
    }

    public function setItemsHash(?string $itemsHash): RefundRequestInterface
    {
        return $this->setData(self::ITEMS_HASH, $itemsHash);
    }

    public function getIdempotencyKey(): string
    {
        return (string)$this->getData(self::IDEMPOTENCY_KEY);
    }

    public function setIdempotencyKey(string $idempotencyKey): RefundRequestInterface
    {
        return $this->setData(self::IDEMPOTENCY_KEY, $idempotencyKey);
    }

    public function getPayloadJson(): string
    {
        return (string)$this->getData(self::PAYLOAD_JSON);
    }

    public function setPayloadJson(string $payloadJson): RefundRequestInterface
    {
        return $this->setData(self::PAYLOAD_JSON, $payloadJson);
    }

    public function getResponseJson(): ?string
    {
        $value = $this->getData(self::RESPONSE_JSON);
        return $value === null ? null : (string)$value;
    }

    public function setResponseJson(?string $responseJson): RefundRequestInterface
    {
        return $this->setData(self::RESPONSE_JSON, $responseJson);
    }

    public function getAplazoRefundId(): ?string
    {
        $value = $this->getData(self::APLAZO_REFUND_ID);
        return $value === null ? null : (string)$value;
    }

    public function setAplazoRefundId(?string $aplazoRefundId): RefundRequestInterface
    {
        return $this->setData(self::APLAZO_REFUND_ID, $aplazoRefundId);
    }

    public function getAplazoRefundStatus(): ?string
    {
        $value = $this->getData(self::APLAZO_REFUND_STATUS);
        return $value === null ? null : (string)$value;
    }

    public function setAplazoRefundStatus(?string $aplazoRefundStatus): RefundRequestInterface
    {
        return $this->setData(self::APLAZO_REFUND_STATUS, $aplazoRefundStatus);
    }

    public function getRetries(): int
    {
        $value = $this->getData(self::RETRIES);
        return $value === null ? 0 : (int)$value;
    }

    public function setRetries(int $retries): RefundRequestInterface
    {
        return $this->setData(self::RETRIES, $retries);
    }

    public function getLastError(): ?string
    {
        $value = $this->getData(self::LAST_ERROR);
        return $value === null ? null : (string)$value;
    }

    public function setLastError(?string $lastError): RefundRequestInterface
    {
        return $this->setData(self::LAST_ERROR, $lastError);
    }

    public function getNextAttemptAt(): ?string
    {
        $value = $this->getData(self::NEXT_ATTEMPT_AT);
        return $value === null ? null : (string)$value;
    }

    public function setNextAttemptAt(?string $nextAttemptAt): RefundRequestInterface
    {
        return $this->setData(self::NEXT_ATTEMPT_AT, $nextAttemptAt);
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);
        return $value === null ? null : (string)$value;
    }

    public function getUpdatedAt(): ?string
    {
        $value = $this->getData(self::UPDATED_AT);
        return $value === null ? null : (string)$value;
    }
}

