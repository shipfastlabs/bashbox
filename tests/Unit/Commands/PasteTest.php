<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

test('paste', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bash = new Bash(new BashOptions(cwd: '/home/user', env: ['USER' => 'user']));
    $bash->writeFile('/home/user/a', "1\n2\n3\n");
    $bash->writeFile('/home/user/b', "x\ny\n");
    $bash->writeFile('/home/user/c', "p\nq\nr\ns");
    $bash->writeFile('/home/user/e', '');

    $bashExecResult = $bash->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'joins lines side by side' => ['paste a b', "1\tx\n2\ty\n3\t\n"],
    'shorter files leave empty fields' => ['paste b a c', "x\t1\tp\ny\t2\tq\n\t3\tr\n\t\ts\n"],
    'delimiter list cycles' => ['paste -d ,: a b c', "1,x:p\n2,y:q\n3,:r\n,:s\n"],
    'escapes in the delimiter list' => ["paste -d '\\t\\n' a b c", "1\tx\np\n2\ty\nq\n3\t\nr\n\t\ns\n"],
    'backslash-zero is no delimiter' => ["paste -d '\\0' a b", "1x\n2y\n3\n"],
    'empty delimiter list' => ["paste -d '' a b", "1x\n2y\n3\n"],
    'other escaped chars stand for themselves' => ["paste -d '\\x\\\\' a b c", "1xx\\p\n2xy\\q\n3x\\r\nx\\s\n"],
    'unescaped trailing backslash' => ["paste -d 'a\\' a b", '', "paste: delimiter list ends with an unescaped backslash: a\\\n", 1],
    'serial' => ['paste -s a b c', "1\t2\t3\nx\ty\np\tq\tr\ts\n"],
    'serial with delimiters' => ['paste -s -d ,- a c', "1,2-3\np,q-r,s\n"],
    'serial empty file' => ['paste -s e a', "\n1\t2\t3\n"],
    'stdin' => ["printf 'i\\nj\\n' | paste - a", "i\t1\nj\t2\n\t3\n"],
    'stdin twice takes turns' => ["printf '1\\n2\\n3\\n4\\n5\\n' | paste - -", "1\t2\n3\t4\n5\t\n"],
    'serial stdin twice' => ["printf '1\\n2\\n' | paste -s - -", "1\t2\n\n"],
    'same file twice' => ['paste a a', "1\t1\n2\t2\n3\t3\n"],
    'zero terminated' => ["printf 'a\\0b\\0' | paste -z - - | od -c", "0000000   a  \\t   b  \\0\n0000004\n"],
    'serial zero terminated' => ["printf 'a\\0b' | paste -sz - | od -c", "0000000   a  \\t   b  \\0\n0000004\n"],
    'missing file' => ['paste a nope', '', "paste: nope: No such file or directory\n", 1],
    'serial missing file' => ['paste -s nope a', "1\t2\t3\n", "paste: nope: No such file or directory\n", 1],
    'directory' => ['mkdir d; paste a d b', "1\t\tx\n2\t\ty\n3\t\t\n", "paste: d: Is a directory\n", 1],
    'serial directory' => ['mkdir d; paste -s d a', "\n1\t2\t3\n", "paste: d: Is a directory\n", 1],
    'empty files' => ['paste e e', ''],
    'last file empty' => ['paste a e', "1\t\n2\t\n3\t\n"],
    'bad option' => ['paste -q', '', "paste: invalid option -- 'q'\nTry 'paste --help' for more information.\n", 1],
    'no trailing newline' => ["printf 'a' | paste - b", "a\tx\n\ty\n"],
]);
