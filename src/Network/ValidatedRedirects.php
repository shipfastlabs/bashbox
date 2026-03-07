<?php

declare(strict_types=1);

namespace BashBox\Network;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\Client\ApplicationInterceptor;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Interceptor\FollowRedirects;
use Amp\Http\Client\Interceptor\TooManyRedirectsException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use BashBox\Network\Exceptions\NetworkAccessDeniedException;
use Error;
use Exception;
use League\Uri;
use Psr\Http\Message\UriInterface;

final readonly class ValidatedRedirects implements ApplicationInterceptor
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private AllowList $allowList,
        private int $maxRedirects,
        private bool $autoReferrer = true,
    ) {
        if ($this->maxRedirects < 1) {
            throw new Error('Invalid redirection limit: '.$this->maxRedirects);
        }
    }

    public function request(
        Request $request,
        Cancellation $cancellation,
        DelegateHttpClient $httpClient,
    ): Response {
        $clonedRequest = $this->cloneRequest($request);
        $response = $httpClient->request($request, $cancellation);

        return $this->followRedirects($clonedRequest, $response, $httpClient, $cancellation);
    }

    private function followRedirects(
        Request $clonedRequest,
        Response $response,
        DelegateHttpClient $delegateHttpClient,
        Cancellation $cancellation,
    ): Response {
        $requestNumber = 2;

        do {
            $request = $this->updateRequestForRedirect($clonedRequest, $response);

            if (! $request instanceof \Amp\Http\Client\Request) {
                return $response;
            }

            $this->validateRedirectRequest($request, $response);

            $clonedRequest = $this->cloneRequest($request);
            $redirectResponse = $delegateHttpClient->request($request, $cancellation);
            $redirectResponse->setPreviousResponse($response);
            $response = $redirectResponse;
        } while (++$requestNumber <= $this->maxRedirects + 1);

        if ($this->getRedirectUri($response) instanceof \Psr\Http\Message\UriInterface) {
            throw new TooManyRedirectsException($response);
        }

        return $response;
    }

    private function validateRedirectRequest(Request $request, Response $response): void
    {
        try {
            $this->allowList->validateRequest($request->getMethod(), (string) $request->getUri());
        } catch (NetworkAccessDeniedException $networkAccessDeniedException) {
            throw new NetworkAccessDeniedException(sprintf(
                'Redirect to denied URL: %s (original: %s). %s',
                (string) $request->getUri(),
                (string) $response->getOriginalRequest()->getUri(),
                $networkAccessDeniedException->getMessage(),
            ), $networkAccessDeniedException->getCode(), $networkAccessDeniedException);
        }
    }

    private function cloneRequest(Request $originalRequest): Request
    {
        $request = clone $originalRequest;
        $request->setMethod('GET');
        $request->removeHeader('transfer-encoding');
        $request->removeHeader('content-length');
        $request->removeHeader('content-type');

        return $request;
    }

    private function updateRequestForRedirect(Request $request, Response $response): ?Request
    {
        $redirectUri = $this->getRedirectUri($response);

        if (! $redirectUri instanceof \Psr\Http\Message\UriInterface) {
            return null;
        }

        $originalUri = $response->getRequest()->getUri();
        $isSameHost = $redirectUri->getAuthority() === $originalUri->getAuthority();

        $request->setUri($redirectUri);

        if (! $isSameHost) {
            $request->setHeaders([]);
        }

        if ($this->autoReferrer) {
            $this->assignRedirectRefererHeader($request, $originalUri, $redirectUri);
        }

        $this->discardResponseBody($response);

        return $request;
    }

    private function assignRedirectRefererHeader(
        Request $request,
        UriInterface $referrerUri,
        UriInterface $followUri,
    ): void {
        $referrerIsEncrypted = $referrerUri->getScheme() === 'https';
        $destinationIsEncrypted = $followUri->getScheme() === 'https';

        if (! $referrerIsEncrypted || $destinationIsEncrypted) {
            $request->setHeader('Referer', (string) $referrerUri->withUserInfo('')->withFragment(''));

            return;
        }

        $request->removeHeader('Referer');
    }

    private function getRedirectUri(Response $response): ?UriInterface
    {
        if (count($response->getHeaderArray('location')) !== 1) {
            return null;
        }

        $status = $response->getStatus();
        $request = $response->getRequest();
        $method = $request->getMethod();

        if ($method !== 'GET' && in_array($status, [307, 308], true)) {
            return null;
        }

        if (! in_array($status, [301, 302, 303, 307, 308], true)) {
            return null;
        }

        try {
            $header = $response->getHeader('location');
            assert($header !== null);

            $locationUri = Uri\Http::new($header);
        } catch (Exception) {
            return null;
        }

        return FollowRedirects::resolve($request->getUri(), $locationUri);
    }

    private function discardResponseBody(Response $response): void
    {
        $payload = $response->getBody();

        try {
            while ($payload->read() !== null) {
            }
        } catch (HttpException|StreamException) {
        } finally {
            unset($payload);
        }
    }
}
