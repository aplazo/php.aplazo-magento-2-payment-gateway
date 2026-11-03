<?php

namespace Aplazo\AplazoPayment\Service;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Magento\Framework\App\CacheInterface;

/**
 * Cache of the /api/auth token, shared by ApiService (writes) and LogService (reads only).
 * Lives in its own class so LogService does not depend on ApiService (which already depends on LogService).
 */
class TokenCache
{
    const CACHE_KEY = 'aplazo_api_token';
    const CACHE_TAG = 'APLAZO_API_TOKEN';
    /** Stop using the token this many seconds before its exp. */
    const EXPIRY_MARGIN_SECONDS = 600;

    public function __construct(
        private CacheInterface $cache,
        private AplazoHelper $aplazoHelper
    ) {
    }

    /**
     * @return string|null Token as returned by /api/auth ("Bearer <jwt>"), or null when there is none still valid
     *                     for the current service URL and credentials.
     */
    public function get(): ?string
    {
        try {
            $raw = $this->cache->load(self::CACHE_KEY);
            if (!$raw) {
                return null;
            }
            $entry = json_decode($raw, true);
            if (!is_array($entry) || empty($entry['token']) || !isset($entry['expires_at'], $entry['fingerprint'])) {
                return null;
            }
            if ($entry['fingerprint'] !== $this->fingerprint() || (int)$entry['expires_at'] <= time()) {
                return null;
            }
            return (string)$entry['token'];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Stores the token until its exp minus the margin. Tokens without a readable exp are not cached.
     */
    public function save(string $token): void
    {
        try {
            $exp = $this->readExp($token);
            if ($exp === null) {
                return;
            }
            $expiresAt = $exp - self::EXPIRY_MARGIN_SECONDS;
            $lifetime = $expiresAt - time();
            if ($lifetime <= 0) {
                return;
            }
            $this->cache->save(
                json_encode(['token' => $token, 'expires_at' => $expiresAt, 'fingerprint' => $this->fingerprint()]),
                self::CACHE_KEY,
                [self::CACHE_TAG],
                $lifetime
            );
        } catch (\Throwable $e) {
            // A cache failure only means the next call authenticates again
        }
    }

    public function clean(): void
    {
        try {
            $this->cache->remove(self::CACHE_KEY);
        } catch (\Throwable $e) {
        }
    }

    /**
     * Ties the cached token to the service URL and credentials it was issued for, so a change in any of them
     * is a cache miss even if the config save did not invalidate the entry.
     */
    private function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            (string)$this->aplazoHelper->getServiceUrl(),
            (string)$this->aplazoHelper->getMerchantId(),
            (string)$this->aplazoHelper->getApiToken(),
        ]));
    }

    private function readExp(string $token): ?int
    {
        $jwt = preg_replace('/^Bearer\s+/i', '', trim($token));
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!is_array($payload) || !isset($payload['exp']) || !is_numeric($payload['exp'])) {
            return null;
        }
        return (int)$payload['exp'];
    }
}
