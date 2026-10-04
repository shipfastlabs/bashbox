<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions(cwd: '/home/user'));
    $this->bash->writeFile('/home/user/file.txt', 'x');
    $this->bash->writeFile('/home/user/empty', '');
    $this->bash->writeFile('/home/user/run.sh', 'echo');
    $this->bash->writeFile('/home/user/locked', 'secret');
    $this->bash->exec('mkdir /home/user/dir');
    $this->bash->getFilesystem()->chmod('/home/user/run.sh', 0755);
    $this->bash->getFilesystem()->chmod('/home/user/locked', 0);
    $this->bash->getFilesystem()->symlink('missing', '/home/user/dangling');
});

test('test evaluates expressions like bash', function (string $expression, int $exitCode): void {
    $result = $this->bash->exec('test '.$expression);

    expect($result->stderr)->toBe('')
        ->and($result->exitCode)->toBe($exitCode);
})->with([
    'no arguments' => ['', 1],
    'non-empty string' => ['x', 0],
    'empty string' => ["''", 1],
    'lone operator is a string' => ['-n', 0],
    'negated string' => ['! x', 1],
    'negated empty string' => ["! ''", 0],
    '-z empty' => ["-z ''", 0],
    '-z non-empty' => ['-z x', 1],
    '-n empty' => ["-n ''", 1],
    'string equal' => ['a = a', 0],
    'string == differs' => ['a == b', 1],
    'string not equal' => ['a != b', 0],
    '-eq with leading zero and blanks' => ["01 -eq ' 1 '", 0],
    '-ne' => ['1 -ne 1', 1],
    '-lt negative' => ['-3 -lt 2', 0],
    '-le' => ['2 -le 2', 0],
    '-gt' => ['3 -gt 2', 0],
    '-ge' => ['2 -ge 3', 1],
    'three-arg -a' => ["x -a ''", 1],
    'three-arg -o' => ["x -o ''", 0],
    'binary operator wins over unary' => ['-n = x', 1],
    'three-arg negation' => ["! -n ''", 0],
    'three-arg parentheses' => ["\\( '' \\)", 1],
    'four-arg negation' => ['! a = b', 0],
    'four-arg parentheses' => ['\\( -n x \\)', 0],
    'and binds tighter than or' => ['-z a -o -n b -a -n c', 0],
    'and of two tests' => ['-n a -a -z b', 1],
    'negation in a long expression' => ["! -n a -o -z ''", 0],
    'grouping' => ['\\( -z a -o -n b \\) -a 1 -eq 1', 0],
    'unary operator in a long expression' => ['x -a ! -z a -a y', 0],
    '-e file' => ['-e file.txt', 0],
    '-e missing' => ['-e nope', 1],
    '-e dangling symlink' => ['-e dangling', 1],
    '-f file' => ['-f file.txt', 0],
    '-f directory' => ['-f dir', 1],
    '-d directory' => ['-d /home/user/dir', 0],
    '-d file' => ['-d file.txt', 1],
    '-s non-empty' => ['-s file.txt', 0],
    '-s empty' => ['-s empty', 1],
    '-r readable' => ['-r file.txt', 0],
    '-r mode 000' => ['-r locked', 1],
    '-w writable' => ['-w file.txt', 0],
    '-w mode 000' => ['-w locked', 1],
    '-x executable' => ['-x run.sh', 0],
    '-x plain file' => ['-x file.txt', 1],
]);

test('test reports syntax errors with exit code 2', function (string $expression, string $stderr): void {
    $result = $this->bash->exec('test '.$expression);

    expect($result->stderr)->toBe(sprintf('bash: test: %s%s', $stderr, PHP_EOL))
        ->and($result->exitCode)->toBe(2);
})->with([
    'two plain words' => ['a b', 'a: unary operator expected'],
    'unknown binary operator' => ['a -foo b', '-foo: binary operator expected'],
    'non-integer' => ['abc -eq 1', 'abc: integer expected'],
    'decimal' => ['1.5 -lt 2', '1.5: integer expected'],
    'trailing argument' => ['a = b c', 'too many arguments'],
    'unclosed parenthesis' => ['\\( a = a', "`)' expected"],
    'three args without an operator' => ['-n x -a', 'x: binary operator expected'],
    'missing operand' => ['x -a y -a', 'argument expected'],
]);

test('[ requires a closing bracket', function (string $script, string $stderr, int $exitCode): void {
    $result = $this->bash->exec($script);

    expect($result->stderr)->toBe($stderr)
        ->and($result->exitCode)->toBe($exitCode);
})->with([
    'file test' => ['[ -f file.txt ]', '', 0],
    'empty brackets' => ['[ ]', '', 1],
    'bracket as the only operand' => ['[ ] ]', '', 0],
    'missing ]' => ['[ -f file.txt', "bash: [: missing `]'\n", 2],
    'syntax error names [' => ['[ a b ]', "bash: [: a: unary operator expected\n", 2],
    'test does not strip ]' => ['test x ]', "bash: test: x: unary operator expected\n", 2],
]);

// Results from bash 5.3's test builtin on the same tree (-g/-G as on Linux, where the sandbox user owns every file and group)
test('test file-type and mode operators', function (string $expr, int $status): void {
    $result = $this->bash->exec('echo x > f; mkdir d; ln -s f lnk; touch su sg st; chmod 4755 su; chmod 2755 sg; chmod 1777 st; test '.$expr);

    expect([$result->stderr, $result->exitCode])->toBe(['', $status]);
})->with([
    '-L on a symlink' => ['-L lnk', 0],
    '-h on a symlink' => ['-h lnk', 0],
    '-L on a regular file' => ['-L f', 1],
    '-L on a dangling symlink' => ['-L dangling', 0],
    '-e on a dangling symlink' => ['-e dangling', 1],
    '-L on a missing path' => ['-L nope', 1],
    '-a as a unary test' => ['-a f', 0],
    '-a on a missing path' => ['-a nope', 1],
    '-u setuid' => ['-u su', 0],
    '-u without setuid' => ['-u f', 1],
    '-g setgid' => ['-g sg', 0],
    '-g without setgid' => ['-g f', 1],
    '-k sticky' => ['-k st', 0],
    '-k without sticky' => ['-k f', 1],
    '-O owned' => ['-O f', 0],
    '-G group-owned' => ['-G d', 0],
    '-O on a missing path' => ['-O nope', 1],
    '-b block device' => ['-b f', 1],
    '-c character device' => ['-c f', 1],
    '-p named pipe' => ['-p f', 1],
    '-S socket' => ['-S f', 1],
    '-t terminal' => ['-t 1', 1],
    'negated -L' => ['! -L f', 0],
    'binary -a' => ['f -a lnk', 0],
    '-L combined with -a' => ['-L lnk -a -f f', 0],
]);

test('test binary operators', function (string $script, string $stdout, string $stderr = ''): void {
    $bash = new Bash(new BashOptions(cwd: '/home/user'));
    $bash->writeFile('/home/user/f', 'x');
    $bash->writeFile('/home/user/g', 'y');
    $bash->getFilesystem()->utimes('/home/user/f', 1000);
    $bash->getFilesystem()->utimes('/home/user/g', 2000);

    $bashExecResult = $bash->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, 0]);
})->with([
    'strings, times, files and integers' => ['for op in = == != "<" ">" -nt -ot -ef -eq -ne -lt -le -gt -ge; do test f "$op" g; printf "%s " $?; test 2 "$op" 2; printf "%s\\n" $?; done', "1 0\n1 0\n0 1\n0 1\n1 1\n1 1\n0 1\n1 1\n2 0\n2 1\n2 1\n2 0\n2 1\n2 0\n", str_repeat("bash: test: f: integer expected\n", 6)],
    'a missing file is older than any other, and the same as none' => ['test f -nt nope; echo $?; test nope -ot f; echo $?; test f -ef nope; echo $?; test f -ef ./f; echo $?', "0\n0\n1\n0\n"],
]);
