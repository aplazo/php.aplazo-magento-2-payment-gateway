<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Controller\Popup;

use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;

/**
 * The popup endpoints require a valid form key on every POST (BNPL-1664). Magento's default frontend CSRF check
 * skips requests sent with X-Requested-With (CsrfValidator::validateRequest); these endpoints do not rely on that.
 * Needs $formKeyValidator and $jsonFactory on the controller.
 */
trait FormKeyRequired
{
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $this->formKeyValidator->validate($request);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        $result = $this->jsonFactory->create()->setHttpResponseCode(403)->setData([
            'status' => 'invalid_form_key',
            'message' => (string)__('Your session has expired. Please reload the page.'),
        ]);

        return new InvalidRequestException($result);
    }
}
