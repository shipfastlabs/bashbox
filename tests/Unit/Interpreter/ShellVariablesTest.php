<?php

declare(strict_types=1);

use BashBox\Bash;

// Every expected output below was checked against GNU bash 5.3 (`bash -c '<script>'`).

test('HOME, USER, PATH and IFS', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'HOME, USER, PATH and IFS are set, and IFS is not exported' => ['echo "${HOME+h}${USER+u}${PATH+p}${IFS+i}"; printenv IFS; echo $?; printenv HOME USER', "hupi\n1\n/home/user\nuser\n"],
    'BASH_SUBSHELL counts subshells, $(...) and <(...)' => ['echo $BASH_SUBSHELL; (echo $BASH_SUBSHELL; (echo $BASH_SUBSHELL)); echo $(echo $BASH_SUBSHELL); x=$( (echo $BASH_SUBSHELL) ); echo $x; cat <(echo $BASH_SUBSHELL)', "0\n1\n2\n1\n2\n1\n"],
    'unsetting them works like bash' => ["unset HOME USER PATH IFS; echo \"[\$HOME|\$USER|\$PATH|\$IFS]\" \"\${IFS-unset}\"; cd; echo \$?\nx=\"a b\tc\"; for w in \$x; do echo \"<\$w>\"; done; set -- p q; echo \"\$*\"\nIFS=; for w in \$x; do echo \"<\$w>\"; done; echo \"\$*\"", "[|||] unset\n1\n<a>\n<b>\n<c>\np q\n<a b\tc>\npq\n", "bash: cd: HOME not set\n"],
]);

test('PIPESTATUS', function (string $script, string $stdout): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, '', 0]);
})->with([
    'a pipeline sets one status per command' => ['true | false | true; echo "${PIPESTATUS[*]}" ${#PIPESTATUS[@]}; declare -p PIPESTATUS', "0 1 0 3\ndeclare -a PIPESTATUS=([0]=\"0\")\n"],
    'a single command and a negated one set it too' => ['false; echo ${PIPESTATUS[@]}; ! true; echo ${PIPESTATUS[@]}; f() { return 3; }; f; echo ${PIPESTATUS[@]}', "1\n0\n3\n"],
    'an assignment, ((...)), [[...]] and a subshell each set it' => ['false | true; x=$(false); echo ${PIPESTATUS[@]}; (( 0 )); echo ${PIPESTATUS[@]}; [[ a == a ]]; echo ${PIPESTATUS[@]}; (exit 3); echo ${PIPESTATUS[@]}', "1\n1\n0\n3\n"],
    'other compound commands and definitions leave the last one inside them' => ['false | true; { false | true; }; echo ${PIPESTATUS[@]}; false | true; case x in y) ;; esac; echo ${PIPESTATUS[@]}; false | true; f() { :; }; echo ${PIPESTATUS[@]}', "1 0\n1 0\n1 0\n"],
    'a substitution sees the status from before it' => ['false | true; echo $(echo ${PIPESTATUS[@]})', "1 0\n"],
    'pipefail changes $? but not PIPESTATUS, and the shell overwrites it even when readonly' => ['set -o pipefail; false | true; echo $? ${PIPESTATUS[@]}; PIPESTATUS=(9 9); echo ${PIPESTATUS[@]}; readonly PIPESTATUS; true | false; echo ${PIPESTATUS[@]}', "1 1 0\n0\n0 1\n"],
]);
