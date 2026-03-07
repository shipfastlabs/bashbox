<?php

declare(strict_types=1);

use BashBox\Network\AllowList;
use BashBox\Network\Exceptions\NetworkAccessDeniedException;
use BashBox\Network\NetworkConfig;

test('curl allows any URL with dangerouslyAllowFullInternetAccess', function (): void {
    $network = new NetworkConfig(
        allowedUrlPrefixes: [],
        dangerouslyAllowFullInternetAccess: true
    );
    $allowList = new AllowList($network);

    expect(fn (): ?object => $allowList->validateRequest('GET', 'https://any-website.com'))->not->toThrow(NetworkAccessDeniedException::class);
    expect(fn (): ?object => $allowList->validateRequest('POST', 'https://example.com/api'))->not->toThrow(NetworkAccessDeniedException::class);
    expect(fn (): ?object => $allowList->validateRequest('GET', 'http://untrusted-site.com'))->not->toThrow(NetworkAccessDeniedException::class);
});

test('curl still respects denyPrivateRanges with full internet access', function (): void {
    $network = new NetworkConfig(
        allowedUrlPrefixes: [],
        denyPrivateRanges: true,
        dangerouslyAllowFullInternetAccess: true
    );
    $allowList = new AllowList($network);

    expect(fn (): ?object => $allowList->validateRequest('GET', 'http://192.168.1.1'))->toThrow(
        NetworkAccessDeniedException::class,
        'private'
    );
    expect(fn (): ?object => $allowList->validateRequest('GET', 'http://10.0.0.1'))->toThrow(
        NetworkAccessDeniedException::class,
        'private'
    );
    expect(fn (): ?object => $allowList->validateRequest('GET', 'http://127.0.0.1'))->toThrow(
        NetworkAccessDeniedException::class,
        'private'
    );
});

test('curl allows any HTTP method with dangerouslyAllowFullInternetAccess', function (): void {
    $network = new NetworkConfig(
        allowedUrlPrefixes: [],
        allowedMethods: ['GET'],
        dangerouslyAllowFullInternetAccess: true
    );
    $allowList = new AllowList($network);

    expect(fn (): ?object => $allowList->validateRequest('POST', 'https://example.com'))->not->toThrow(NetworkAccessDeniedException::class);
    expect(fn (): ?object => $allowList->validateRequest('PUT', 'https://example.com'))->not->toThrow(NetworkAccessDeniedException::class);
    expect(fn (): ?object => $allowList->validateRequest('DELETE', 'https://example.com'))->not->toThrow(NetworkAccessDeniedException::class);
    expect(fn (): ?object => $allowList->validateRequest('PATCH', 'https://example.com'))->not->toThrow(NetworkAccessDeniedException::class);
    expect(fn (): ?object => $allowList->validateRequest('OPTIONS', 'https://example.com'))->not->toThrow(NetworkAccessDeniedException::class);
});

test('curl respects restrictions without dangerouslyAllowFullInternetAccess', function (): void {
    $network = new NetworkConfig(
        allowedUrlPrefixes: ['https://allowed.com'],
        allowedMethods: ['GET'],
        dangerouslyAllowFullInternetAccess: false
    );
    $allowList = new AllowList($network);

    expect(fn (): ?object => $allowList->validateRequest('GET', 'https://example.com'))->toThrow(
        NetworkAccessDeniedException::class,
        'dangerouslyAllowFullInternetAccess'
    );

    expect(fn (): ?object => $allowList->validateRequest('POST', 'https://allowed.com'))->toThrow(
        NetworkAccessDeniedException::class,
        'dangerouslyAllowFullInternetAccess'
    );
});

test('empty allowedUrlPrefixes is valid with dangerouslyAllowFullInternetAccess', function (): void {
    $network = new NetworkConfig(
        allowedUrlPrefixes: [],
        dangerouslyAllowFullInternetAccess: true
    );
    $allowList = new AllowList($network);

    expect(fn (): ?object => $allowList->validateRequest('GET', 'https://example.com'))->not->toThrow(NetworkAccessDeniedException::class);
});

test('dangerouslyAllowFullInternetAccess takes precedence over allowedUrlPrefixes', function (): void {
    $network = new NetworkConfig(
        allowedUrlPrefixes: ['https://specific.com'],
        allowedMethods: ['GET'],
        dangerouslyAllowFullInternetAccess: true
    );
    $allowList = new AllowList($network);

    expect(fn (): ?object => $allowList->validateRequest('GET', 'https://other.com'))->not->toThrow(NetworkAccessDeniedException::class);
    expect(fn (): ?object => $allowList->validateRequest('POST', 'https://another.com'))->not->toThrow(NetworkAccessDeniedException::class);
});

test('private ranges can be accessed when denyPrivateRanges is false', function (): void {
    $network = new NetworkConfig(
        allowedUrlPrefixes: [],
        denyPrivateRanges: false,
        dangerouslyAllowFullInternetAccess: true
    );
    $allowList = new AllowList($network);

    try {
        $allowList->validateRequest('GET', 'http://192.168.1.1');
    } catch (NetworkAccessDeniedException $networkAccessDeniedException) {
        expect($networkAccessDeniedException->getMessage())->not->toContain('private');
        expect($networkAccessDeniedException->getMessage())->not->toContain('SSRF');
    }
});

test('error messages suggest using dangerouslyAllowFullInternetAccess', function (): void {
    $network = new NetworkConfig(
        allowedUrlPrefixes: ['https://allowed.com'],
        allowedMethods: ['GET'],
        dangerouslyAllowFullInternetAccess: false
    );
    $allowList = new AllowList($network);

    try {
        $allowList->validateRequest('GET', 'https://example.com');
    } catch (NetworkAccessDeniedException $networkAccessDeniedException) {
        expect($networkAccessDeniedException->getMessage())->toContain('dangerouslyAllowFullInternetAccess');
        expect($networkAccessDeniedException->getMessage())->toContain('security risk');
    }

    try {
        $allowList->validateRequest('POST', 'https://allowed.com');
    } catch (NetworkAccessDeniedException $networkAccessDeniedException) {
        expect($networkAccessDeniedException->getMessage())->toContain('dangerouslyAllowFullInternetAccess');
        expect($networkAccessDeniedException->getMessage())->toContain('security risk');
    }
});

test('dangerouslyAllowFullInternetAccess defaults to false', function (): void {
    $network = new NetworkConfig;

    expect($network->dangerouslyAllowFullInternetAccess)->toBe(false);
});

test('NetworkConfig has dangerouslyAllowFullInternetAccess property', function (): void {
    $network = new NetworkConfig(
        dangerouslyAllowFullInternetAccess: true
    );

    expect($network->dangerouslyAllowFullInternetAccess)->toBe(true);

    $network2 = new NetworkConfig(
        dangerouslyAllowFullInternetAccess: false
    );

    expect($network2->dangerouslyAllowFullInternetAccess)->toBe(false);
});

test('localhost is blocked with full internet access and denyPrivateRanges', function (): void {
    $network = new NetworkConfig(
        allowedUrlPrefixes: [],
        denyPrivateRanges: true,
        dangerouslyAllowFullInternetAccess: true
    );
    $allowList = new AllowList($network);

    expect(fn (): ?object => $allowList->validateRequest('GET', 'http://localhost'))->toThrow(
        NetworkAccessDeniedException::class,
        'private'
    );
});

test('NetworkConfig limits still apply with dangerouslyAllowFullInternetAccess', function (): void {
    $network = new NetworkConfig(
        maxResponseSize: 1024,
        maxRedirects: 5,
        timeout: 10,
        dangerouslyAllowFullInternetAccess: true,
    );

    expect($network->maxResponseSize)->toBe(1024);
    expect($network->maxRedirects)->toBe(5);
    expect($network->timeout)->toBe(10);
});
