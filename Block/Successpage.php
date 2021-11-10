<?php
namespace Aplazo\AplazoPayment\Block;

use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\Session\SessionManagerInterface;

class Successpage extends \Magento\Framework\View\Element\Template
{

	/**
	 * @var SessionManagerInterface
	 */
    protected $_coreSession;

    /**
	 * Successpage constructor.
	 * @param SessionManagerInterface $coreSession
	 * @param Context $context
	 */
	public function __construct(
        Context $context,
		SessionManagerInterface $coreSession
    ) {
		$this->_coreSession = $coreSession;
		parent::__construct($context);
	}

	/**
	 * @return OrderId
	 */
	public function getOrderId()
	{
		$this->_coreSession->start();
    	return $this->_coreSession->getOrderId();
	}

	/**
	 * @return IncrementId
	 */
	public function getIncrementId()
	{
		$this->_coreSession->start();
    	return $this->_coreSession->getIncrementId();
	}

	/**
	 * @return LoanId
	 */
	public function getLoanId()
	{
		$this->_coreSession->start();
    	return $this->_coreSession->getLoanId();
	}
}