<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;
use BashBox\Network\NetworkConfig;
use BashBox\Tests\Fixtures\TestHttpServer;

/** A shell whose curl may reach the local test server ($BASE). */
function curlShell(int $maxResponseSize = 1024): Bash
{
    return new Bash(new BashOptions(
        env: ['BASE' => TestHttpServer::url()],
        network: new NetworkConfig(
            allowedUrlPrefixes: [TestHttpServer::url('/')],
            denyPrivateRanges: false,
            maxResponseSize: $maxResponseSize,
            maxRedirects: 3,
            timeout: 5,
        ),
    ));
}

test('prints the response body', function (): void {
    $bashExecResult = curlShell()->exec('curl $BASE/hello');

    expect($bashExecResult->stdout)->toBe("hello world\n")
        ->and($bashExecResult->stderr)->toBe('')
        ->and($bashExecResult->exitCode)->toBe(0);
});

test('output can be piped', function (): void {
    expect(curlShell()->exec('curl -s $BASE/hello | tr a-z A-Z')->stdout)->toBe("HELLO WORLD\n");
});

test('-i includes the status line and headers', function (): void {
    $stdout = curlShell()->exec('curl -i $BASE/hello')->stdout;

    expect($stdout)->toStartWith("HTTP/1.1 200\r\n")
        ->toContain("x-greeting: hi\r\n")
        ->toEndWith("\r\n\r\nhello world\n");
});

test('-I sends HEAD and prints only headers', function (): void {
    $stdout = curlShell()->exec('curl --head $BASE/echo')->stdout;

    expect($stdout)->toStartWith("HTTP/1.1 200\r\n")
        ->toContain("content-type: application/json\r\n")
        ->toEndWith("\r\n\r\n");
});

test('-d sends a POST with the data, joining repeated values with &', function (): void {
    $echo = json_decode(curlShell()->exec("curl -d 'a=1' --data b=2 \$BASE/echo")->stdout, true);

    expect($echo['method'])->toBe('POST')
        ->and($echo['body'])->toBe('a=1&b=2');
});

test('-X and -H set the method and request headers', function (string $command): void {
    $echo = json_decode(curlShell()->exec($command)->stdout, true);

    expect($echo['method'])->toBe('PATCH')
        ->and($echo['headers']['X-Token'])->toBe('abc')
        ->and($echo['body'])->toBe('x');
})->with([
    'separate values' => ["curl -X PATCH -H 'X-Token: abc' --data-raw x \$BASE/echo"],
    'long options' => ["curl --request PATCH --header 'X-Token:abc' -d x \$BASE/echo"],
    'attached value' => ["curl -XPATCH -H 'X-Token: abc' -dx \$BASE/echo"],
]);

test('headers without a colon are ignored', function (): void {
    $echo = json_decode(curlShell()->exec("curl -H 'NoColon' \$BASE/echo")->stdout, true);

    expect($echo['headers'])->not->toHaveKey('NoColon');
});

test('-X overrides the POST implied by -d', function (): void {
    $echo = json_decode(curlShell()->exec('curl -X GET -d q=1 $BASE/echo')->stdout, true);

    expect($echo['method'])->toBe('GET')
        ->and($echo['body'])->toBe('q=1');
});

test('redirects are only followed with -L', function (): void {
    $bash = curlShell();

    expect($bash->exec('curl "$BASE/redirect?to=/hello"')->stdout)->toBe("redirecting\n")
        ->and($bash->exec('curl -L "$BASE/redirect?to=/hello"')->stdout)->toBe("hello world\n")
        ->and($bash->exec('curl -fsSL "$BASE/redirect?to=/hello"')->stdout)->toBe("hello world\n");
});

test('-f fails with exit code 22 on HTTP errors', function (): void {
    $bashExecResult = curlShell()->exec('curl -f "$BASE/status?code=404"');

    expect($bashExecResult->stdout)->toBe('')
        ->and($bashExecResult->stderr)->toBe("curl: (22) The requested URL returned error: 404\n")
        ->and($bashExecResult->exitCode)->toBe(22);
});

test('without -f HTTP errors are printed and succeed', function (): void {
    $bashExecResult = curlShell()->exec('curl "$BASE/status?code=404"');

    expect($bashExecResult->stdout)->toBe("status 404\n")
        ->and($bashExecResult->exitCode)->toBe(0);
});

test('-s hides errors unless -S is given', function (): void {
    $bash = curlShell();

    $bashExecResult = $bash->exec('curl -fs "$BASE/status?code=500"');
    $showError = $bash->exec('curl -fsS "$BASE/status?code=500"');

    expect($bashExecResult->stderr)->toBe('')
        ->and($bashExecResult->exitCode)->toBe(22)
        ->and($showError->stderr)->toBe("curl: (22) The requested URL returned error: 500\n")
        ->and($showError->exitCode)->toBe(22);
});

test('-o writes the output to a file relative to the cwd', function (): void {
    $bash = curlShell();

    $bashExecResult = $bash->exec('cd /tmp && curl -o page.txt $BASE/hello');

    expect($bashExecResult->stdout)->toBe('')
        ->and($bashExecResult->exitCode)->toBe(0)
        ->and($bash->readFile('/tmp/page.txt'))->toBe("hello world\n");
});

test('URLs outside the allow-list are denied with exit code 6', function (): void {
    $bashExecResult = curlShell()->exec('curl http://example.com/');

    expect($bashExecResult->stderr)->toBe('curl: (6) Access denied: URL "http://example.com/" is not in the allowed URL prefixes. Set `dangerouslyAllowFullInternetAccess: true` to allow all URLs (security risk).'."\n")
        ->and($bashExecResult->exitCode)->toBe(6);
});

test('redirects outside the allow-list are denied with -L', function (): void {
    $bashExecResult = curlShell()->exec('curl -L "$BASE/redirect?to=http://example.com/"');

    expect($bashExecResult->stdout)->toBe('')
        ->and($bashExecResult->stderr)->toStartWith('curl: (6) Access denied: Redirect to denied URL: http://example.com/')
        ->and($bashExecResult->exitCode)->toBe(6);
});

test('private addresses are denied by default', function (): void {
    $bash = new Bash(new BashOptions(
        env: ['BASE' => TestHttpServer::url()],
        network: new NetworkConfig(dangerouslyAllowFullInternetAccess: true),
    ));

    $bashExecResult = $bash->exec('curl $BASE/hello');

    expect($bashExecResult->stdout)->toBe('')
        ->and($bashExecResult->stderr)->toContain('Access to private/internal IP "127.0.0.1"')
        ->and($bashExecResult->exitCode)->toBe(6);
});

test('responses over maxResponseSize fail with exit code 63', function (): void {
    $bashExecResult = curlShell(maxResponseSize: 10)->exec('curl "$BASE/large?size=11"');

    expect($bashExecResult->stdout)->toBe('')
        ->and($bashExecResult->stderr)->toBe("curl: (63) Response exceeded maximum size of 10 bytes\n")
        ->and($bashExecResult->exitCode)->toBe(63);
});

test('too many redirects fail with exit code 47', function (): void {
    $bashExecResult = curlShell()->exec('curl -L $BASE/loop');

    expect($bashExecResult->stderr)->toBe("curl: (47) Maximum (3) redirects followed\n")
        ->and($bashExecResult->exitCode)->toBe(47);
});

test('connection failures use the curl error code', function (): void {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $closedPort = parse_url('tcp://'.stream_socket_get_name($probe, false), PHP_URL_PORT);
    fclose($probe);

    $bash = new Bash(new BashOptions(network: new NetworkConfig(denyPrivateRanges: false, dangerouslyAllowFullInternetAccess: true)));
    $bashExecResult = $bash->exec(sprintf('curl http://127.0.0.1:%d/', $closedPort));

    expect($bashExecResult->stderr)->toStartWith('curl: (7) Failed to connect to 127.0.0.1')
        ->and($bashExecResult->exitCode)->toBe(7);
});

test('usage errors exit with code 2', function (string $command, string $stderr): void {
    $bashExecResult = curlShell()->exec($command);

    expect($bashExecResult->stderr)->toBe($stderr)
        ->and($bashExecResult->exitCode)->toBe(2);
})->with([
    'no URL' => ['curl -s', "curl: no URL specified\n"],
    'unknown option' => ['curl --bogus $BASE/hello', "curl: option --bogus: is unknown\n"],
    'missing option value' => ['curl $BASE/hello -H', "curl: option -H: requires parameter\n"],
]);

test('-o into a missing or non-directory parent fails with exit 23', function (string $target): void {
    $bashExecResult = curlShell()->exec('touch /tmp/file; curl -o '.$target.' $BASE/hello; echo "rc=$?"; curl -s -o '.$target.' $BASE/hello; echo "rc=$?"');

    expect($bashExecResult->stdout)->toBe("rc=23\nrc=23\n")
        ->and($bashExecResult->stderr)->toBe("curl: (23) Failure writing output to destination\n");
})->with(['missing directory' => ['/nodir/page.txt'], 'file as directory' => ['/tmp/file/page.txt']]);
