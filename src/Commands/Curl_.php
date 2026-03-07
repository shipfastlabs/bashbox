<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use BashBox\Network\Exceptions\NetworkAccessDeniedException;
use BashBox\Network\Exceptions\ResponseTooLargeException;
use RuntimeException;

final class Curl_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'curl';
    }

    public function execute(array $args, CommandContext $ctx): ExecResult
    {
        if ($ctx->fetch === null) {
            return $this->failure("curl: network is not configured\n");
        }

        $parsed = $this->parseArgs($args);

        if ($parsed === null) {
            return $this->failure("curl: no URL specified\n");
        }

        $method = strtoupper($parsed['method']);
        $url = $parsed['url'];
        $headers = $parsed['headers'];
        $body = $parsed['body'];
        $silent = $parsed['silent'];
        $showHeaders = $parsed['showHeaders'];
        $outputFile = $parsed['output'];
        $headOnly = $parsed['headOnly'];

        if ($headOnly) {
            $method = 'HEAD';
        }

        try {
            $response = $ctx->fetch->request($method, $url, $headers, $body);
        } catch (NetworkAccessDeniedException $e) {
            return $this->failure(sprintf("curl: (6) Access denied: %s\n", $e->getMessage()));
        } catch (ResponseTooLargeException $e) {
            return $this->failure(sprintf("curl: (63) %s\n", $e->getMessage()));
        } catch (RuntimeException $e) {
            return $this->failure(sprintf("curl: (7) %s\n", $e->getMessage()));
        }

        $output = '';

        if ($showHeaders || $headOnly) {
            $output .= sprintf("HTTP/1.1 %d\r\n", $response['statusCode']);

            foreach ($response['headers'] as $name => $value) {
                $output .= sprintf("%s: %s\r\n", $name, $value);
            }
            $output .= "\r\n";
        }

        if (! $headOnly) {
            $output .= $response['body'];
        }

        if ($outputFile !== null) {
            $path = $this->resolvePath($ctx, $outputFile);
            $ctx->fs->writeFile($path, $output);

            if (! $silent) {
                return $this->success();
            }

            return $this->success();
        }

        return $this->success($output);
    }

    /**
     * @param  list<string>  $args
     * @return array{method: string, url: string, headers: array<string, string>, body: string, silent: bool, showHeaders: bool, output: string|null, headOnly: bool}|null
     */
    private function parseArgs(array $args): ?array
    {
        $method = 'GET';
        $url = null;
        $headers = [];
        $body = '';
        $silent = false;
        $showHeaders = false;
        $output = null;
        $headOnly = false;

        $i = 0;

        while ($i < count($args)) {
            $arg = $args[$i];

            switch ($arg) {
                case '-X':
                case '--request':
                    $i++;
                    $method = $args[$i] ?? 'GET';

                    break;

                case '-H':
                case '--header':
                    $i++;
                    $headerLine = $args[$i] ?? '';
                    $colonPos = strpos($headerLine, ':');

                    if ($colonPos !== false) {
                        $name = trim(substr($headerLine, 0, $colonPos));
                        $value = trim(substr($headerLine, $colonPos + 1));
                        $headers[$name] = $value;
                    }

                    break;

                case '-d':
                case '--data':
                case '--data-raw':
                    $i++;
                    $body = $args[$i] ?? '';

                    if ($method === 'GET') {
                        $method = 'POST';
                    }

                    break;

                case '-s':
                case '--silent':
                    $silent = true;

                    break;

                case '-i':
                case '--include':
                    $showHeaders = true;

                    break;

                case '-I':
                case '--head':
                    $headOnly = true;

                    break;

                case '-o':
                case '--output':
                    $i++;
                    $output = $args[$i] ?? null;

                    break;

                case '-L':
                case '--location':
                    // Redirects are handled by SecureHttpClient
                    break;

                case '-f':
                case '--fail':
                    // Fail silently handled by checking status code
                    break;

                default:
                    if (! str_starts_with($arg, '-') && $url === null) {
                        $url = $arg;
                    }

                    break;
            }

            $i++;
        }

        if ($url === null) {
            return null;
        }

        return [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'silent' => $silent,
            'showHeaders' => $showHeaders,
            'output' => $output,
            'headOnly' => $headOnly,
        ];
    }
}
