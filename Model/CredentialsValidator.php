<?php

namespace Aplazo\AplazoPayment\Model;

use Aplazo\AplazoPayment\Service\ApiService as AplazoService;
use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;

class CredentialsValidator
{
    const USER_AUTHENTICATED = 1;
    const INCOMPLETE_CREDENTIALS = 0;
    const USER_NOT_AUTHENTICATED = -1;
    const CALLBACK_NOT_EQUALS = 2;

    /**
     * @var AplazoHelper
     */
    private $aplazoHelper;
    /**
     * @var AplazoService
     */
    private $aplazoService;

    public function __construct(
        AplazoHelper  $aplazoHelper,
        AplazoService $aplazoService
    )
    {
        $this->aplazoHelper = $aplazoHelper;
        $this->aplazoService = $aplazoService;
    }

    /**
     * @return int
     */
    public function validateCredentials(): int
    {
        $result = self::USER_NOT_AUTHENTICATED;
        if($this->aplazoHelper->getMerchantId() != '' && $this->aplazoHelper->getApiToken() != ''){
            try {
                if($token = $this->aplazoService->getAuthorizationToken()){
                    if($token) {
                        $result = self::USER_AUTHENTICATED;
                    }
                }
            }catch (\Magento\Framework\Exception\LocalizedException $localizedException){
            }
        }
        else{
            $result = self::INCOMPLETE_CREDENTIALS;
        }
        return $result;
    }

    public function areCredentialsValid(): bool
    {
        return $this->validateCredentials() == self::USER_AUTHENTICATED && $this->aplazoHelper->getCurrentCurrencyCode() === 'MXN';
    }
}
