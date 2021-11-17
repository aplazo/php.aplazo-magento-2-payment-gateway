<?php
namespace Aplazo\AplazoPayment\Block;

use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\App\Request\Http;

class Successpage extends \Magento\Framework\View\Element\Template
{

	/**
	 * @var SessionManagerInterface
	 */
    protected $_coreSession;

	/**
     * @var Http
     */
    protected $http;

    /**
	 * Successpage constructor.
	 * @param SessionManagerInterface $coreSession
	 * @param Context $context
	 */
	public function __construct(
        Context $context,
		Http $http,
		SessionManagerInterface $coreSession
    ) {
		$this->_coreSession = $coreSession;
		$this->http = $http;
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
    	return $this->http->getParam('orderId');
	}

	/**
	 * @return LoanId
	 */
	public function getLoanId()
	{
		$this->_coreSession->start();
    	return $this->_coreSession->getLoanId();
	}

	public function unSetSessions(){
		$this->_coreSession->start();
		$this->_coreSession->unsLoanId();
		$this->_coreSession->unsIncrementId();
		return $this->_coreSession->unsOrderId();
	}
	
}