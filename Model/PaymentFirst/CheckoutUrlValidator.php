<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Aplazo\AplazoPayment\Model\Config\Source\CheckoutMode;

/**
 * The checkout URL returned by createLoan is opened in the popup only when it is on the configured checkout origin
 * (ported from BNPL-1664's PopupCheckout, commit d3d72ef). Plain parse_url(), no URI library dependency.
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

        return $this->withPopupMode($url);
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
        $uri = $this->parse($url);
        $expected = $this->parse($origin);
        if ($uri === null || $expected === null
            || isset($uri['user']) || isset($uri['pass'])
            || strpos(substr($url, 0, strcspn($url, '/?#', 8) + 8), '@') !== false
        ) {
            return false;
        }

        return $uri['host'] === $expected['host'] && $uri['port'] === $expected['port'];
    }

    /**
     * Sets mode=popup on the checkout URL (provisional contract, BNPL-1666). The rest of the query is kept exactly as
     * encoded, and so is the fragment.
     */
    public function withPopupMode(string $url): string
    {
        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }
        $query = '';
        if (($mark = strpos($url, '?')) !== false) {
            $query = substr($url, $mark + 1);
            $url = substr($url, 0, $mark);
        }
        $pairs = array_filter(explode('&', $query), function ($pair) {
            return $pair !== '' && urldecode(explode('=', $pair, 2)[0]) !== 'mode';
        });
        $pairs[] = 'mode=' . CheckoutMode::MODE_POPUP;

        return $url . '?' . implode('&', $pairs) . $fragment;
    }

    /**
     * @return array{host: string, port: int}|null an absolute https URL with a host; the port defaults to 443
     */
    private function parse(string $url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || (string)($parts['host'] ?? '') === '' || strpos($url, '//') !== strlen((string)$parts['scheme']) + 1
        ) {
            return null;
        }
        if (isset($parts['port']) && (int)$parts['port'] === 0) {
            return null;
        }
        $parts['host'] = strtolower((string)$parts['host']);
        $parts['port'] = (int)($parts['port'] ?? 443);

        return $parts;
    }
}
