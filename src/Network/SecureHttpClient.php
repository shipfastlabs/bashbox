<?php

declare(strict_types=1);

namespace BashBox\Network;

use BashBox\Network\Exceptions\NetworkAccessDeniedException;
use BashBox\Network\Exceptions\ResponseTooLargeException;
use CurlHandle;
use RuntimeException;

final readonly class SecureHttpClient
{
    private AllowList $allowList;

    public function __construct(private NetworkConfig $networkConfig = new NetworkConfig)
    {
        $this->allowList = new AllowList($this->networkConfig);
    }

    /**
     * Redirects are followed one hop at a time so every target is validated before it is requested.
     *
     * @param  array<string, string>  $headers
     * @return array{statusCode: int, headers: array<string, string>, body: string}
     *
     * @throws NetworkAccessDeniedException when the request or a redirect target is not allowed
     * @throws ResponseTooLargeException when the body exceeds maxResponseSize
     * @throws RuntimeException on transport errors (the code is the curl error number)
     */
    public function request(string $method, string $url, array $headers = [], string $body = '', bool $followRedirects = true): array
    {
        $method = strtoupper($method);
        $this->allowList->validateRequest($method, $url);
        $validatedRedirects = new ValidatedRedirects($this->allowList, $this->networkConfig->maxRedirects);

        while (true) {
            [$response, $redirectUrl] = $this->send($method, $url, $headers, $body);

            $status = $response['statusCode'];

            if (! $followRedirects || $redirectUrl === '' || $status < 300 || $status > 399) {
                return $response;
            }

            // Like curl: 303 always switches to GET, 301/302 only for POST.
            if (($status === 303 && $method !== 'HEAD') || ($method === 'POST' && in_array($status, [301, 302], true))) {
                $method = 'GET';
                $body = '';
            }

            $validatedRedirects->validate($method, $url, $redirectUrl);

            // Like curl: credentials are not sent to a different origin.
            if ($this->origin($redirectUrl) !== $this->origin($url)) {
                $headers = array_filter(
                    $headers,
                    fn (string|int $name): bool => ! in_array(strtolower((string) $name), ['authorization', 'cookie', 'proxy-authorization'], true),
                    ARRAY_FILTER_USE_KEY,
                );
            }

            $url = $redirectUrl;
        }
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url) ?: [];
        $scheme = strtolower($parts['scheme'] ?? '');

        return sprintf('%s://%s:%d', $scheme, strtolower($parts['host'] ?? ''), $parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{array{statusCode: int, headers: array<string, string>, body: string}, string}
     */
    private function send(string $method, string $url, array $headers, string $body): array
    {
        $responseHeaders = [];
        $responseBody = '';
        $denied = null;
        $maxResponseSize = $this->networkConfig->maxResponseSize;

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // Never use a proxy from http_proxy/https_proxy: it would bypass the connected-IP check.
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT => min(10, $this->networkConfig->timeout),
            CURLOPT_TIMEOUT => $this->networkConfig->timeout,
            CURLOPT_HTTPHEADER => array_map(
                fn (string $name, string $value): string => sprintf('%s: %s', $name, $value),
                array_keys($headers),
                $headers,
            ),
            CURLOPT_PREREQFUNCTION => function (CurlHandle $curlHandle, string $connectedIp) use ($url, &$denied): int {
                try {
                    $this->allowList->validateConnectedIp($connectedIp, $url);
                } catch (NetworkAccessDeniedException $networkAccessDeniedException) {
                    $denied = $networkAccessDeniedException;

                    return CURL_PREREQFUNC_ABORT;
                }

                return CURL_PREREQFUNC_OK;
            },
            CURLOPT_HEADERFUNCTION => function (CurlHandle $curlHandle, string $line) use (&$responseHeaders): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = []; // A new response, e.g. after "100 Continue"
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function (CurlHandle $curlHandle, string $chunk) use (&$responseBody, $maxResponseSize): int {
                $responseBody .= $chunk;

                // Returning less than the chunk length aborts the transfer.
                return strlen($responseBody) > $maxResponseSize ? 0 : strlen($chunk);
            },
        ]);

        if ($body !== '' && $method !== 'HEAD') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $succeeded = curl_exec($ch);

        if ($denied instanceof NetworkAccessDeniedException) {
            throw $denied;
        }

        if (strlen($responseBody) > $maxResponseSize) {
            throw new ResponseTooLargeException(sprintf('Response exceeded maximum size of %d bytes', $maxResponseSize));
        }

        if ($succeeded === false) {
            throw new RuntimeException(curl_error($ch), curl_errno($ch));
        }

        return [
            ['statusCode' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => $responseHeaders, 'body' => $responseBody],
            (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL),
        ];
    }
}
