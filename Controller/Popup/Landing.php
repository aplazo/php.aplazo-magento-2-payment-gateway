<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Controller\Popup;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Escaper;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

/**
 * GET aplazo/popup/landing (BNPL-1664): successUrl, errorUrl and cartUrl of a payment-first loan. Aplazo's window ends
 * here and closes itself; the checkout page then asks the server (complete). It reads nothing and changes nothing.
 */
class Landing implements HttpGetActionInterface
{
    public function __construct(
        private RawFactory $rawFactory,
        private Escaper $escaper,
        private SecureHtmlRenderer $secureRenderer
    ) {
    }

    public function execute()
    {
        return $this->rawFactory->create()
            ->setHeader('Content-Type', 'text/html; charset=utf-8', true)
            ->setContents(
                '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
                . '<title>Aplazo</title>'
                . $this->secureRenderer->renderTag('style', [], 'body{font-family:sans-serif;padding:24px}', false)
                . '</head><body>'
                . '<p>' . $this->escaper->escapeHtml((string)__('You can close this window and return to the store.')) . '</p>'
                . '<button type="button" id="aplazo-popup-close">'
                . $this->escaper->escapeHtml((string)__('Close this window')) . '</button>'
                . $this->secureRenderer->renderEventListenerAsTag('onclick', 'window.close()', '#aplazo-popup-close')
                . $this->secureRenderer->renderTag('script', [], 'window.close();', false)
                . '</body></html>'
            );
    }
}
