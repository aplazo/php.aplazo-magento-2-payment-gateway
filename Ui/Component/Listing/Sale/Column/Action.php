<?php

namespace Aplazo\AplazoPayment\Ui\Component\Listing\Sale\Column;

use Aplazo\AplazoPayment\Model\Data\Sale;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\UrlInterface;

class Action extends Column
{
    /** Url path */
    const ROW_RECREATE_URL = 'aplazo_aplazopayment/sale/recreate';
    const ROW_CANCEL_URL = 'aplazo_aplazopayment/sale/refund';
    /** @var UrlInterface */
    protected $_urlBuilder;

    /**
     * @param ContextInterface   $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface       $urlBuilder
     * @param array              $components
     * @param array              $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        $this->_urlBuilder = $urlBuilder;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Prepare Data Source.
     *
     * @param array $dataSource
     *
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$item) {
                if (isset($item['sale_id'])) {
                    if ($item['status'] === Sale::STATUS_ERROR || $item['status'] === Sale::STATUS_PENDING) {
                        $item[$this->getData('name')]['cancel'] = [
                            'href' => $this->_urlBuilder->getUrl(
                                self::ROW_CANCEL_URL,
                                ['id' => $item['sale_id']]
                            ),
                            'label' => __('Cancel or refund if applicable'),
                        ];
                    }
                    if ($item['status'] === Sale::STATUS_ERROR) {
                        $item[$this->getData('name')]['retry'] = [
                            'href' => $this->_urlBuilder->getUrl(
                                self::ROW_RECREATE_URL,
                                ['id' => $item['sale_id']]
                            ),
                            'label' => __('Retry order submit'),
                        ];
                    }
                }
            }
        }

        return $dataSource;
    }
}
