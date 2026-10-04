<?php

declare(strict_types=1);

use BashBox\Bash;

// read assigns names left to right and stops at one that is not an identifier or array element.
// Every expected value is bash 5.3's (`bash -c`, LC_ALL=en_US.UTF-8), with "bash: line N:" shortened to "bash:".
test('read checks its names like bash', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'an option after a name is an invalid identifier' => ["read a -r <<< 'x y'; echo \"\$? [\$a]\"", "1 [x]\n", "bash: read: `-r': not a valid identifier\n"],
    'names before a bad one are assigned' => ["read a 1x b <<< 'x y z'; echo \"\$? [\$a] [\$b]\"", "1 [x] []\n", "bash: read: `1x': not a valid identifier\n"],
    'a bad name is reported at end of input too' => ['read a -r < /dev/null; echo $?', "1\n", "bash: read: `-r': not a valid identifier\n"],
    'an array element is a valid name' => ["read 'a[1]' b <<< 'x y'; echo \"\$? [\${a[1]}] [\$b]\"", "0 [x] [y]\n"],
    'read -a needs a plain name' => ["read -a 1x <<< 'x'; echo \$?; read -a 'a[1]' <<< 'x'; echo \$?", "1\n1\n", "bash: read: `1x': not a valid identifier\nbash: read: `a[1]': not a valid identifier\n"],
    'read -N with a bad name' => ["read -N 2 a 1x <<< 'xyz'; echo \"\$? [\$a]\"", "1 [xy]\n", "bash: read: `1x': not a valid identifier\n"],
    'read -N into an array' => ["read -N 3 -a arr <<< 'x y'; echo \"\$? [\${arr[0]}] \${#arr[@]}\"", "0 [x y] 1\n"],
    'read -N -a with a bad name' => ["read -N 1 -a 1x <<< 'x'; echo \$?", "1\n", "bash: read: `1x': not a valid identifier\n"],
]);
