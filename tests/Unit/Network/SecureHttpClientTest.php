<?php

declare(strict_types=1);

use BashBox\Network\Exceptions\NetworkAccessDeniedException;
use BashBox\Network\Exceptions\ResponseTooLargeException;
use BashBox\Network\NetworkConfig;
use BashBox\Network\SecureHttpClient;
use BashBox\Tests\Fixtures\TestHttpServer;

/** A client allowed to reach the local test server only. */
function localClient(int $maxRedirects = 5, int $maxResponseSize = 1024): SecureHttpClient
{
    return new SecureHttpClient(new NetworkConfig(
        allowedUrlPrefixes: [TestHttpServer::url('/')],
        denyPrivateRanges: false,
        maxResponseSize: $maxResponseSize,
        maxRedirects: $maxRedirects,
        timeout: 5,
    ));
}

/**
 * A path on the test server that creates $file when requested.
 *
 * @return array{string, string} [path, file]
 */
function hitPath(): array
{
    $file = sys_get_temp_dir().'/bashbox-hit-'.bin2hex(random_bytes(8));

    return ['/hit?file='.urlencode($file), $file];
}

test('rejects an empty method or URL', function (string $method, string $url): void {
    localClient()->request($method, $url);
})->with([
    'empty method' => ['', 'http://localhost/'],
    'empty URL' => ['GET', ''],
])->throws(InvalidArgumentException::class, 'The method and the URL must not be empty');

test('returns status, lowercased headers and body', function (): void {
    $response = localClient()->request('GET', TestHttpServer::url('/hello'));

    expect($response['statusCode'])->toBe(200)
        ->and($response['headers']['x-greeting'])->toBe('hi')
        ->and($response['body'])->toBe("hello world\n");
});

test('sends method, headers and body', function (): void {
    $response = localClient()->request('put', TestHttpServer::url('/echo'), ['X-Token' => 'abc'], 'payload');
    $echo = json_decode($response['body'], true);

    expect($echo['method'])->toBe('PUT')
        ->and($echo['headers']['X-Token'])->toBe('abc')
        ->and($echo['body'])->toBe('payload');
});

test('HEAD returns headers without waiting for a body', function (): void {
    $response = localClient()->request('HEAD', TestHttpServer::url('/hello'));

    expect($response['statusCode'])->toBe(200)
        ->and($response['headers']['x-greeting'])->toBe('hi')
        ->and($response['body'])->toBe('');
});

test('returns error statuses as responses', function (): void {
    $response = localClient()->request('GET', TestHttpServer::url('/status?code=503'));

    expect($response['statusCode'])->toBe(503)
        ->and($response['body'])->toBe("status 503\n");
});

test('follows relative and absolute redirects', function (string $location): void {
    $response = localClient()->request('GET', TestHttpServer::url('/redirect?to='.urlencode($location)));

    expect($response['statusCode'])->toBe(200)
        ->and($response['body'])->toBe("hello world\n");
})->with([
    'absolute path' => ['/hello'],
    'relative path' => ['hello'],
    'absolute url' => [TestHttpServer::url('/hello')],
]);

test('does not follow redirects when disabled', function (): void {
    $response = localClient()->request('GET', TestHttpServer::url('/redirect?to=/hello'), followRedirects: false);

    expect($response['statusCode'])->toBe(302)
        ->and($response['headers']['location'])->toBe('/hello')
        ->and($response['body'])->toBe("redirecting\n");
});

test('a Location header on a non-redirect response is not followed', function (): void {
    $response = localClient()->request('POST', TestHttpServer::url('/created'));

    expect($response['statusCode'])->toBe(201)
        ->and($response['body'])->toBe("created\n");
});

test('redirects switch POST to GET like curl does', function (int $status, string $expectedMethod, string $expectedBody): void {
    $url = TestHttpServer::url('/redirect?status='.$status.'&to=/echo');
    $echo = json_decode(localClient()->request('POST', $url, body: 'data')['body'], true);

    expect($echo['method'])->toBe($expectedMethod)
        ->and($echo['body'])->toBe($expectedBody);
})->with([
    '301' => [301, 'GET', ''],
    '302' => [302, 'GET', ''],
    '303' => [303, 'GET', ''],
    '307 keeps the method and body' => [307, 'POST', 'data'],
    '308 keeps the method and body' => [308, 'POST', 'data'],
]);

test('redirect to a URL outside the allow-list is rejected before it is requested', function (): void {
    $client = new SecureHttpClient(new NetworkConfig(
        allowedUrlPrefixes: [TestHttpServer::url('/redirect')],
        denyPrivateRanges: false,
    ));
    [$path, $marker] = hitPath();
    $target = TestHttpServer::url($path);

    expect(fn (): array => $client->request('GET', TestHttpServer::url('/redirect?to='.urlencode($target))))
        ->toThrow(NetworkAccessDeniedException::class, 'Redirect to denied URL: '.$target);
    expect(file_exists($marker))->toBeFalse();
});

test('protocol-relative redirects are validated against the host curl would use', function (): void {
    $port = parse_url(TestHttpServer::url(), PHP_URL_PORT);
    $location = sprintf('//localhost:%d/hello', $port);

    expect(fn (): array => localClient()->request('GET', TestHttpServer::url('/redirect?to='.urlencode($location))))
        ->toThrow(NetworkAccessDeniedException::class, sprintf('Redirect to denied URL: http://localhost:%d/hello', $port));
});

test('redirect loops stop at maxRedirects with curl error 47', function (): void {
    expect(fn (): array => localClient(maxRedirects: 3)->request('GET', TestHttpServer::url('/loop')))
        ->toThrow(new RuntimeException('Maximum (3) redirects followed', 47));
});

test('bodies up to maxResponseSize are returned', function (): void {
    $response = localClient(maxResponseSize: 100)->request('GET', TestHttpServer::url('/large?size=100'));

    expect(strlen($response['body']))->toBe(100);
});

test('bodies larger than maxResponseSize are rejected', function (): void {
    expect(fn (): array => localClient(maxResponseSize: 100)->request('GET', TestHttpServer::url('/large?size=100000')))
        ->toThrow(ResponseTooLargeException::class, 'Response exceeded maximum size of 100 bytes');
});

test('transport errors carry the curl error number', function (): void {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $closedPort = parse_url('tcp://'.stream_socket_get_name($probe, false), PHP_URL_PORT);
    fclose($probe);

    $client = new SecureHttpClient(new NetworkConfig(denyPrivateRanges: false, dangerouslyAllowFullInternetAccess: true));

    try {
        $client->request('GET', sprintf('http://127.0.0.1:%d/', $closedPort));
        $this->fail('Expected a connection error');
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getCode())->toBe(CURLE_COULDNT_CONNECT)
            ->and($runtimeException->getMessage())->toContain('127.0.0.1');
    }
});

test('only http and https are allowed', function (): void {
    $client = new SecureHttpClient(new NetworkConfig(denyPrivateRanges: false, dangerouslyAllowFullInternetAccess: true));

    try {
        $client->request('GET', 'file:///etc/passwd');
        $this->fail('Expected the file protocol to be rejected');
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getCode())->toBe(CURLE_UNSUPPORTED_PROTOCOL)
            ->and($runtimeException->getMessage())->toContain('Protocol "file"');
    }
});

test('the request itself is validated against the allow-list', function (): void {
    expect(fn (): array => localClient()->request('GET', 'http://example.com/'))
        ->toThrow(NetworkAccessDeniedException::class, 'URL "http://example.com/" is not in the allowed URL prefixes');
});

function ssrfProtectedClient(): SecureHttpClient
{
    return new SecureHttpClient(new NetworkConfig(timeout: 5, dangerouslyAllowFullInternetAccess: true));
}

test('private addresses are denied without being contacted', function (string $url): void {
    [$path, $marker] = hitPath();
    $url = str_replace(['{port}', '{path}'], [(string) parse_url(TestHttpServer::url(), PHP_URL_PORT), $path], $url);

    expect(fn (): array => ssrfProtectedClient()->request('GET', $url))
        ->toThrow(NetworkAccessDeniedException::class, 'is denied (SSRF protection)');
    expect(file_exists($marker))->toBeFalse();
})->with([
    'IPv4 loopback' => ['http://127.0.0.1:{port}{path}'],
    'localhost' => ['http://localhost:{port}{path}'],
    'IPv6 loopback' => ['http://[::1]:{port}{path}'],
    'IPv4-mapped IPv6' => ['http://[::ffff:127.0.0.1]:{port}{path}'],
    'decimal IPv4 resolved by curl' => ['http://2130706433:{port}{path}'],
    'short IPv4 resolved by curl' => ['http://127.1:{port}{path}'],
    'trailing-dot hostname' => ['http://localhost.:{port}{path}'],
]);

test('proxy environment variables cannot route requests around the connected-IP check', function (): void {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $deadProxy = 'http://'.stream_socket_get_name($probe, false);
    fclose($probe);

    $saved = getenv('http_proxy');
    putenv('http_proxy='.$deadProxy);

    try {
        // Through the (unreachable) proxy this would fail with a connection error.
        expect(localClient()->request('GET', TestHttpServer::url('/hello'))['body'])->toBe("hello world\n");
    } finally {
        putenv($saved === false ? 'http_proxy' : 'http_proxy='.$saved);
    }
});

test('credentials are dropped when a redirect changes origin', function (): void {
    $port = parse_url(TestHttpServer::url(), PHP_URL_PORT);
    $client = new SecureHttpClient(new NetworkConfig(
        allowedUrlPrefixes: [TestHttpServer::url('/'), sprintf('http://localhost:%d/', $port)],
        denyPrivateRanges: false,
    ));
    $headers = ['Authorization' => 'Bearer secret', 'cookie' => 'id=1', 'Proxy-Authorization' => 'Basic x', 'X-Token' => 'abc'];

    $sameOrigin = json_decode($client->request('GET', TestHttpServer::url('/redirect?to=/echo'), $headers)['body'], true);
    $crossOrigin = json_decode($client->request('GET', TestHttpServer::url('/redirect?to='.urlencode(sprintf('http://localhost:%d/echo', $port))), $headers)['body'], true);

    expect($sameOrigin['headers'])->toMatchArray(['Authorization' => 'Bearer secret', 'cookie' => 'id=1', 'Proxy-Authorization' => 'Basic x'])
        ->and($crossOrigin['headers'])->not->toHaveKeys(['Authorization', 'cookie', 'Proxy-Authorization'])
        ->and($crossOrigin['headers']['X-Token'])->toBe('abc')
        ->and($crossOrigin['headers']['Host'])->toBe('localhost:'.$port);
});
