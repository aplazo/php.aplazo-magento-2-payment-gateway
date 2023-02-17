<?php

namespace Aplazo\AplazoPayment\Model\Ui;

use Aplazo\AplazoPayment\Controller\Order\Operations;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Asset\Repository;
use Aplazo\AplazoPayment\Helper\Data;
use Aplazo\AplazoPayment\Model\CredentialsValidator;

class ConfigProvider implements ConfigProviderInterface
{
    const CODE = 'aplazo_gateway';

    /**
     * @var Context
     */
    private $_context;

    /**
     * @var Repository
     */
    protected $_assetRepo;

    /**
     * @var Data
     */
    protected $aplazoHelper;

    /**
     * @var CredentialsValidator
     */
    protected $credentialsValidator;

    public function __construct(
        Context $context,
        Repository $assetRepo,
        Data $aplazoHelper,
        CredentialsValidator $credentialsValidator
    )
    {
        $this->_context = $context;
        $this->_assetRepo = $assetRepo;
        $this->aplazoHelper = $aplazoHelper;
        $this->credentialsValidator = $credentialsValidator;
    }

    /**
     * @return array
     */
    public function getConfig()
    {
        return [
            'payment' => [
                self::CODE => [
                    'active' => $this->aplazoHelper->isActive() && $this->credentialsValidator->areCredentialsValid(),
                    'title' => 'Compra ahora y paga en 5 quincenas',
                    'banner' => $this->_assetRepo->getUrl("Aplazo_AplazoPayment::images/aplazo-logo.png"),
                    'actionUrl' => $this->_context->getUrl()->getUrl(Operations::ACTION_URL),
                ]
            ]
        ];
    }
}
