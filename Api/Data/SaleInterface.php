<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Api\Data;

interface SaleInterface extends \Magento\Framework\Api\ExtensibleDataInterface
{

    const SALE_ID = 'sale_id';
    const EMAIL = 'email';
    const MESSAGE = 'message';
    const STATUS = 'status';
    const LOAN_ID = 'loan_id';
    const LASTNAME = 'lastname';
    const UPDATED_AT = 'updated_at';
    const RESERVED_ORDER_ID = 'reserved_order_id';
    const QUOTE_ID = 'quote_id';
    const CREATED_AT = 'created_at';
    const FIRSTNAME = 'firstname';

    /**
     * Get sale_id
     * @return string|null
     */
    public function getSaleId();

    /**
     * Set sale_id
     * @param string $saleId
     * @return \Aplazo\AplazoPayment\Api\Data\SaleInterface
     */
    public function setSaleId($saleId);

    /**
     * Get quote_id
     * @return string|null
     */
    public function getQuoteId();

    /**
     * Set quote_id
     * @param string $quoteId
     * @return \Aplazo\AplazoPayment\Api\Data\SaleInterface
     */
    public function setQuoteId($quoteId);

    /**
     * Retrieve existing extension attributes object or create a new one.
     * @return \Aplazo\AplazoPayment\Api\Data\SaleExtensionInterface|null
     */
    public function getExtensionAttributes();

    /**
     * Set an extension attributes object.
     * @param \Aplazo\AplazoPayment\Api\Data\SaleExtensionInterface $extensionAttributes
     * @return $this
     */
    public function setExtensionAttributes(
        \Aplazo\AplazoPayment\Api\Data\SaleExtensionInterface $extensionAttributes
    );

    /**
     * Get reserved_order_id
     * @return string|null
     */
    public function getReservedOrderId();

    /**
     * Set reserved_order_id
     * @param string $reservedOrderId
     * @return \Aplazo\AplazoPayment\Api\Data\SaleInterface
     */
    public function setReservedOrderId($reservedOrderId);

    /**
     * Get firstname
     * @return string|null
     */
    public function getFirstname();

    /**
     * Set firstname
     * @param string $firstname
     * @return \Aplazo\AplazoPayment\Api\Data\SaleInterface
     */
    public function setFirstname($firstname);

    /**
     * Get lastname
     * @return string|null
     */
    public function getLastname();

    /**
     * Set lastname
     * @param string $lastname
     * @return \Aplazo\AplazoPayment\Api\Data\SaleInterface
     */
    public function setLastname($lastname);

    /**
     * Get email
     * @return string|null
     */
    public function getEmail();

    /**
     * Set email
     * @param string $email
     * @return \Aplazo\AplazoPayment\Api\Data\SaleInterface
     */
    public function setEmail($email);

    /**
     * Get status
     * @return string|null
     */
    public function getStatus();

    /**
     * Set status
     * @param string $status
     * @return \Aplazo\AplazoPayment\Api\Data\SaleInterface
     */
    public function setStatus($status);

    /**
     * Get loanId
     * @return string|null
     */
    public function getLoanId();

    /**
     * Set loanId
     * @param string $loanId
     * @return \Aplazo\AplazoPayment\Api\Data\SaleInterface
     */
    public function setLoanId($loanId);

    /**
     * Get message
     * @return string|null
     */
    public function getMessage();

    /**
     * Set message
     * @param string $message
     * @return \Aplazo\AplazoPayment\Api\Data\SaleInterface
     */
    public function setMessage($message);

    /**
     * Get created_at
     * @return string|null
     */
    public function getCreatedAt();

    /**
     * Set created_at
     * @param string $createdAt
     * @return \Aplazo\AplazoPayment\Api\Data\SaleInterface
     */
    public function setCreatedAt($createdAt);

    /**
     * Get updated_at
     * @return string|null
     */
    public function getUpdatedAt();

    /**
     * Set updated_at
     * @param string $updatedAt
     * @return \Aplazo\AplazoPayment\Api\Data\SaleInterface
     */
    public function setUpdatedAt($updatedAt);
}

