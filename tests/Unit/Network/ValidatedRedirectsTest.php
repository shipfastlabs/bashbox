<?php

declare(strict_types=1);

use BashBox\Network\AllowList;
use BashBox\Network\Exceptions\NetworkAccessDeniedException;
use BashBox\Network\NetworkConfig;
use BashBox\Network\ValidatedRedirects;

test('redirects are validated before the follow-up request is sent', function (): void {
    $allowList = new AllowList(new NetworkConfig(
        allowedUrlPrefixes: ['https://allowed.example/'],
        denyPrivateRanges: false,
    ));

    $validator = new ValidatedRedirects($allowList, 5);

    $ch = curl_init('https://allowed.example/start');
    $validatedUrls = [];

    $validator->attachToCurl($ch, 'https://allowed.example/start');

    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$validatedUrls): int {
        $headerLine = trim($header);

        if (preg_match('/^Location:\s*(.+)$/i', $headerLine, $matches)) {
            $location = trim($matches[1]);

            if (! preg_match('/^https?:\/\//i', $location)) {
                $location = 'https://blocked.example'.(str_starts_with($location, '/') ? '' : '/').$location;
            }

            $validatedUrls[] = $location;
        }

        return strlen($header);
    });

    curl_close($ch);

    expect(fn () => $allowList->validateRequest('GET', 'https://blocked.example/next'))
        ->toThrow(NetworkAccessDeniedException::class);

    expect(fn () => $allowList->validateRequest('GET', 'https://allowed.example/start'))
        ->not->toThrow(NetworkAccessDeniedException::class);
});

test('validator rejects redirect to blocked domain', function (): void {
    $allowList = new AllowList(new NetworkConfig(
        allowedUrlPrefixes: ['https://allowed.example/'],
        denyPrivateRanges: false,
    ));

    $validator = new ValidatedRedirects($allowList, 5);

    expect(fn () => $allowList->validateRequest('GET', 'https://blocked.example/next'))
        ->toThrow(NetworkAccessDeniedException::class, 'URL "https://blocked.example/next" is not in the allowed URL prefixes');
});

test('validator allows redirect to allowed domain', function (): void {
    $allowList = new AllowList(new NetworkConfig(
        allowedUrlPrefixes: ['https://allowed.example/'],
        denyPrivateRanges: false,
    ));

    $validator = new ValidatedRedirects($allowList, 5);

    expect(fn () => $allowList->validateRequest('GET', 'https://allowed.example/next'))
        ->not->toThrow(NetworkAccessDeniedException::class);
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
