<?php

declare(strict_types=1);

namespace BashBox\Network;

use Amp\ByteStream\BufferException;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Interceptor\SetRequestTimeout;
use Amp\Http\Client\Request;
use BashBox\Network\Exceptions\ResponseTooLargeException;

final readonly class SecureHttpClient
{
    private AllowList $allowList;

    private HttpClient $httpClient;

    public function __construct(
        private NetworkConfig $networkConfig = new NetworkConfig,
    ) {
        $this->allowList = new AllowList($this->networkConfig);

        $setRequestTimeout = new SetRequestTimeout(
            tcpConnectTimeout: min(10, $this->networkConfig->timeout),
            tlsHandshakeTimeout: min(10, $this->networkConfig->timeout),
            transferTimeout: $this->networkConfig->timeout,
        );

        $this->httpClient = (new HttpClientBuilder)
            ->intercept($setRequestTimeout)
            ->followRedirects(0)
            ->intercept(new ValidatedRedirects($this->allowList, $this->networkConfig->maxRedirects))
            ->build();
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{statusCode: int, headers: array<string, string>, body: string}
     */
    public function request(string $method, string $url, array $headers = [], string $body = ''): array
    {
        $this->allowList->validateRequest($method, $url);

        /** @var non-empty-string&uppercase-string $upperMethod */
        $upperMethod = strtoupper($method);
        $request = new Request($url, $upperMethod);
        $request->setBodySizeLimit($this->networkConfig->maxResponseSize);
        $request->setTransferTimeout($this->networkConfig->timeout);

        foreach ($headers as $name => $value) {
            /** @var non-empty-string $name */
            $request->setHeader($name, $value);
        }

        if ($body !== '' && ! in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            $request->setBody($body);
        }

        $response = $this->httpClient->request($request);

        try {
            $responseBody = $response->getBody()->buffer(null, $this->networkConfig->maxResponseSize);
        } catch (BufferException) {
            throw new ResponseTooLargeException(sprintf(
                'Response exceeded maximum size of %d bytes',
                $this->networkConfig->maxResponseSize,
            ));
        }

        $responseHeaders = [];

        foreach ($response->getHeaders() as $name => $header) {
            $responseHeaders[strtolower((string) $name)] = implode(', ', $header);
        }

        return [
            'statusCode' => $response->getStatus(),
            'headers' => $responseHeaders,
            'body' => $responseBody,
        ];
    }
}
