<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Aplazo\AplazoPayment\Model\Config\Source\CheckoutMode;
use Laminas\Uri\Http as HttpUri;

/**
 * The checkout URL returned by createLoan is opened in the popup only when it is on the configured checkout origin
 * (ported from BNPL-1664's PopupCheckout, commit d3d72ef).
 */
class CheckoutUrlValidator
{
    /**
     * @return string|null the URL with mode=popup, or null when it must not be opened
     */
    public function popupUrl(string $url, ?string $origin): ?string
    {
        if ($origin === null || !$this->isOnOrigin($url, $origin)) {
            return null;
        }
        try {
            return $this->withPopupMode($url);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Strict origin check: absolute https URL, no user info, same host (case-insensitive, no suffix or subdomain
     * match) and same effective port as the configured origin. Whitespace, control characters and backslashes are
     * rejected before parsing, because browsers and parsers can read them differently.
     */
    public function isOnOrigin(string $url, string $origin): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }
        try {
            $uri = new HttpUri($url);
            $expected = new HttpUri($origin);
        } catch (\Throwable $e) {
            return false;
        }
        if (!$uri->isValid() || !$uri->isAbsolute()
            || strtolower((string)$uri->getScheme()) !== 'https' || strtolower((string)$expected->getScheme()) !== 'https'
            || (string)$uri->getUserInfo() !== ''
        ) {
            return false;
        }
        $host = strtolower((string)$uri->getHost());
        if ($host === '' || $host !== strtolower((string)$expected->getHost())) {
            return false;
        }

        return ((int)$uri->getPort() ?: 443) === ((int)$expected->getPort() ?: 443);
    }

    /**
     * Sets mode=popup on the checkout URL (provisional contract, BNPL-1666). The rest of the query is kept exactly as
     * encoded, and so is the fragment.
     */
    public function withPopupMode(string $url): string
    {
        $uri = new HttpUri($url);
        $pairs = array_filter(explode('&', (string)$uri->getQuery()), function ($pair) {
            return $pair !== '' && urldecode(explode('=', $pair, 2)[0]) !== 'mode';
        });
        $pairs[] = 'mode=' . CheckoutMode::MODE_POPUP;
        $uri->setQuery(implode('&', $pairs));

        return $uri->toString();
    }
}
