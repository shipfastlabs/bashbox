<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashExecResult;
use BashBox\BashOptions;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\Limits;

test('xargs', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'defaults to echo' => ["printf 'a b\\n c' | xargs", "a b c\n"],
    'appends items to the command' => ['echo a b | xargs echo X', "X a b\n"],
    '-n limits items per run' => ['echo a b c | xargs -n 2; echo a b | xargs -n1 echo -', "a b\nc\n- a\n- b\n"],
    'options after the command belong to it' => ['echo a b | xargs -n1 echo -n; echo', "ab\n"],
    '-I runs once per line, keeping trailing blanks' => ["printf 'one\\n\\n  two  \\n' | xargs -I {} echo '<{}>' {}", "<one> one\n<two  > two  \n"],
    '-I with an attached placeholder' => ['echo x | xargs -I% echo [%]', "[x]\n"],
    'runs once on empty input' => ["printf '' | xargs echo hi", "hi\n"],
    '-r skips empty input' => ["printf '' | xargs -r echo hi", ''],
    'items are passed literally until an unmatched quote' => ["printf '%s\\n' '*' 'a\$b' \"it's\" | xargs -I{} echo {}", "*\na\$b\n", "xargs: unmatched single quote; by default quotes are special to xargs unless you use the -0 option\n", 1],
    'a failing command makes xargs exit 123' => ['echo a | xargs false', '', '', 123],
    'an unknown command exits 127' => ['echo a | xargs nope', '', "xargs: nope: No such file or directory\n", 127],
    'a command that is not executable exits 126' => ['mkdir d; echo a | xargs ./d', '', "xargs: ./d: Permission denied\n", 126],
    'exit status 255 stops xargs' => ["printf 'exit 255\\n' > s; chmod +x s; echo a b | xargs -n1 ./s", '', "xargs: ./s: exited with status 255; aborting\n", 124],
    'bad options' => ['xargs -k; xargs --foo; xargs -n; xargs --max-args; xargs --null=x; xargs --ma 2', '', "xargs: invalid option -- 'k'\nTry 'xargs --help' for more information.\nxargs: unrecognized option '--foo'\nTry 'xargs --help' for more information.\nxargs: option requires an argument -- 'n'\nTry 'xargs --help' for more information.\nxargs: option '--max-args' requires an argument\nTry 'xargs --help' for more information.\nxargs: option '--null' doesn't allow an argument\nTry 'xargs --help' for more information.\nxargs: option '--ma' is ambiguous; possibilities: '--max-lines' '--max-args' '--max-chars' '--max-procs'\nTry 'xargs --help' for more information.\n", 1],
    'quotes and backslashes group words' => ['printf \'%s\\n\' \'"a b" \'\\\'\'c d\'\\\'\' e\\\\ f g\\\\\\\\h\' \'i\\\\\' \'j\' | xargs -n1 echo', "a b\nc d\ne\\\nf\ng\\\\h\ni\\\nj\n"],
    'an empty quoted word is kept but a final one is dropped' => ['printf \'"" a\\nb ""\' | xargs -n1 echo -; printf "c \'" | xargs echo', "- \n- a\n- b\nc\n"],
    'a backslash escapes a newline' => ["printf 'a\\\\\\nb\\n' | xargs -n1 echo; printf 'c\\\\' | xargs echo", "a\nb\nc\n"],
    'an unmatched quote first runs what was read' => ['printf \'ab cd "x\\n\' | xargs echo; printf \'ab "x\' | xargs -n1 echo; printf "ab \'x\\ny\'" | xargs -x echo', "ab cd\nab\n", "xargs: unmatched double quote; by default quotes are special to xargs unless you use the -0 option\nxargs: unmatched double quote; by default quotes are special to xargs unless you use the -0 option\nxargs: unmatched single quote; by default quotes are special to xargs unless you use the -0 option\n", 1],
    'a NUL ends an argument, with a warning' => ["printf 'a\\0b c\\0d\\n' | xargs echo", "a c\n", "xargs: WARNING: a NUL character occurred in the input.  It cannot be passed through in the argument list.  Did you mean to use the --null option?\n"],
    '-0 splits at NULs' => ["printf 'a b\\0\\0c\\0' | xargs -0 -n1 echo -", "- a b\n- \n- c\n"],
    '-d takes a character or an escape' => ["printf 'a,b,,c' | xargs -d, echo; printf 'a\\tb\\n' | xargs -d '\\t' echo; printf 'aXb' | xargs -d '\\x58' echo; printf 'aXb' | xargs --delimiter='\\130' echo; printf 'a\\ab' | xargs -d '\\a' echo", "a b  c\na b\n\na b\na b\na b\n"],
    '-d numbers are read like strtoul' => ["printf 'aAb' | xargs -d '\\x 41' echo; printf 'aAb' | xargs -d '\\x+0x41' echo; printf 'a\\0b' | xargs -d '\\x' echo; printf 'a\\0b' | xargs -d '\\x-0' echo", "a b\na b\na b\na b\n"],
    'bad -d delimiters' => ["xargs -d ab; xargs -d ''; xargs -d '\\q'; xargs -d '\\x100'; xargs -d '\\400'; xargs -d '\\x-1'; xargs -d '\\101z'; xargs -d '\\9'", '', "xargs: Invalid input delimiter specification ab: the delimiter must be either a single character or an escape sequence starting with \\.\nxargs: Invalid input delimiter specification : the delimiter must be either a single character or an escape sequence starting with \\.\nxargs: Invalid escape sequence \\q in input delimiter specification.\nxargs: Invalid escape sequence \\x100 in input delimiter specification; character values must not exceed ff.\nxargs: Invalid escape sequence \\400 in input delimiter specification; character values must not exceed 377.\nxargs: Invalid escape sequence \\x-1 in input delimiter specification; character values must not exceed ff.\nxargs: Invalid escape sequence \\101z in input delimiter specification; trailing characters z not recognised.\nxargs: Invalid escape sequence \\9 in input delimiter specification; trailing characters 9 not recognised.\n", 1],
    '-d items keep blanks and quotes' => ['printf "a \'b\\nc\\n" | xargs -d \'\\n\' -n1 echo', "a 'b\nc\n"],
    '-E stops at the end-of-file word' => ["printf 'a b\\nSTOP\\nc\\n' | xargs -E STOP echo; printf 'a STOP b\\n' | xargs -eSTOP echo; printf 'STOP\\nc\\n' | xargs --eof=STOP echo x; printf 'a STOP' | xargs -E STOP; printf 'STOP' | xargs -E STOP echo y", "a b\na\nx\na STOP\ny\n"],
    '-E on a line ending' => ["printf 'a STOP\\nb\\n' | xargs -E STOP -L1 echo", "a\n"],
    'a bare -e turns -E off' => ['echo a x b | xargs -E x -e echo; echo a x b | xargs -E x --eof echo', "a x b\na x b\n"],
    '-E has no effect with -0 or -d' => ["printf 'a\\0x\\0b' | xargs -E x -0 echo", "a x b\n", "xargs: warning: the -E option has no effect if -0 or -d is used.\n\n"],
    '-L runs per lines' => ["printf 'a\\nb\\nc\\nd\\ne\\n' | xargs -L2 echo", "a b\nc d\ne\n"],
    '-L continues a line ending in a blank' => ["printf 'a b \\nc\\nd\\\\ \\ne\\n' | xargs -L1 echo", "a b c\nd  e\n"],
    '-l defaults to one line' => ["printf 'a\\nb\\nc\\n' | xargs -l echo; printf 'a\\nb\\nc\\n' | xargs -l2 echo; printf 'a\\nb\\n' | xargs --max-lines echo", "a\nb\nc\na b\nc\na\nb\n"],
    'a bare optional option at the end of a bundle' => ['echo a x b | xargs -E x -tl echo; echo y | xargs -ti echo {}; echo z | xargs -it echo {}', "a\ny\n{}\n", "echo a\necho y\n"],
    'options taking a value consume the next word' => ['echo a | xargs -a -i; echo a | xargs --arg-file -i; echo a | xargs --max-args -l', '', "xargs: Cannot open input file '-i': No such file or directory\nxargs: Cannot open input file '-i': No such file or directory\nxargs: invalid number \"-l\" for -n option\nTry 'xargs --help' for more information.\n", 1],
    '-L, -n and -I exclude each other' => ["printf 'a\\nb\\nc\\n' | xargs -n2 -L1 echo; printf 'a\\nb\\nc\\n' | xargs -L1 -n2 echo; printf 'a\\nb\\n' | xargs -I{} -l echo {}", "a\nb\nc\na b\nc\n{} a\n{} b\n", "xargs: warning: options --max-args and -L are mutually exclusive, ignoring previous --max-args value\nxargs: warning: options --max-lines and --max-args/-n are mutually exclusive, ignoring previous --max-lines value\nxargs: warning: options --replace and --max-lines/-l are mutually exclusive, ignoring previous --replace value\n"],
    '-I excludes -n and -L' => ["printf 'a\\nb\\n' | xargs -L1 -I{} echo {}; printf 'a\\nb\\n' | xargs -n2 -I{} echo {}; printf 'a\\nb\\n' | xargs -I{} -n2 echo {}", "a\nb\na\nb\n{} a b\n", "xargs: warning: options --max-lines and --replace/-I/-i are mutually exclusive, ignoring previous --max-lines value\nxargs: warning: options --max-args and --replace/-I/-i are mutually exclusive, ignoring previous --max-args value\nxargs: warning: options --replace and --max-args/-n are mutually exclusive, ignoring previous --replace value\n"],
    '-n1 after -I is ignored' => ["printf 'a b\\nc\\n' | xargs -I{} -n1 echo [{}]", "[a b]\n[c]\n"],
    '-i defaults to {}' => ["printf 'a\\nb\\n' | xargs -i echo {}; echo c | xargs --replace echo {}; echo d | xargs -iX echo X", "a\nb\nc\nd\n"],
    'bad numbers' => ["xargs -n x; xargs -n ''; xargs -n '2\n'; xargs -n0; xargs -L -1; xargs -l0; xargs -P -1; xargs -P 2147483648", '', "xargs: invalid number \"x\" for -n option\nTry 'xargs --help' for more information.\nxargs: invalid number \"\" for -n option\nTry 'xargs --help' for more information.\nxargs: invalid number \"2\n\" for -n option\nTry 'xargs --help' for more information.\nxargs: value 0 for -n option should be >= 1\nTry 'xargs --help' for more information.\nxargs: value -1 for -L option should be >= 1\nTry 'xargs --help' for more information.\nxargs: value 0 for -l option should be >= 1\nTry 'xargs --help' for more information.\nxargs: value -1 for -P option should be >= 0\nTry 'xargs --help' for more information.\nxargs: value 2147483648 for -P option should be <= 2147483647\nTry 'xargs --help' for more information.\n", 1],
    'numbers may have blanks and a sign' => ["echo a b c | xargs -n ' +2' echo; echo d | xargs -P 2 echo", "a b\nc\nd\n"],
    '-s limits the command length' => ["printf 'a\\nb\\nc\\n' | xargs -s 9 echo", "a b\nc\n"],
    '-s below 1 is raised to 1' => ['echo a | xargs -s 0 echo', '', "xargs: value 0 for -s option should be >= 1\nxargs: cannot fit single argument within argument list size limit\n", 1],
    '-s too small for the command' => ['echo a | xargs -s 4', '', "xargs: cannot fit single argument within argument list size limit\n", 1],
    'an argument longer than -s allows' => ["printf 'ab\\nabcdef\\n' | xargs -s 9 echo; printf 'ab\\nabcdef\\n' | xargs -d '\\n' -s 9 echo", "ab\nab\n", "xargs: argument line too long\nxargs: argument line too long\n", 1],
    '-x with -n, or -L, fails instead of splitting' => ["printf 'a b c\\n' | xargs -n2 -x -s 8 echo; printf 'a b\\nc d e\\n' | xargs -L1 -s 10 echo", "a b\n", "xargs: argument list too long\nxargs: argument list too long\n", 1],
    '-x alone still splits' => ["printf 'a b c d\\n' | xargs -x -s 9 echo", "a b\nc d\n"],
    '-I size limits' => ["printf 'abc\\n' | xargs -I{} -s 8 echo {} {}; printf 'abcde\\n' | xargs -I{} -s 6 echo x{}; printf 'abcdef\\n' | xargs -I{} -s 6 echo x{}; printf 'a\\n' | xargs -I{} -s 3 echo {}; printf '' | xargs -I{} -s 3 echo {}", '', "xargs: argument list too long\nxargs: command too long\nxargs: argument line too long\nxargs: cannot fit single argument within argument list size limit\n"],
    'an empty -I pattern' => ["echo a | xargs -I '' echo '' y; echo b | xargs -I '' echo '' x", '', "xargs: command too long\nxargs: command too long\n", 1],
    '-I with -0' => ["printf 'a b\\0c\\0' | xargs -0 -I{} echo [{}]", "[a b]\n[c]\n"],
    '-a reads items from a file' => ["printf 'a b\\nc\\n' > f; xargs -a f echo; echo in | xargs --arg-file=f -n2 cat -", "a b c\nin\n", "cat: a: No such file or directory\ncat: b: No such file or directory\ncat: c: No such file or directory\n", 123],
    '-a - reads standard input' => ["printf 'a\\nb\\n' | xargs -a - echo", "a b\n"],
    '-a with a directory reads nothing' => ['mkdir d; xargs -a d echo x', "x\n"],
    '-a with a missing file' => ['xargs -a nope echo', '', "xargs: Cannot open input file 'nope': No such file or directory\n", 1],
    '-t prints each command' => ['printf \'a b\\nc\\n\' | xargs -t -n2 echo; printf "it\'s\\ta b\\n" | xargs -d \'\\n\' --verbose', "a b\nc\nit's\ta b\n", "echo a b\necho c\necho 'it'\\''s'\$'\\t''a b'\n"],
    '-p fails without a terminal' => ["printf 'a b\\n' | xargs -p echo", '', "echo a bxargs: failed to open /dev/tty for reading: No such device or address\n", 1],
    '-o fails without a terminal' => ["printf 'a b\\n' | xargs -o -n1 echo", '', "xargs: '/dev/tty': No such device or address\nxargs: '/dev/tty': No such device or address\n", 123],
]);

test('xargs stops at the output size limit instead of exhausting memory', function (): void {
    expect(fn (): BashExecResult => new Bash(new BashOptions(limits: new Limits(maxOutputSize: 1000)))->exec('seq 1 200 | xargs -n1 echo item-item-item'))
        ->toThrow(ExecutionLimitException::class, 'Output size limit exceeded (1000 bytes)');
});
