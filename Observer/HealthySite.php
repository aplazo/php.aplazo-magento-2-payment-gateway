<?php

namespace Aplazo\AplazoPayment\Observer;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Aplazo\AplazoPayment\Service\ApiService as AplazoService;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\LocalizedException;

class HealthySite implements ObserverInterface
{
    const TEST_LOG = 'log';
    const TEST_AUTH = 'auth';
    const TEST_LOAN = 'loan';
    const TEST_LOAN_STATUS = 'loanStatus';
    const TEST_CANCEL = 'cancel';
    const TEST_INCREMENT_ID = '10000000001';
    /**
     * @var Logger
     */
    protected $logger;
    private $aplazoService;
    private $aplazoHelper;
    private $authAplazoBearerToken;
    private $authAplazoBearerToken2;
    private $configWriter;
    private $tests = [
        self::TEST_LOG,
        self::TEST_AUTH,
        self::TEST_LOAN,
        self::TEST_LOAN_STATUS,
        self::TEST_CANCEL
    ];

    public function __construct(
        AplazoService   $aplazoService,
        AplazoHelper    $aplazoHelper,
        WriterInterface $configWriter
    )
    {
        $this->aplazoService = $aplazoService;
        $this->aplazoHelper = $aplazoHelper;
        $this->configWriter = $configWriter;
    }

    public function execute(Observer $observer)
    {
        if ($this->aplazoHelper->isHealthyCheck()) {
            $finalLog = '';
            foreach ($this->tests as $test) {
                switch ($test) {
                    case self::TEST_LOG:
                        $isLoggedCorrectly = $this->logInBoth('*** Comenzando Healthy Test ***');
                        $finalLog .= "1. Test de logs:\n";
                        if ($isLoggedCorrectly->getStatus() == 202) {
                            $finalLog .= "Logs llegan correctamente a Aplazo\n";
                        } else {
                            $finalLog .= "Logs no llegan a Aplazo. Status: " . $isLoggedCorrectly->getStatus() ."\n";
                        }
//                        $isLoggedCorrectly2 = $this->logInBoth('*** Comenzando Healthy Test Second Chance ***', true);
//                        if ($isLoggedCorrectly2->getStatus() == 202) {
//                            $finalLog .= "Logs llegan correctamente a Aplazo con PHP Curl \n";
//                        } else {
//                            $finalLog .= "Logs no llegan a Aplazo con PHP Curl. Status: " . $isLoggedCorrectly2->getStatus() ."\n";
//                        }
                        break;
                    case self::TEST_AUTH:
                        $finalLog .= "\n2. Test de auth:\n";
                        try {
                            $this->authAplazoBearerToken = $this->aplazoService->getAuthorizationToken();
                            if (!empty($this->authAplazoBearerToken)) {
                                $finalLog .= "Auth correcto con token: $this->authAplazoBearerToken \n";
                            } else {
                                $finalLog .= "Sin token\n";
                            }
                        } catch (\Exception $e) {
                            $finalLog .= "Error obteniendo el auth: " . $e->getMessage() . "\n";
                        }
//                        try {
//                            $this->authAplazoBearerToken2 = $this->aplazoService->getAuthorizationToken(true);
//                            if (!empty($this->authAplazoBearerToken2)) {
//                                $finalLog .= "Auth PHP Curl correcto con token: $this->authAplazoBearerToken \n";
//                            } else {
//                                $finalLog .= "Sin token con php curl \n";
//                            }
//                        } catch (\Exception $e) {
//                            $finalLog .= "Error obteniendo el auth con php curl: " . $e->getMessage() . "\n";
//                        }
                        break;
                    case self::TEST_LOAN:
                        $finalLog .= "\n3. Test de loan:\n";
                        try {
                            $response = $this->aplazoService->createLoan($this->getLoanDummyData(), $this->authAplazoBearerToken);
                            if (is_array($response)) {
                                $finalLog .= "Loan Exitoso " . json_encode($response) . "\n";
                            } else {
                                $finalLog .= "Error al crear el loan \n";
                            }
                        } catch (\Exception $e) {
                            $finalLog .=  "Error creando el loan: " . $e->getMessage() . "\n";
                        }
//                        try {
//                            $response = $this->aplazoService->createLoan($this->getLoanDummyData("-2"), $this->authAplazoBearerToken2, true);
//                            if (is_array($response)) {
//                                $finalLog .= "Loan Con PHP Curl Exitoso " . json_encode($response) . "\n";
//                            } else {
//                                $finalLog .= "Error al crear el loan con PHP Curl \n";
//                            }
//                        } catch (\Exception $e) {
//                            $finalLog .=  "Error creando el loan con PHP Curl: " . $e->getMessage() . "\n";
//                        }
                        break;
                    case self::TEST_LOAN_STATUS:
                        $finalLog .= "\n4. Test de loan status:\n";
                        $response = $this->aplazoService->getLoanStatus(self::TEST_INCREMENT_ID);
                        if(is_array($response)){
                            foreach ($response as $loan) {
                                $finalLog .= "Loan obtenido correctamente con status " . $loan['status'] . "y con loanId " . $loan['loanId'] . "\n";
                                break;
                            }
                        } else {
                            $finalLog .= "Error al obtener loan status\n";
                        }

//                        $response2 = $this->aplazoService->getLoanStatus(self::TEST_INCREMENT_ID, true);
//                        if(is_array($response2)){
//                            foreach ($response2 as $loan) {
//                                $finalLog .= "Loan con PHP Curl obtenido correctamente con status " . $loan['status'] . "y con loanId " . $loan['loanId'] . "\n";
//                                break;
//                            }
//                        } else {
//                            $finalLog .= "Error al obtener loan status con PHP Curl\n";
//                        }
                        break;
                    case self::TEST_CANCEL:
                        $finalLog .= "\n5. Test de cancelación:\n";
                        try{
                            $response = $this->aplazoService->cancelLoan([
                                "cartId" => self::TEST_INCREMENT_ID,
                                "totalAmount" => 0,
                                "reason" => 'From Magento Healthy test'
                            ]);
                            if(!empty($response)){
                                $finalLog .=  "Orden cancelada correctamente con status ".$response['status'] . "\n";
                            } else {
                                $finalLog .=  "Error al cancelar\n";
                            }
                        } catch (\Exception $e) {
                            $finalLog .=  "Error: " . $e->getMessage()  . "\n";
                        }
//                        try{
//                            $response = $this->aplazoService->cancelLoan([
//                                "cartId" => self::TEST_INCREMENT_ID."-2",
//                                "totalAmount" => 0,
//                                "reason" => 'From Magento PHP Curl Healthy test',
//
//                            ]);
//                            if(!empty($response)){
//                                $finalLog .=  "Orden cancelada con PHP Curl correctamente con status ".$response['status'] . "\n";
//                            } else {
//                                $finalLog .=  "Error al cancelar con PHP Curl\n";
//                            }
//                        } catch (\Exception $e) {
//                            $finalLog .=  "Error con PHP Curl: " . $e->getMessage()  . "\n";
//                        }
                        break;
                }
            }

            $this->logInBoth($finalLog . "\n\n *** Proceso Health finalizado ***");
            $this->configWriter->save('payment/aplazo_gateway/check_healthy_site', 0);
        }
    }

    private function logInBoth($message, $secondChance = false)
    {
        $this->aplazoHelper->log($message);
        return $this->aplazoService->sendLog($message, AplazoHelper::LOGS_CATEGORY_INFO, AplazoHelper::LOGS_SUBCATEGORY_HEALTH_CHECK, [], $secondChance);
    }

    private function getLoanDummyData($secondLoan = null)
    {
        return [
            "buyer" => [
                "addressLine" => "Calle prueba",
                "email" => "test@aplazo.mx",
                "firstName" => "John",
                "lastName" => "Doe",
                "phone" => "5512345678",
                "postalCode" => "06100"
            ],
            "cartId" => self::TEST_INCREMENT_ID ."$secondLoan",
            "cartUrl" => "https://magento.test/aplazo/order/operations/operation/cancel/incrementid/".self::TEST_INCREMENT_ID."$secondLoan"."/token/Yl22ESxcwBKByoHY/",
            "discount" => [
                "price" => 0,
                "title" => null
            ],
            "errorUrl" => "https://magento.test/aplazo/order/operations/operation/redirect_to_onepage/onepage/failure/orderid/".self::TEST_INCREMENT_ID."$secondLoan"."/",
            "products" => [
                [
                    "count" => 1,
                    "description" => null,
                    "id" => "123",
                    "imageUrl" => "",
                    "price" => 473.28,
                    "title" => "Producto test"
                ]
            ],
            "shipping" => [
                "price" => 0,
                "title" => "Envio test"
            ],
            "shopId" => self::TEST_INCREMENT_ID,
            "successUrl" => "https://magento.test/aplazo/order/operations/operation/redirect_to_onepage/onepage/success/orderid/".self::TEST_INCREMENT_ID."$secondLoan"."/",
            "taxes" => [
                "price" => 75.72,
                "title" => "Impuesto"
            ],
            "extOrderId" => "1",
            "totalPrice" => 549,
            "webHookUrl" => "https://magento.test/rest/V1/aplazo/callback"
        ];
    }
}
