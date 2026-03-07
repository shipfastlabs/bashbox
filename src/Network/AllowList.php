<?php

declare(strict_types=1);

namespace BashBox\Network;

use BashBox\Network\Exceptions\NetworkAccessDeniedException;

final readonly class AllowList
{
    public function __construct(
        private NetworkConfig $config,
    ) {}

    public function validateRequest(string $method, string $url): void
    {
        $this->validateMethod($method);
        $this->validateUrl($url);

        if ($this->config->denyPrivateRanges) {
            $this->validateNotPrivate($url);
        }
    }

    private function validateMethod(string $method): void
    {
        $upper = strtoupper($method);
        foreach ($this->config->allowedMethods as $allowed) {
            if (strtoupper($allowed) === $upper) {
                return;
            }
        }

        throw new NetworkAccessDeniedException(sprintf(
            'HTTP method "%s" is not allowed. Allowed methods: %s',
            $method,
            implode(', ', $this->config->allowedMethods),
        ));
    }

    private function validateUrl(string $url): void
    {
        if ($this->config->allowedUrlPrefixes === []) {
            return;
        }

        foreach ($this->config->allowedUrlPrefixes as $prefix) {
            if (str_starts_with($url, $prefix)) {
                return;
            }
        }

        throw new NetworkAccessDeniedException(sprintf(
            'URL "%s" is not in the allowed URL prefixes',
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

        // Resolve the hostname to IP addresses
        $ips = gethostbynamel($host);
        if ($ips === false) {
            // If we can't resolve, check common private hostnames
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

        return $lower === 'localhost'
            || $lower === 'ip6-localhost'
            || $lower === 'ip6-loopback'
            || str_ends_with($lower, '.local')
            || str_ends_with($lower, '.internal');
    }

    private function isPrivateIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
