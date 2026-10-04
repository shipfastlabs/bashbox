<?php

declare(strict_types=1);

use BashBox\Network\AllowList;
use BashBox\Network\Exceptions\NetworkAccessDeniedException;
use BashBox\Network\NetworkConfig;

function prefixAllowList(string ...$prefixes): AllowList
{
    return new AllowList(new NetworkConfig(allowedUrlPrefixes: array_values($prefixes), denyPrivateRanges: false));
}

test('an empty allow-list denies every URL unless full access is enabled', function (): void {
    expect(fn () => new AllowList(new NetworkConfig)->validateRequest('GET', 'https://example.com/'))
        ->toThrow(NetworkAccessDeniedException::class, 'URL "https://example.com/" is not in the allowed URL prefixes');
});

test('origin-only prefixes match that exact origin', function (string $url): void {
    expect(fn () => prefixAllowList('https://api.example.com')->validateRequest('GET', $url))
        ->not->toThrow(NetworkAccessDeniedException::class);
})->with([
    'https://api.example.com',
    'https://api.example.com/v1/users',
    'https://api.example.com?q=1',
    'https://api.example.com#top',
]);

test('origin-only prefixes do not match other hosts sharing the prefix', function (string $url): void {
    expect(fn () => prefixAllowList('https://api.example.com')->validateRequest('GET', $url))
        ->toThrow(NetworkAccessDeniedException::class, 'is not in the allowed URL prefixes');
})->with([
    'subdomain suffix' => ['https://api.example.com.evil.net/'],
    'userinfo trick' => ['https://api.example.com@evil.net/'],
    'longer port' => ['https://api.example.com:8443/'],
]);

test('path prefixes cannot be escaped with dot segments', function (string $url): void {
    expect(fn () => prefixAllowList('https://api.example.com/v1/')->validateRequest('GET', $url))
        ->toThrow(NetworkAccessDeniedException::class, 'is not in the allowed URL prefixes');
})->with([
    'https://api.example.com/v1/../admin',
    'https://api.example.com/v1/%2e%2e/admin',
    'https://api.example.com/v1/..',
    'https://api.example.com/v1/./../admin',
]);

test('dot segments in the query string are not path traversal', function (): void {
    expect(fn () => prefixAllowList('https://api.example.com/v1/')->validateRequest('GET', 'https://api.example.com/v1/files?path=/../x'))
        ->not->toThrow(NetworkAccessDeniedException::class);
});

test('methods are matched case-insensitively', function (): void {
    $allowList = new AllowList(new NetworkConfig(
        allowedUrlPrefixes: ['https://api.example.com/'],
        allowedMethods: ['get'],
        denyPrivateRanges: false,
    ));

    expect(fn () => $allowList->validateRequest('GET', 'https://api.example.com/'))->not->toThrow(NetworkAccessDeniedException::class);
    expect(fn () => $allowList->validateRequest('DELETE', 'https://api.example.com/'))
        ->toThrow(NetworkAccessDeniedException::class, 'HTTP method "DELETE" is not allowed. Allowed methods: get.');
});

function ipUrl(string $ip): string
{
    return str_contains($ip, ':') ? sprintf('http://[%s]/', $ip) : sprintf('http://%s/', $ip);
}

test('special-purpose addresses are denied before and after connecting', function (string $ip): void {
    $allowList = new AllowList(new NetworkConfig(dangerouslyAllowFullInternetAccess: true));

    expect(fn () => $allowList->validateRequest('GET', ipUrl($ip)))
        ->toThrow(NetworkAccessDeniedException::class, sprintf('Access to private/internal IP "%s"', $ip));
    expect(fn () => $allowList->validateConnectedIp($ip, 'http://example.com/'))
        ->toThrow(NetworkAccessDeniedException::class, sprintf('Access to private/internal IP "%s"', $ip));
})->with([
    // First and last address of every denied range.
    '0.0.0.0/8' => ['0.0.0.0'], ['0.255.255.255'],
    '10.0.0.0/8' => ['10.0.0.0'], ['10.255.255.255'],
    '100.64.0.0/10 (CGNAT)' => ['100.64.0.0'], ['100.127.255.255'],
    '127.0.0.0/8' => ['127.0.0.0'], ['127.255.255.255'],
    '169.254.0.0/16' => ['169.254.0.0'], ['169.254.255.255'],
    '172.16.0.0/12' => ['172.16.0.0'], ['172.31.255.255'],
    '192.0.0.0/24' => ['192.0.0.0'], ['192.0.0.255'],
    '192.0.2.0/24' => ['192.0.2.0'], ['192.0.2.255'],
    '192.88.99.0/24' => ['192.88.99.0'], ['192.88.99.255'],
    '192.168.0.0/16' => ['192.168.0.0'], ['192.168.255.255'],
    '198.18.0.0/15' => ['198.18.0.0'], ['198.19.255.255'],
    '198.51.100.0/24' => ['198.51.100.0'], ['198.51.100.255'],
    '203.0.113.0/24' => ['203.0.113.0'], ['203.0.113.255'],
    '224.0.0.0/4 (multicast)' => ['224.0.0.0'], ['239.255.255.255'],
    '240.0.0.0/4 (incl. broadcast)' => ['240.0.0.0'], ['255.255.255.255'],
    '::/96' => ['::'], ['::1'], ['::ffff:ffff'],
    '::ffff:0:0/96 (mapped)' => ['::ffff:0.0.0.0'], ['::ffff:8.8.8.8'], ['::ffff:255.255.255.255'],
    '::ffff:0:0:0/96 (translated)' => ['::ffff:0:0.0.0.0'], ['::ffff:0:255.255.255.255'],
    '64:ff9b:1::/48' => ['64:ff9b:1::'], ['64:ff9b:1:ffff:ffff:ffff:ffff:ffff'],
    '100::/64' => ['100::'], ['100::ffff:ffff:ffff:ffff'],
    '2001::/23 (incl. Teredo)' => ['2001::'], ['2001:0:4136:e378:8000:63bf:3fff:fdd2'], ['2001:1ff:ffff:ffff:ffff:ffff:ffff:ffff'],
    '2001:db8::/32' => ['2001:db8::'], ['2001:db8:ffff:ffff:ffff:ffff:ffff:ffff'],
    '3fff::/20' => ['3fff::'], ['3fff:fff:ffff:ffff:ffff:ffff:ffff:ffff'],
    'fc00::/7' => ['fc00::'], ['fdff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
    'fe80::/10' => ['fe80::'], ['febf:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
    'fec0::/10' => ['fec0::'], ['feff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
    'ff00::/8' => ['ff00::'], ['ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
    // NAT64 and 6to4 route to the IPv4 address they embed.
    'NAT64 of 10.0.0.1' => ['64:ff9b::a00:1'],
    'NAT64 of 127.0.0.1' => ['64:ff9b::7f00:1'],
    'NAT64 of 100.64.0.0' => ['64:ff9b::6440:0'],
    '6to4 of 10.0.0.1' => ['2002:a00:1::'],
    '6to4 of 192.168.1.1' => ['2002:c0a8:101::1'],
    '6to4 of 100.127.255.255' => ['2002:647f:ffff::'],
]);

test('addresses next to the denied ranges are allowed before and after connecting', function (string $ip): void {
    $allowList = new AllowList(new NetworkConfig(dangerouslyAllowFullInternetAccess: true));

    expect(fn () => $allowList->validateRequest('GET', ipUrl($ip)))->not->toThrow(NetworkAccessDeniedException::class);
    expect(fn () => $allowList->validateConnectedIp($ip, 'http://example.com/'))->not->toThrow(NetworkAccessDeniedException::class);
})->with([
    '1.0.0.0', '9.255.255.255', '11.0.0.0', '100.63.255.255', '100.128.0.0', '126.255.255.255', '128.0.0.0',
    '169.253.255.255', '169.255.0.0', '172.15.255.255', '172.32.0.0', '191.255.255.255', '192.0.1.0',
    '192.0.1.255', '192.0.3.0', '192.88.98.255', '192.88.100.0', '192.167.255.255', '192.169.0.0',
    '198.17.255.255', '198.20.0.0', '198.51.99.255', '198.51.101.0', '203.0.112.255', '203.0.114.0',
    '223.255.255.255', '93.184.216.34',
    'IPv4 that starts like 6to4' => ['32.2.10.0'],
    '2000:ffff:ffff:ffff:ffff:ffff:ffff:ffff', '2001:200::', '2001:db7:ffff:ffff:ffff:ffff:ffff:ffff', '2001:db9::',
    '3ffe:ffff:ffff:ffff:ffff:ffff:ffff:ffff', '3fff:1000::', '2606:4700::1111',
    'NAT64 of a public address' => ['64:ff9b::808:808'],
    'NAT64 of 100.63.255.255' => ['64:ff9b::643f:ffff'],
    '6to4 of a public address' => ['2002:808:808::1'],
]);

test('a connected address that is not an IP is denied', function (): void {
    expect(fn () => new AllowList(new NetworkConfig)->validateConnectedIp('', 'http://example.com/'))
        ->toThrow(NetworkAccessDeniedException::class, 'Access to private/internal IP ""');
});

test('private hostnames are denied', function (string $url): void {
    $allowList = new AllowList(new NetworkConfig(dangerouslyAllowFullInternetAccess: true));

    expect(fn () => $allowList->validateRequest('GET', $url))
        ->toThrow(NetworkAccessDeniedException::class, 'is denied (SSRF protection)');
})->with([
    'http://LOCALHOST/',
    'http://app.localhost/',
    'http://printer.local/',
    'http://metadata.google.internal/',
]);

test('connected addresses are checked only when denyPrivateRanges is on', function (): void {
    $protected = new AllowList(new NetworkConfig(dangerouslyAllowFullInternetAccess: true));
    $unprotected = new AllowList(new NetworkConfig(denyPrivateRanges: false));

    expect(fn () => $protected->validateConnectedIp('10.1.2.3', 'http://intranet.example/'))
        ->toThrow(NetworkAccessDeniedException::class, 'Access to private/internal IP "10.1.2.3" (url: http://intranet.example/) is denied');
    expect(fn () => $protected->validateConnectedIp('93.184.216.34', 'http://example.com/'))->not->toThrow(NetworkAccessDeniedException::class);
    expect(fn () => $unprotected->validateConnectedIp('10.1.2.3', 'http://intranet.example/'))->not->toThrow(NetworkAccessDeniedException::class);
});
