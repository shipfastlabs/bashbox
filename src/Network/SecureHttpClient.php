<?php

declare(strict_types=1);

namespace BashBox\Network;

use BashBox\Network\Exceptions\ResponseTooLargeException;
use RuntimeException;

final readonly class SecureHttpClient
{
    private AllowList $allowList;

    public function __construct(
        private NetworkConfig $networkConfig = new NetworkConfig,
    ) {
        $this->allowList = new AllowList($this->networkConfig);
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

        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL');
        }

        $validatedRedirects = new ValidatedRedirects($this->allowList, $this->networkConfig->maxRedirects);
        $validatedRedirects->attachToCurl($ch, $url);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $upperMethod);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, $this->networkConfig->maxRedirects);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $this->networkConfig->timeout));
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->networkConfig->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);

        if ($headers !== []) {
            $curlHeaders = array_map(
                fn (string $name, string $value): string => sprintf('%s: %s', $name, $value),
                array_keys($headers),
                $headers
            );
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        }

        if ($body !== '' && ! in_array($upperMethod, ['GET', 'HEAD'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new RuntimeException('HTTP request failed: '.$error);
        }

        // Ensure $response is a string (PHPStan doesn't know curl_exec returns string on success with CURLOPT_RETURNTRANSFER)
        if (! is_string($response)) {
            throw new RuntimeException('Unexpected curl_exec return type');
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $headerStr = substr($response, 0, $headerSize);
        $bodyStr = substr($response, $headerSize);

        if (strlen($bodyStr) > $this->networkConfig->maxResponseSize) {
            throw new ResponseTooLargeException(sprintf(
                'Response exceeded maximum size of %d bytes',
                $this->networkConfig->maxResponseSize,
            ));
        }

        $responseHeaders = $this->parseHeaders($headerStr);

        return [
            'statusCode' => $statusCode,
            'headers' => $responseHeaders,
            'body' => $bodyStr,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $headerStr): array
    {
        $headers = [];
        $lines = explode("\r\n", $headerStr);

        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return $headers;
    }
}
