<?php

declare(strict_types=1);

use BashBox\Network\AllowList;
use BashBox\Network\Exceptions\NetworkAccessDeniedException;
use BashBox\Network\NetworkConfig;
use BashBox\Network\ValidatedRedirects;

test('validator rejects redirect to blocked domain', function (): void {
    $validator = new ValidatedRedirects(new AllowList(new NetworkConfig(
        allowedUrlPrefixes: ['https://allowed.example/'],
        denyPrivateRanges: false,
    )), 5);

    expect(fn () => $validator->validate('GET', 'https://allowed.example/start', 'https://blocked.example/next'))
        ->toThrow(
            NetworkAccessDeniedException::class,
            'Redirect to denied URL: https://blocked.example/next (original: https://allowed.example/start). URL "https://blocked.example/next" is not in the allowed URL prefixes',
        );
});

test('validator allows redirect to allowed domain', function (): void {
    $validator = new ValidatedRedirects(new AllowList(new NetworkConfig(
        allowedUrlPrefixes: ['https://allowed.example/'],
        denyPrivateRanges: false,
    )), 5);

    expect(fn () => $validator->validate('GET', 'https://allowed.example/start', 'https://allowed.example/next'))
        ->not->toThrow(Throwable::class);
});

test('validator checks the method the redirect will use', function (): void {
    $validator = new ValidatedRedirects(new AllowList(new NetworkConfig(
        allowedUrlPrefixes: ['https://allowed.example/'],
        allowedMethods: ['POST'],
    )), 5);

    expect(fn () => $validator->validate('GET', 'https://allowed.example/form', 'https://allowed.example/done'))
        ->toThrow(NetworkAccessDeniedException::class, 'HTTP method "GET" is not allowed');
});

test('validator rejects redirects to private addresses even with full internet access', function (): void {
    $validator = new ValidatedRedirects(new AllowList(new NetworkConfig(dangerouslyAllowFullInternetAccess: true)), 5);

    expect(fn () => $validator->validate('GET', 'https://public.example/', 'http://169.254.169.254/latest/meta-data/'))
        ->toThrow(NetworkAccessDeniedException::class, 'Access to private/internal IP "169.254.169.254"');
});

test('validator stops after maxRedirects hops', function (): void {
    $validator = new ValidatedRedirects(new AllowList(new NetworkConfig(dangerouslyAllowFullInternetAccess: true)), 2);

    $validator->validate('GET', 'https://a.example/', 'https://b.example/');
    $validator->validate('GET', 'https://b.example/', 'https://c.example/');

    expect(fn () => $validator->validate('GET', 'https://c.example/', 'https://d.example/'))
        ->toThrow(new RuntimeException('Maximum (2) redirects followed', 47));
});

test('max redirects limit is enforced', function (): void {
    expect(fn (): \BashBox\Network\ValidatedRedirects => new ValidatedRedirects(
        new AllowList(new NetworkConfig),
        0
    ))->toThrow(\Error::class, 'Invalid redirection limit: 0');

    expect(fn (): \BashBox\Network\ValidatedRedirects => new ValidatedRedirects(
        new AllowList(new NetworkConfig),
        -1
    ))->toThrow(\Error::class, 'Invalid redirection limit: -1');
});
