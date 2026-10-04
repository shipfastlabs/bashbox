<?php

declare(strict_types=1);

use BashBox\Bash;

// Every expected output below was checked against GNU bash 5.3 (`bash -c '<script>'`).
// The first case shows BashBox's defaults, which stand in for the host's environment.

test('HOME, USER, PATH and IFS', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'HOME, USER, PATH and IFS are set, and IFS is not exported' => ['echo "${HOME+h}${USER+u}${PATH+p}${IFS+i}"; printenv IFS; echo $?; printenv HOME USER', "hupi\n1\n/home/user\nuser\n"],
    'BASH_SUBSHELL counts subshells, $(...) and <(...)' => ['echo $BASH_SUBSHELL; (echo $BASH_SUBSHELL; (echo $BASH_SUBSHELL)); echo $(echo $BASH_SUBSHELL); x=$( (echo $BASH_SUBSHELL) ); echo $x; cat <(echo $BASH_SUBSHELL)', "0\n1\n2\n1\n2\n1\n"],
    'unsetting them works like bash' => ["unset HOME USER PATH IFS; echo \"[\$HOME|\$USER|\$PATH|\$IFS]\" \"\${IFS-unset}\"; cd; echo \$?\nx=\"a b\tc\"; for w in \$x; do echo \"<\$w>\"; done; set -- p q; echo \"\$*\"\nIFS=; for w in \$x; do echo \"<\$w>\"; done; echo \"\$*\"", "[|||] unset\n1\n<a>\n<b>\n<c>\np q\n<a b\tc>\npq\n", "bash: cd: HOME not set\n"],
]);
