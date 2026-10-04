<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

test('du', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bash = new Bash(new BashOptions(cwd: '/home/user', env: ['USER' => 'user']));
    $bash->writeFile('/home/user/d/a', str_repeat('x', 4096));
    $bash->writeFile('/home/user/d/s/b', str_repeat('x', 12288));
    $bash->writeFile('/home/user/d/s/t/c', '');
    $bash->writeFile('/home/user/e/f', str_repeat('x', 12288000));
    $bash->exec('ln -s a d/l; mkdir d/s/u');

    $bashExecResult = $bash->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'directories, deepest first' => ['du d', "0\td/s/t\n0\td/s/u\n12\td/s\n16\td\n"],
    'current directory by default' => ['cd d && du', "0\t./s/t\n0\t./s/u\n12\t./s\n16\t.\n"],
    'trailing slash' => ['du d/', "0\td/s/t\n0\td/s/u\n12\td/s\n16\td/\n"],
    'all entries' => ['du -a d', "4\td/a\n0\td/l\n12\td/s/b\n0\td/s/t/c\n0\td/s/t\n0\td/s/u\n12\td/s\n16\td\n"],
    'summarize' => ['du -s d e', "16\td\n12000\te\n"],
    'total' => ['du -sc d e', "16\td\n12000\te\n12016\ttotal\n"],
    'max depth' => ['du -d 1 d', "12\td/s\n16\td\n"],
    'max depth long' => ['du --max-depth=1 -a d', "4\td/a\n0\td/l\n12\td/s\n16\td\n"],
    'max depth zero' => ['du --max-depth 0 d', "16\td\n"],
    'negative max depth shows the operand' => ['du -d -1 d', "16\td\n"],
    'octal max depth' => ['du -d 01 d', "12\td/s\n16\td\n"],
    'human' => ['du -h d e', "0\td/s/t\n0\td/s/u\n12K\td/s\n16K\td\n12M\te\n"],
    'human all' => ['du -ah d', "4.0K\td/a\n0\td/l\n12K\td/s/b\n0\td/s/t/c\n0\td/s/t\n0\td/s/u\n12K\td/s\n16K\td\n"],
    'k after h' => ['du -hk d', "0\td/s/t\n0\td/s/u\n12\td/s\n16\td\n"],
    'h after k' => ['du -kh -s e', "12M\te\n"],
    'apparent bytes' => ['du -b d', "0\td/s/t\n0\td/s/u\n12288\td/s\n16385\td\n"],
    'apparent size in K' => ['du -bk d', "0\td/s/t\n0\td/s/u\n12\td/s\n17\td\n"],
    'apparent human' => ['du -bh -s e', "12M\te\n"],
    'apparent-size' => ['du --apparent-size -a d/s', "12\td/s/b\n0\td/s/t/c\n0\td/s/t\n0\td/s/u\n12\td/s\n"],
    'a file operand' => ['du d/a', "4\td/a\n"],
    'a symlink operand is not followed' => ['du d/l', "0\td/l\n"],
    'repeated operand counted once' => ['du d d', "0\td/s/t\n0\td/s/u\n12\td/s\n16\td\n"],
    'nested operand counted once' => ['du -c d d/a', "0\td/s/t\n0\td/s/u\n12\td/s\n16\td\n16\ttotal\n"],
    'missing operand' => ['du nope d/a', "4\td/a\n", "du: cannot access 'nope': No such file or directory\n", 1],
    'summarize and all' => ['du -sa d', '', "du: cannot both summarize and show all entries\nTry 'du --help' for more information.\n", 1],
    'summarize and max depth' => ['du -s -d 1 d', '', "du: warning: summarizing conflicts with --max-depth=1\nTry 'du --help' for more information.\n", 1],
    'summarize and max depth 0' => ['du -s -d 0 d', "16\td\n", "du: warning: summarizing is the same as using --max-depth=0\n"],
    'invalid max depth' => ['du -d x d', '', "du: invalid maximum depth 'x'\nTry 'du --help' for more information.\n", 1],
    'huge max depth' => ['du -d 99999999999999999999 d', '', "du: invalid maximum depth '99999999999999999999'\nTry 'du --help' for more information.\n", 1],
    'bad option' => ['du -q', '', "du: invalid option -- 'q'\nTry 'du --help' for more information.\n", 1],
    'human rounding' => ["for n in 1 1023 1024 1025 1536 10239 10240 10241 1047552 1047553 1048575 1048576 1048577 5000000; do printf \"%\${n}s\" '' > f; du -bh f; done", "1\tf\n1023\tf\n1.0K\tf\n1.1K\tf\n1.5K\tf\n10K\tf\n10K\tf\n11K\tf\n1023K\tf\n1.0M\tf\n1.0M\tf\n1.0M\tf\n1.1M\tf\n4.8M\tf\n"],
    'empty directory' => ['mkdir -p r; du -a r', "0\tr\n"],
    'hard links counted once' => ['ln d/a d/s/h; du -a d', "4\td/a\n0\td/l\n12\td/s/b\n0\td/s/t/c\n0\td/s/t\n0\td/s/u\n12\td/s\n16\td\n"],
    'model: a small file takes a whole 1K block' => ['printf x > f; du f; du -b f', "1\tf\n1\tf\n"],
    'absolute root' => ['du -s /home/user/d/s', "12\t/home/user/d/s\n"],
]);
