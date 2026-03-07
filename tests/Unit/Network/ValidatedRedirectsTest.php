<?php

declare(strict_types=1);

use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\NullCancellation;
use BashBox\Network\AllowList;
use BashBox\Network\Exceptions\NetworkAccessDeniedException;
use BashBox\Network\NetworkConfig;
use BashBox\Network\ValidatedRedirects;

test('redirects are validated before the follow-up request is sent', function (): void {
    $allowList = new AllowList(new NetworkConfig(
        allowedUrlPrefixes: ['https://allowed.example/'],
        denyPrivateRanges: false,
    ));

    $interceptor = new ValidatedRedirects($allowList, 5);
    $requests = [];

    $client = new class($requests) implements DelegateHttpClient
    {
        /**
         * @param  list<Request>  $requests
         */
        public function __construct(
            public array &$requests,
        ) {}

        public function request(Request $request, \Amp\Cancellation $cancellation): Response
        {
            $this->requests[] = $request;

            return match (count($this->requests)) {
                1 => new Response(
                    '1.1',
                    302,
                    'Found',
                    ['location' => ['https://blocked.example/next']],
                    '',
                    $request,
                ),
            };
        }
    };

    $request = new Request('https://allowed.example/start');

    expect(fn (): Response => $interceptor->request($request, new NullCancellation, $client))
        ->toThrow(NetworkAccessDeniedException::class, 'Redirect to denied URL');

    expect($requests)->toHaveCount(1);
    expect((string) $requests[0]->getUri())->toBe('https://allowed.example/start');
});
