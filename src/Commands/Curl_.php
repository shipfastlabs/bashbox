<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use BashBox\Network\Exceptions\NetworkAccessDeniedException;
use BashBox\Network\Exceptions\ResponseTooLargeException;
use BashBox\Network\SecureHttpClient;
use RuntimeException;

final class Curl_ extends AbstractCommand
{
    private const array VALUE_OPTIONS = [
        '-X' => 'request', '--request' => 'request',
        '-H' => 'header', '--header' => 'header',
        '-d' => 'data', '--data' => 'data', '--data-raw' => 'data',
        '-o' => 'output', '--output' => 'output',
    ];

    private const array FLAG_OPTIONS = [
        '-s' => 'silent', '--silent' => 'silent',
        '-S' => 'show-error', '--show-error' => 'show-error',
        '-i' => 'include', '--include' => 'include',
        '-I' => 'head', '--head' => 'head',
        '-L' => 'location', '--location' => 'location',
        '-f' => 'fail', '--fail' => 'fail',
    ];

    public function getName(): string
    {
        return 'curl';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        if (! $commandContext->fetch instanceof SecureHttpClient) {
            return $this->failure("curl: network is not configured\n");
        }

        $options = $this->parseArgs($args);

        if (is_string($options)) {
            return $this->failure($options, 2);
        }

        $silent = isset($options['flags']['silent']) && ! isset($options['flags']['show-error']);
        $headOnly = isset($options['flags']['head']);
        $data = $options['data'];
        $method = $options['request'] ?? match (true) {
            $headOnly => 'HEAD',
            $data !== null => 'POST',
            default => 'GET',
        };

        try {
            $response = $commandContext->fetch->request(
                $method,
                $options['url'],
                $options['headers'],
                $data ?? '',
                isset($options['flags']['location']),
            );
        } catch (NetworkAccessDeniedException $e) {
            return $this->error(6, 'Access denied: '.$e->getMessage(), $silent);
        } catch (ResponseTooLargeException $e) {
            return $this->error(63, $e->getMessage(), $silent);
        } catch (RuntimeException $e) {
            return $this->error((int) $e->getCode(), $e->getMessage(), $silent);
        }

        if (isset($options['flags']['fail']) && $response['statusCode'] >= 400) {
            return $this->error(22, 'The requested URL returned error: '.$response['statusCode'], $silent);
        }

        $output = '';

        if (isset($options['flags']['include']) || $headOnly) {
            $output .= sprintf("HTTP/1.1 %d\r\n", $response['statusCode']);

            foreach ($response['headers'] as $name => $value) {
                $output .= sprintf("%s: %s\r\n", $name, $value);
            }

            $output .= "\r\n";
        }

        $output .= $response['body'];

        if ($options['output'] !== null) {
            try {
                $this->writeOutputFile($commandContext, $options['output'], $output);
            } catch (RuntimeException) {
                return $this->error(23, 'Failure writing output to destination', $silent);
            }

            return $this->success();
        }

        return $this->success($output);
    }

    private function error(int $code, string $message, bool $silent): ExecResult
    {
        return $this->failure($silent ? '' : sprintf("curl: (%d) %s\n", $code, $message), $code);
    }

    /**
     * Returns the parsed options, or an error message for invalid usage.
     *
     * @param  list<string>  $args
     * @return array{url: string, request: ?string, headers: array<string, string>, data: ?string, output: ?string, flags: array<string, true>}|string
     */
    private function parseArgs(array $args): array|string
    {
        $url = null;
        $request = null;
        $headers = [];
        $data = null;
        $output = null;
        $flags = [];

        // Expand bundled short options ("-fsSL", "-XPOST") into separate arguments.
        $expanded = [];

        foreach ($args as $arg) {
            if (strlen($arg) <= 2 || $arg[0] !== '-' || $arg[1] === '-') {
                $expanded[] = $arg;

                continue;
            }

            for ($j = 1; $j < strlen($arg); $j++) {
                $expanded[] = '-'.$arg[$j];

                if (isset(self::VALUE_OPTIONS['-'.$arg[$j]]) && $j + 1 < strlen($arg)) {
                    $expanded[] = substr($arg, $j + 1);

                    break;
                }
            }
        }

        $counter = count($expanded);

        for ($i = 0; $i < $counter; $i++) {
            $arg = $expanded[$i];

            if (isset(self::FLAG_OPTIONS[$arg])) {
                $flags[self::FLAG_OPTIONS[$arg]] = true;

                continue;
            }

            if (! isset(self::VALUE_OPTIONS[$arg])) {
                if (str_starts_with($arg, '-')) {
                    return sprintf("curl: option %s: is unknown\n", $arg);
                }

                $url ??= $arg;

                continue;
            }

            $value = $expanded[++$i] ?? null;

            if ($value === null) {
                return sprintf("curl: option %s: requires parameter\n", $arg);
            }

            switch (self::VALUE_OPTIONS[$arg]) {
                case 'request':
                    $request = $value;

                    break;

                case 'header':
                    if (str_contains($value, ':')) {
                        [$name, $headerValue] = explode(':', $value, 2);
                        $headers[trim($name)] = trim($headerValue);
                    }

                    break;

                case 'data':
                    // Like curl, repeated -d values are joined with "&".
                    $data = $data === null ? $value : $data.'&'.$value;

                    break;

                default:
                    $output = $value;
            }
        }

        if ($url === null) {
            return "curl: no URL specified\n";
        }

        return ['url' => $url, 'request' => $request, 'headers' => $headers, 'data' => $data, 'output' => $output, 'flags' => $flags];
    }
}
