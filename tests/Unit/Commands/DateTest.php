<?php

declare(strict_types=1);

use BashBox\Bash;

test('date', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'default format' => ['date -u -d @0', "Thu Jan  1 00:00:00 UTC 1970\n"],
    'combined -ud' => ['date -ud @86400; date -ud@86400 +%F', "Fri Jan  2 00:00:00 UTC 1970\n1970-01-02\n"],
    'strftime conversions' => [
        "date -u -d @1700000000 '+%F %T|%a %A %b %B %h|%d %e %j|%y %Y|%I %p|%u %w|%s|%z %Z|%D %R|%%|%q|x%ny%tz'",
        "2023-11-14 22:13:20|Tue Tuesday Nov November Nov|14 14 318|23 2023|10 PM|2 2|1700000000|+0000 UTC|11/14/23 22:13|%|%q|x\ny\tz\n",
    ],
    'parses date strings' => ["date -u -d '2024-02-29 13:05:09' '+%j %H:%M:%S'", "060 13:05:09\n"],
    'invalid date' => ['date -d nonsense', '', "date: invalid date 'nonsense'\n", 1],
]);

test('date defaults to the current time', function (): void {
    $bashExecResult = (new Bash)->exec('date -u; date -u +%s');
    [$date, $epoch] = explode("\n", $bashExecResult->stdout);

    expect($date)->toMatch('/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun) (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) [ \d]\d \d\d:\d\d:\d\d UTC \d{4}$/')
        ->and((int) $epoch)->toBeGreaterThanOrEqual(time() - 5)->toBeLessThanOrEqual(time());
});
