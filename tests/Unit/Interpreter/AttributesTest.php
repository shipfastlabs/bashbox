<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

// Expected values come from GNU bash 5.3 (`bash -c`, HOME/USER/PATH as BashBox's defaults), with "bash: line 1:" shortened to "bash:".

function runAttributes(string $script): array
{
    $bashExecResult = new Bash(new BashOptions(env: ['HOME' => '/home/user']))->exec($script);

    return [$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode];
}

test('only exported variables reach commands, and declare shows the attributes', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    expect(runAttributes($script))->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'a plain assignment is not exported' => ['FOO=1; printenv FOO; echo $?', "1\n"],
    'export marks the name, so later values reach commands' => ['export X=1; X=2; printenv X; export Y; Y=3; printenv Y', "2\n3\n"],
    'a local inherits the export attribute' => ['export x=g; f(){ local x=1; printenv x; }; f', "1\n"],
    'local -x exports only the local' => ['x=g; f(){ local -x x=1; printenv x; }; f; printenv x; echo $?', "1\n1\n"],
    'unset drops the export attribute' => ['export x=1; unset x; x=2; printenv x; echo $?', "1\n"],
    'an exported name without a value is listed bare' => ['export Z; export -p | grep Z; Z=1; printenv Z', "declare -x Z\n1\n"],
    'export -n un-exports' => ['export A=1; export -n A; printenv A; echo $? $A', "1 1\n"],
    'declare -p shows r and x' => ["declare -rx R=1; declare -p R; declare -x S; declare -p S; export -p | grep -w '[RS]'", "declare -rx R=\"1\"\ndeclare -x S\ndeclare -rx R=\"1\"\ndeclare -x S\n"],
    'declare +x un-exports' => ['declare -x A=1; declare +x A; printenv A; echo $?', "1\n"],
    'set -a exports every assignment while on' => ['set -a; B=2; printenv B; echo $-; set +a; C=3; printenv C; echo $?', "2\nahBc\n1\n"],
    'allexport covers locals and read, not arrays' => ['set -o allexport; f(){ local l=3; declare -p l; }; f; read r <<< hi; declare -p r; arr=(1); declare -p arr', "declare -x l=\"3\"\ndeclare -x r=\"hi\"\ndeclare -a arr=([0]=\"1\")\n"],
    'prefix assignments are exported for one command' => ['FOO=1 printenv FOO; printenv FOO; echo $?; f(){ printenv V; }; V=7 f; printenv V; echo $?', "1\n1\n7\n1\n"],
    'a prefix assignment to an exported variable restores its value' => ['export V=0; V=1 printenv V; printenv V', "1\n0\n"],
    'arrays print with a or A, an associative one with a trailing space' => ['declare -a arr=(1 2); export arr; declare -p arr; declare -A m=([k]=v); declare -p m', "declare -ax arr=([0]=\"1\" [1]=\"2\")\ndeclare -A m=([k]=\"v\" )\n"],
    'a local hides -A until the function returns' => ['declare -A m=([a]=1); f(){ local m; declare -p m; local -A n; n[x]=1; declare -p n; }; f; declare -p m', "declare -- m\ndeclare -A n=([x]=\"1\" )\ndeclare -A m=([a]=\"1\" )\n"],
    'declare rejects names that are not identifiers, and options after the names' => ['declare 1x=2 y=3 -z; echo $? $y', "1 3\n", "bash: declare: `1x=2': not a valid identifier\nbash: declare: `-z': not a valid identifier\n"],
    'local rejects names that are not identifiers' => ['f(){ local a-b=1 c=2; echo $? $c; }; f', "1 2\n", "bash: local: `a-b=1': not a valid identifier\n"],
    'readonly rejects names that are not identifiers' => ["readonly 9=1 r=1; echo \$? \$r; readonly | grep ' r='", "1 1\ndeclare -r r=\"1\"\n", "bash: readonly: `9=1': not a valid identifier\n"],
    'export rejects names that are not identifiers' => ['export a.b x=1; echo $?; printenv x', "1\n1\n", "bash: export: `a.b': not a valid identifier\n"],
    'export rejects unknown options' => ['export -z; echo $?', "2\n", "bash: export: -z: invalid option\nexport: usage: export [-fn] [name[=value] ...] or export -p [-f]\n"],
    'declare assigns an array element' => ['declare a[1]=x; declare -p a', "declare -a a=([1]=\"x\")\n"],
    "readonly -p shows each variable's attributes" => ["readonly U; export V; readonly V; readonly -p | grep -w '[UV]'", "declare -r U\ndeclare -rx V\n"],
    'declare -p escapes quotes, dollars, backquotes and backslashes' => ["x='a\"b\$c`d\\e'; declare -p x; a=(\"q\\\"\"); declare -p a", "declare -- x=\"a\\\"b\\\$c\\`d\\\\e\"\ndeclare -a a=([0]=\"q\\\"\")\n"],
    'export -f only checks that the functions exist' => ['f(){ :; }; export -f f; echo $?; export -f nosuch; echo $?', "0\n1\n", "bash: export: nosuch: not a function\n"],
    'declare -x and declare -r list like export and readonly' => ['export HOME; declare -x | grep HOME; declare -r Q=1; declare -r | grep Q', "declare -x HOME=\"/home/user\"\ndeclare -r Q=\"1\"\n"],
    'env sees only exported variables' => ['env | grep -c FOO; FOO=1 env | grep FOO; export E=1; (E=2; printenv E); printenv E; x=$(printenv E); echo $x', "0\nFOO=1\n2\n1\n1\n"],
    'options end at --' => ['export -- G=1; printenv G', "1\n"],
]);

test('set applies options like bash', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    expect(runAttributes($script))->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'o in a cluster takes the next argument as its option name' => ['set -euo pipefail; echo "[${1-}]" $-; shopt -po pipefail', "[] ehuBc\nset -o pipefail\n"],
    'pipefail from a cluster takes effect' => ['set -eo pipefail; echo "[${1-}]"; false | true; echo $?', "[]\n", '', 1],
    'several -o options' => ['set -o errexit -o nounset; echo $-; set +o nounset; echo $-', "ehuBc\nehBc\n"],
    'o at the end of a cluster lists the options after applying the others' => ["set -euo | grep -E '^(errexit|nounset|xtrace) '", "errexit        \ton\nnounset        \ton\nxtrace         \toff\n"],
    '+o at the end prints them as commands' => ["set -e +o | grep -E 'errexit|pipefail'", "set -o errexit\nset +o pipefail\n"],
    'an unknown option name changes nothing' => ['set -o bogus -u; echo $? $-', "2 hBc\n", "bash: set: bogus: invalid option name\n"],
    'an unknown letter changes nothing' => ['set -ez; echo $? $-', "2 hBc\n", "bash: set: -z: invalid option\nset: usage: set [-abefhkmnptuvxBCEHPT] [-o option-name] [--] [-] [arg ...]\n"],
    'a bad option inside -- is reported as -' => ['set --bogus; echo $?', "2\n", "bash: set: --: invalid option\nset: usage: set [-abefhkmnptuvxBCEHPT] [-o option-name] [--] [-] [arg ...]\n"],
    'letters with no effect here are accepted' => ['set -hkB; echo $?', "0\n"],
    'words after the options become positional parameters' => ['set -e a b; echo "$@"; set -ex -- c d; echo "$@"', "a b\nc d\n", "+ echo c d\n"],
    '-- with nothing after it clears them' => ['set -- a b; set --; echo $#', "0\n"],
    'a lone - keeps them' => ['set -- a b; set -; echo $# "$@"; set - c; echo "$@"', "2 a b\nc\n"],
]);
