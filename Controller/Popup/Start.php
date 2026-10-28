<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Controller\Popup;

use Aplazo\AplazoPayment\Model\PaymentFirst\StartService;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * POST aplazo/popup/start (BNPL-1664): starts a payment-first attempt for the session's quote. A POST action with a
 * required form key (FormKeyRequired). Nothing about the quote, amount or customer is read from the request.
 */
class Start implements HttpPostActionInterface, CsrfAwareActionInterface
{
    use FormKeyRequired;

    public function __construct(
        private JsonFactory $jsonFactory,
        private FormKeyValidator $formKeyValidator,
        private StartService $startService
    ) {
    }

    public function execute()
    {
        return $this->jsonFactory->create()->setData($this->startService->start());
    }
}
