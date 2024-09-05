<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Api;

interface CheckoutNotPaidManagementResponseInterface
{
    const DATA_MESSAGE = 'message';
    const DATA_QUOTE_ID = 'quote_id';
    const DATA_MESSAGE_ERROR = 'message_error';

    /**
     * @return string
     */
    public function getMessage();

    /**
     * @return int
     */
    public function getQuoteId();

    /**
     * @return string
     */
    public function getMessageError();

    /**
     * @param string $message
     * @return $this
     */
    public function setMessage($message);

    /**
     * @param int $quoteId
     * @return $this
     */
    public function setQuoteId($quoteId);

    /**
     * @param string $messageError
     * @return $this
     */
    public function setMessageError($messageError);
}
