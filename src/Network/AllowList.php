<?php

declare(strict_types=1);

namespace BashBox\Network;

use BashBox\Network\Exceptions\NetworkAccessDeniedException;

final readonly class AllowList
{
    /** Ranges that are not globally reachable (IANA special-purpose registries), many of which PHP's filter flags miss. */
    private const array DENIED_RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/96', '::ffff:0:0/96', '::ffff:0:0:0/96', '64:ff9b:1::/48', '100::/64', '2001::/23',
        '2001:db8::/32', '3fff::/20', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    public function __construct(
        private NetworkConfig $networkConfig,
    ) {}

    public function validateRequest(string $method, string $url): void
    {
        if (! $this->networkConfig->dangerouslyAllowFullInternetAccess) {
            $this->validateMethod($method);
            $this->validateUrl($url);
        }

        // Obvious cases are rejected before connecting; everything else is checked
        // against the address actually connected to (see validateConnectedIp).
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->validateConnectedIp($host, $url);
        } elseif ($this->networkConfig->denyPrivateRanges && $this->isPrivateHostname($host)) {
            throw new NetworkAccessDeniedException(sprintf(
                'Access to private/internal host "%s" is denied (SSRF protection)',
                $host,
            ));
        }
    }

    /**
     * Checked against the peer address of every connection, so DNS tricks
     * (rebinding, IPv6-only names, decimal IPs) cannot reach private ranges.
     */
    public function validateConnectedIp(string $ip, string $url): void
    {
        if ($this->networkConfig->denyPrivateRanges && $this->isPrivateIp($ip)) {
            throw new NetworkAccessDeniedException(sprintf(
                'Access to private/internal IP "%s" (url: %s) is denied (SSRF protection)',
                $ip,
                $url,
            ));
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
        // curl collapses "/../" in the path before sending, which would escape a path-scoped prefix.
        if (preg_match('~^[^?#]*/(\.|%2e){1,2}([/?#]|$)~i', $url) !== 1) {
            foreach ($this->networkConfig->allowedUrlPrefixes as $prefix) {
                if ($this->matchesPrefix($url, (string) $prefix)) {
                    return;
                }
            }
        }

        throw new NetworkAccessDeniedException(sprintf(
            'URL "%s" is not in the allowed URL prefixes. Set `dangerouslyAllowFullInternetAccess: true` to allow all URLs (security risk).',
            $url,
        ));
    }

    private function matchesPrefix(string $url, string $prefix): bool
    {
        if (! str_starts_with($url, $prefix)) {
            return false;
        }

        // An origin-only prefix ("https://api.example.com") must not match
        // "https://api.example.com.evil.net" or "https://api.example.com@evil.net".
        $rest = substr($url, strlen($prefix));

        return preg_match('~^[^:/?#]+://[^/?#]*$~', $prefix) !== 1
            || $rest === ''
            || str_contains('/?#', $rest[0]);
    }

    private function isPrivateHostname(string $host): bool
    {
        return in_array($host, ['localhost', 'ip6-localhost', 'ip6-loopback'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal');
    }

    private function isPrivateIp(string $ip): bool
    {
        $packed = inet_pton($ip);

        if ($packed === false) {
            return true;
        }

        // NAT64 (64:ff9b::/96) and 6to4 (2002::/16) addresses are routed to the IPv4 address they embed.
        $embedded = match (true) {
            str_starts_with($packed, "\x00\x64\xff\x9b".str_repeat("\x00", 8)) => substr($packed, 12),
            strlen($packed) === 16 && str_starts_with($packed, "\x20\x02") => substr($packed, 2, 4),
            default => null,
        };

        if ($embedded !== null && $this->isPrivateIp((string) inet_ntop($embedded))) {
            return true;
        }

        foreach (self::DENIED_RANGES as $range) {
            [$network, $bits] = explode('/', $range);
            $network = (string) inet_pton($network);
            $bytes = intdiv((int) $bits, 8);
            $mask = 0xFF << (8 - (int) $bits % 8) & 0xFF;

            if (strlen($network) === strlen($packed)
                && strncmp($network, $packed, $bytes) === 0
                && (ord($network[$bytes] ?? "\0") & $mask) === (ord($packed[$bytes] ?? "\0") & $mask)) {
                return true;
            }
        }

        return false;
    }
}
