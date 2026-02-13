<?php

namespace Aplazo\AplazoPayment\Api\Data;

use Magento\Framework\Api\ExtensibleDataInterface;

interface RefundRequestInterface extends ExtensibleDataInterface
{
    public const ENTITY_ID = 'entity_id';
    public const TYPE = 'type';
    public const STATUS = 'status';
    public const ORDER_INCREMENT_ID = 'order_increment_id';
    public const ORDER_ID = 'order_id';
    public const CREDITMEMO_ID = 'creditmemo_id';
    public const RMA_ENTITY_ID = 'rma_entity_id';
    public const RMA_ITEM_ID = 'rma_item_id';
    public const ORDER_ITEM_ID = 'order_item_id';
    public const QTY = 'qty';
    public const AMOUNT_CENTS = 'amount_cents';
    public const CURRENCY = 'currency';
    public const REASON = 'reason';
    public const REASON_HASH = 'reason_hash';
    public const ITEMS_HASH = 'items_hash';
    public const IDEMPOTENCY_KEY = 'idempotency_key';
    public const PAYLOAD_JSON = 'payload_json';
    public const RESPONSE_JSON = 'response_json';
    public const APLAZO_REFUND_ID = 'aplazo_refund_id';
    public const APLAZO_REFUND_STATUS = 'aplazo_refund_status';
    public const RETRIES = 'retries';
    public const LAST_ERROR = 'last_error';
    public const NEXT_ATTEMPT_AT = 'next_attempt_at';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    /**
     * Identifier for this entity (maps to `entity_id`).
     */
    public function getId(): ?int;

    /**
     * Set identifier (maps to `entity_id`).
     *
     * Note: parameter is intentionally untyped to stay compatible with Magento model signatures.
     *
     * @param mixed $id
     */
    public function setId($id): self;

    public function getType(): string;
    public function setType(string $type): self;

    public function getStatus(): string;
    public function setStatus(string $status): self;

    public function getOrderIncrementId(): string;
    public function setOrderIncrementId(string $orderIncrementId): self;

    public function getOrderId(): ?int;
    public function setOrderId(?int $orderId): self;

    public function getCreditmemoId(): ?int;
    public function setCreditmemoId(?int $creditmemoId): self;

    public function getRmaEntityId(): ?int;
    public function setRmaEntityId(?int $rmaEntityId): self;

    public function getRmaItemId(): ?int;
    public function setRmaItemId(?int $rmaItemId): self;

    public function getOrderItemId(): ?int;
    public function setOrderItemId(?int $orderItemId): self;

    public function getQty(): ?float;
    public function setQty(?float $qty): self;

    public function getAmountCents(): int;
    public function setAmountCents(int $amountCents): self;

    public function getCurrency(): ?string;
    public function setCurrency(?string $currency): self;

    public function getReason(): ?string;
    public function setReason(?string $reason): self;

    public function getReasonHash(): ?string;
    public function setReasonHash(?string $reasonHash): self;

    public function getItemsHash(): ?string;
    public function setItemsHash(?string $itemsHash): self;

    public function getIdempotencyKey(): string;
    public function setIdempotencyKey(string $idempotencyKey): self;

    public function getPayloadJson(): string;
    public function setPayloadJson(string $payloadJson): self;

    public function getResponseJson(): ?string;
    public function setResponseJson(?string $responseJson): self;

    public function getAplazoRefundId(): ?string;
    public function setAplazoRefundId(?string $aplazoRefundId): self;

    public function getAplazoRefundStatus(): ?string;
    public function setAplazoRefundStatus(?string $aplazoRefundStatus): self;

    public function getRetries(): int;
    public function setRetries(int $retries): self;

    public function getLastError(): ?string;
    public function setLastError(?string $lastError): self;

    public function getNextAttemptAt(): ?string;
    public function setNextAttemptAt(?string $nextAttemptAt): self;

    public function getCreatedAt(): ?string;
    public function getUpdatedAt(): ?string;
}

