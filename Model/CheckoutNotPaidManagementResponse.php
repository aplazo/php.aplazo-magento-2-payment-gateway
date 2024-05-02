<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model;

use Aplazo\AplazoPayment\Api\CheckoutNotPaidManagementResponseInterface;
use Magento\Framework\DataObject;

class CheckoutNotPaidManagementResponse extends DataObject implements CheckoutNotPaidManagementResponseInterface
{

    public function getMessage()
    {
        return $this->_getData(self::DATA_MESSAGE);
    }

    public function getQuoteId()
    {
        return $this->_getData(self::DATA_QUOTE_ID);
    }

    public function getMessageError()
    {
        return $this->_getData(self::DATA_MESSAGE_ERROR);
    }

    public function setMessage($message)
    {
        return $this->setData(self::DATA_MESSAGE, $message);
    }

    public function setQuoteId($quoteId)
    {
        return $this->setData(self::DATA_QUOTE_ID, $quoteId);
    }

    public function setMessageError($messageError)
    {
        return $this->setData(self::DATA_MESSAGE_ERROR, $messageError);
    }
}
