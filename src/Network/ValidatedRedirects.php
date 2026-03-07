<?php

declare(strict_types=1);

namespace BashBox\Network;

use BashBox\Network\Exceptions\NetworkAccessDeniedException;
use CurlHandle;
use Error;
use RuntimeException;

final readonly class ValidatedRedirects
{
    private int $maxRedirects;

    public function __construct(
        private AllowList $allowList,
        int $maxRedirects,
    ) {
        if ($maxRedirects < 1) {
            throw new Error('Invalid redirection limit: '.$maxRedirects);
        }

        $this->maxRedirects = $maxRedirects;
    }

    /**
     * Attach redirect validation to a curl handle.
     *
     * @param  CurlHandle  $ch
     */
    public function attachToCurl($ch, string $originalUrl): void
    {
        $redirectCount = 0;
        $currentUrl = $originalUrl;

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function (CurlHandle $curlHandle, string $header) use (
            &$redirectCount,
            &$currentUrl,
        ): int {
            if (! preg_match('/^Location:\s*(.+)$/i', $header, $matches)) {
                return strlen($header);
            }

            $redirectUrl = $this->resolveRedirectUrl($currentUrl, trim($matches[1]));
            $redirectCount++;

            if ($redirectCount > $this->maxRedirects) {
                throw new RuntimeException('Too many redirects');
            }

            try {
                $this->allowList->validateRequest('GET', $redirectUrl);
            } catch (NetworkAccessDeniedException $networkAccessDeniedException) {
                throw new NetworkAccessDeniedException(sprintf(
                    'Redirect to denied URL: %s (original: %s). %s',
                    $redirectUrl,
                    $currentUrl,
                    $networkAccessDeniedException->getMessage()
                ), $networkAccessDeniedException->getCode(), $networkAccessDeniedException);
            }

            $currentUrl = $redirectUrl;

            return strlen($header);
        });
    }

    /**
     * Resolve a redirect location against a base URL.
     */
    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        if (preg_match('/^https?:\/\//i', $location)) {
            return $location;
        }

        $parsed = parse_url($baseUrl);

        if ($parsed === false) {
            return $location;
        }

        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';

        if (str_starts_with($location, '/')) {
            return sprintf('%s://%s%s%s', $scheme, $host, $port, $location);
        }

        $basePath = $parsed['path'] ?? '/';
        $slashPos = strrpos($basePath, '/');
        $dir = $slashPos !== false ? substr($basePath, 0, $slashPos + 1) : '/';

        return sprintf('%s://%s%s%s%s', $scheme, $host, $port, $dir, $location);
    }
}
