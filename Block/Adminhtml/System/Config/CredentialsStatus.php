<?php


namespace Aplazo\AplazoPayment\Block\Adminhtml\System\Config;


use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Backend\Block\Template\Context;
use Aplazo\AplazoPayment\Model\CredentialsValidator;

class CredentialsStatus extends \Magento\Config\Block\System\Config\Form\Field
{
    /**
     * @var CredentialsValidator
     */
    private $credentialsValidator;

    public function __construct(
        Context $context,
        CredentialsValidator $credentialsValidator,
        array $data = []
    )
    {
        parent::__construct($context, $data);
        $this->credentialsValidator = $credentialsValidator;
    }

    /**
     * @param AbstractElement $element
     *
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        switch ($this->credentialsValidator->validateCredentials()){
            case CredentialsValidator::USER_AUTHENTICATED:
                $status = 'success';
                $label = __('User authenticated.');
                break;
            case CredentialsValidator::INCOMPLETE_CREDENTIALS:
                $status = 'warning';
                $label = __('Credentials section is incomplete. Please, complete the section and try again.');
                break;
            default:
                $status = 'error';
                $label = __('User not authenticated.');
                break;
        }

        return sprintf('<div class="control-value"><span class="%s">%s</span></div>', $status, $label);
    }

    /**
     * @param AbstractElement $element
     * @param string $html
     *
     * @return string
     */
    protected function _decorateRowHtml(AbstractElement $element, $html)
    {
        return '<tr id="row_' . $element->getHtmlId() . '" class="row_payment_other_aplazo_validation_credentials">' . $html . '</tr>';
    }
}
