<?php

declare(strict_types=1);

namespace BashBox\Network;

use Amp\ByteStream\BufferException;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Interceptor\SetRequestTimeout;
use Amp\Http\Client\Request;
use BashBox\Network\Exceptions\NetworkAccessDeniedException;
use BashBox\Network\Exceptions\ResponseTooLargeException;

final class SecureHttpClient
{
    private readonly AllowList $allowList;

    private readonly HttpClient $client;

    public function __construct(
        private readonly NetworkConfig $config = new NetworkConfig,
    ) {
        $this->allowList = new AllowList($this->config);

        $timeout = new SetRequestTimeout(
            tcpConnectTimeout: min(10, $this->config->timeout),
            tlsHandshakeTimeout: min(10, $this->config->timeout),
            transferTimeout: $this->config->timeout,
        );

        $this->client = (new HttpClientBuilder)
            ->intercept($timeout)
            ->followRedirects($this->config->maxRedirects)
            ->build();
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{statusCode: int, headers: array<string, string>, body: string}
     */
    public function request(string $method, string $url, array $headers = [], string $body = ''): array
    {
        $this->allowList->validateRequest($method, $url);

        $request = new Request($url, strtoupper($method));
        $request->setBodySizeLimit($this->config->maxResponseSize);
        $request->setTransferTimeout($this->config->timeout);

        foreach ($headers as $name => $value) {
            $request->setHeader($name, $value);
        }

        if ($body !== '' && ! in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            $request->setBody($body);
        }

        $response = $this->client->request($request);

        // Validate the effective URI after redirects (SSRF check)
        $effectiveUri = (string) $response->getRequest()->getUri();
        if ($effectiveUri !== $url) {
            try {
                $this->allowList->validateRequest(strtoupper($method), $effectiveUri);
            } catch (NetworkAccessDeniedException $e) {
                throw new NetworkAccessDeniedException(sprintf(
                    'Redirect to denied URL: %s (original: %s). %s',
                    $effectiveUri,
                    $url,
                    $e->getMessage(),
                ));
            }
        }

        try {
            $responseBody = $response->getBody()->buffer(null, $this->config->maxResponseSize);
        } catch (BufferException) {
            throw new ResponseTooLargeException(sprintf(
                'Response exceeded maximum size of %d bytes',
                $this->config->maxResponseSize,
            ));
        }

        $responseHeaders = [];
        foreach ($response->getHeaders() as $name => $values) {
            $responseHeaders[strtolower($name)] = implode(', ', $values);
        }

        return [
            'statusCode' => $response->getStatus(),
            'headers' => $responseHeaders,
            'body' => $responseBody,
        ];
    }
}
