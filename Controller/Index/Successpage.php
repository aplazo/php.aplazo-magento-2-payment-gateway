<?php

namespace Aplazo\AplazoPayment\Controller\Index;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\View\Result\PageFactory;
use Psr\Log\LoggerInterface;

class Successpage extends Action  implements CsrfAwareActionInterface{

	/**
	 * @var LoggerInterface
	 */
	protected $_logger;

    /**
	 * @var PageFactory
	 */
    protected $_pageFactory;

	/**
	 * Successpage constructor.
	 * @param Context $context
	 * @param LoggerInterface $logger
     * @param PageFactory $pageFactory
	 */
	public function __construct(
		Context $context,
		LoggerInterface $logger,
        PageFactory $pageFactory
	) {
		$this->_logger = $logger;
        $this->_pageFactory = $pageFactory;
		parent::__construct($context);
	}

	public function execute() {
		return $this->_pageFactory->create();
	}




	/**
	 * @param RequestInterface $request
	 * @return InvalidRequestException|null
	 */
	public function createCsrfValidationException(RequestInterface $request): ? InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
