<?php

declare(strict_types=1);

use BashBox\Bash;

// Every expected output below was checked against GNU bash 5.3 (`bash -c '<script>'`).

test('pathname expansion options', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'nullglob drops a pattern that matches nothing' => ["mkdir -p d/e; touch a.txt b.txt .hid C.TXT d/x.txt d/e/y.txt\nshopt -s nullglob; echo a *.nope b; x=(*.nope); echo \${#x[@]}; echo \"*.nope\"", "a b\n0\n*.nope\n"],
    'failglob makes it an error that ends the line' => ["touch a.txt\nshopt -s failglob; echo *.nope; echo same\necho next; for f in *.zz; do echo in; done; echo after\necho \$(echo *.nope2)fin; echo \"s=\$?\"\necho *.txt; f() { echo in; echo *.q; echo notreached; }; f; echo \$?\necho s=\$?\nshopt -s nullglob; echo *.q; echo s=\$?", "next\nfin\ns=0\na.txt\nin\ns=1\n", "bash: no match: *.nope\nbash: no match: *.zz\nbash: no match: *.nope2\nbash: no match: *.q\nbash: no match: *.q\n", 1],
    'dotglob lets * match dotfiles' => ["mkdir -p d/e; touch a.txt b.txt .hid C.TXT d/x.txt d/e/y.txt\nshopt -s dotglob; echo *; echo .*; shopt -u dotglob; echo *; echo .*", ".hid C.TXT a.txt b.txt d\n.hid\nC.TXT a.txt b.txt d\n.hid\n"],
    'nocaseglob' => ["mkdir -p d/e; touch a.txt b.txt .hid C.TXT d/x.txt d/e/y.txt\nshopt -s nocaseglob; echo *.txt; echo [a-c]*; echo c.txt", "C.TXT a.txt b.txt\nC.TXT a.txt b.txt\nc.txt\n"],
    'globstar makes ** recurse' => ["mkdir -p d/e; touch a.txt b.txt .hid C.TXT d/x.txt d/e/y.txt\nshopt -s globstar; echo **; echo **/*.txt; echo d/**; echo d/**/; shopt -u globstar; echo **/*.txt", "C.TXT a.txt b.txt d d/e d/e/y.txt d/x.txt\na.txt b.txt d/e/y.txt d/x.txt\nd/ d/e d/e/y.txt d/x.txt\nd/ d/e/\nd/x.txt\n"],
    'a literal last part must exist' => ["mkdir -p d/e; touch a.txt b.txt .hid C.TXT d/x.txt d/e/y.txt\necho */x.txt */nope.txt; echo d/*/; echo \"*\".txt \\*.txt", "d/x.txt */nope.txt\nd/e/\n*.txt *.txt\n"],
    'extglob patterns in pathnames' => ["mkdir -p d/e; touch a.txt b.txt .hid C.TXT d/x.txt d/e/y.txt\nshopt -s extglob\necho !(*.txt); echo @(a|b).txt; echo *(a).txt; echo +([a-c]).txt; echo ?(a).txt; echo !(d|*.txt|C*)\necho \"@(a)\".txt; x='@(a|b).txt'; echo \$x; echo \"\$x\"; echo @(nomatch)", "C.TXT d\na.txt b.txt\na.txt\na.txt b.txt\na.txt\n!(d|*.txt|C*)\n@(a).txt\na.txt b.txt\n@(a|b).txt\n@(nomatch)\n"],
    'without extglob the parentheses are literal' => ["mkdir -p d/e; touch a.txt b.txt .hid C.TXT d/x.txt d/e/y.txt\nx='@(a).txt'; echo \$x\nshopt -s extglob\necho \$x", "@(a).txt\na.txt\n"],
]);
