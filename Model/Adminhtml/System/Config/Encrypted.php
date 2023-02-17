<?php


namespace Aplazo\AplazoPayment\Model\Adminhtml\System\Config;

use Magento\Framework\App\Cache\Type\Config as Cache;
use Aplazo\AplazoPayment\Service\ApiService;

class Encrypted extends \Magento\Config\Model\Config\Backend\Encrypted
{
    /**
     * @var Cache
     */
    private $cache;

    public function __construct(
        Cache $cache,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry,$config, $cacheTypeList, $encryptor, $resource, $resourceCollection, $data);
        $this->cache = $cache;
    }


    public function beforeSave()
    {
        if($this->hasDataChanges()){
            if($this->cache->test(ApiService::TOKEN_CACHE_KEY)){
                $this->cache->remove(ApiService::TOKEN_CACHE_KEY);
            }
        }
        parent::beforeSave();
        return $this;
    }


}
