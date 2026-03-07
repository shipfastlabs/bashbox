<?php

declare(strict_types=1);

namespace BashBox\Network;

use BashBox\Network\Exceptions\NetworkAccessDeniedException;

final readonly class AllowList
{
    public function __construct(
        private NetworkConfig $networkConfig,
    ) {}

    public function validateRequest(string $method, string $url): void
    {
        if (! $this->networkConfig->dangerouslyAllowFullInternetAccess) {
            $this->validateMethod($method);
            $this->validateUrl($url);
        }

        if ($this->networkConfig->denyPrivateRanges) {
            $this->validateNotPrivate($url);
        }
    }

    private function validateMethod(string $method): void
    {
        $upper = strtoupper($method);

        foreach ($this->networkConfig->allowedMethods as $allowed) {
            if (strtoupper((string) $allowed) === $upper) {
                return;
            }
        }

        throw new NetworkAccessDeniedException(sprintf(
            'HTTP method "%s" is not allowed. Allowed methods: %s. Set `dangerouslyAllowFullInternetAccess: true` to allow all methods (security risk).',
            $method,
            implode(', ', $this->networkConfig->allowedMethods),
        ));
    }

    private function validateUrl(string $url): void
    {
        if ($this->networkConfig->allowedUrlPrefixes === []) {
            return;
        }

        foreach ($this->networkConfig->allowedUrlPrefixes as $prefix) {
            if (str_starts_with($url, (string) $prefix)) {
                return;
            }
        }

        throw new NetworkAccessDeniedException(sprintf(
            'URL "%s" is not in the allowed URL prefixes. Set `dangerouslyAllowFullInternetAccess: true` to allow all URLs (security risk).',
            $url,
        ));
    }

    private function validateNotPrivate(string $url): void
    {
        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['host'])) {
            throw new NetworkAccessDeniedException(sprintf('Invalid URL: "%s"', $url));
        }

        $host = $parsed['host'];

        $ips = gethostbynamel($host);

        if ($ips === false) {
            if ($this->isPrivateHostname($host)) {
                throw new NetworkAccessDeniedException(sprintf(
                    'Access to private/internal host "%s" is denied (SSRF protection)',
                    $host,
                ));
            }

            return;
        }

        foreach ($ips as $ip) {
            if ($this->isPrivateIp($ip)) {
                throw new NetworkAccessDeniedException(sprintf(
                    'Access to private/internal IP "%s" (host: %s) is denied (SSRF protection)',
                    $ip,
                    $host,
                ));
            }
        }
    }

    private function isPrivateHostname(string $host): bool
    {
        $lower = strtolower($host);

        return in_array($lower, ['localhost', 'ip6-localhost', 'ip6-loopback'], true)
            || str_ends_with($lower, '.local')
            || str_ends_with($lower, '.internal');
    }

    private function isPrivateIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
