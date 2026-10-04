<?php

declare(strict_types=1);

use BashBox\Bash;

// Every expected value is bash 5.3's (`bash -c`, LC_ALL=en_US.UTF-8), with "bash: line N:" shortened to "bash:".
test('strings are measured and matched by UTF-8 character', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'length counts characters' => ["x='héllo wörld'; echo \${#x}; y='日本語'; echo \${#y}", "11\n3\n"],
    'array element length' => ["a=('héllo' 'ö'); echo \"\${#a[0]} \${#a[1]} \${#a[@]}\"", "5 1 2\n"],
    'positional length' => ["set -- 'é' 'ab'; echo \${#1} \${#2}", "1 2\n"],
    'substring by character' => ["x='héllo'; echo \"\${x:1:2}|\${x: -3}|\${x:0:1}|\${x:4}|\${x:(-4):2}\"", "él|llo|h|o|él\n"],
    'substring of wide characters' => ["x='日本語'; echo \"\${x:1} \${x: -1:1}\"", "本語 語\n"],
    'invalid UTF-8 counts bytes' => ["x=\$'\\xff\\xfe'; echo \${#x}; y=\$'a\\xffb'; echo \${#y} \"\${y:2}\"", "2\n3 b\n"],
    'patterns match characters' => ["x='héllo'; echo \"\${x#?}|\${x%??}|\${x/?/X}|\${x//[é]/E}|\${x/#h?/Y}\"", "éllo|hél|Xéllo|hEllo|Yllo\n"],
    'case and [[ ]] patterns' => ["x='héllo'; case \$x in h?llo) echo case;; esac; [[ \$x == h?llo ]] && echo glob; [[ \$x =~ ^h.llo\$ ]] && echo regex", "case\nglob\nregex\n"],
    'filename globbing by character' => ['touch é1 ab1; echo ?1', "é1\n"],
    'patterns on invalid UTF-8' => ["x=\$'\\xffa'; echo \"\${x#?}\"; case \$x in ?a) echo case;; esac", "a\ncase\n"],
]);
