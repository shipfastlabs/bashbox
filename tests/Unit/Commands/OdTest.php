<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

test('od', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bash = new Bash(new BashOptions(cwd: '/home/user', env: ['USER' => 'user']));
    $bash->writeFile('/home/user/o.bin', "hello world\n\x00\x7f\x80\xff");
    $bash->writeFile('/home/user/z', "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00x");
    $bash->writeFile('/home/user/e', '');

    $bashExecResult = $bash->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'nothing is dumped when no file can be read' => ["od 'n o'", '', "od: 'n o': No such file or directory\n", 1],
    'octal words' => ['od o.bin', "0000000 062550 066154 020157 067567 066162 005144 077400 177600\n0000020\n"],
    'characters' => ['od -c o.bin', "0000000   h   e   l   l   o       w   o   r   l   d  \\n  \\0 177 200 377\n0000020\n"],
    'hex words' => ['od -x o.bin', "0000000 6568 6c6c 206f 6f77 6c72 0a64 7f00 ff80\n0000020\n"],
    'no addresses' => ['od -An -tx1 o.bin', " 68 65 6c 6c 6f 20 77 6f 72 6c 64 0a 00 7f 80 ff\n"],
    'hex bytes' => ['od -t x1 o.bin', "0000000 68 65 6c 6c 6f 20 77 6f 72 6c 64 0a 00 7f 80 ff\n0000020\n"],
    'octal bytes' => ['od -t o1 o.bin', "0000000 150 145 154 154 157 040 167 157 162 154 144 012 000 177 200 377\n0000020\n"],
    'signed bytes' => ['od -t d1 o.bin', "0000000  104  101  108  108  111   32  119  111  114  108  100   10    0  127 -128   -1\n0000020\n"],
    'unsigned bytes' => ['od -t u1 o.bin', "0000000 104 101 108 108 111  32 119 111 114 108 100  10   0 127 128 255\n0000020\n"],
    'named characters' => ['od -t a o.bin', "0000000   h   e   l   l   o  sp   w   o   r   l   d  nl nul del nul del\n0000020\n"],
    'chars and hex aligned' => ['od -tc -tx2 o.bin', "0000000   h   e   l   l   o       w   o   r   l   d  \\n  \\0 177 200 377\n           6568    6c6c    206f    6f77    6c72    0a64    7f00    ff80\n0000020\n"],
    'signed and hex aligned' => ['od -t d1 -t x1 o.bin', "0000000  104  101  108  108  111   32  119  111  114  108  100   10    0  127 -128   -1\n          68   65   6c   6c   6f   20   77   6f   72   6c   64   0a   00   7f   80   ff\n0000020\n"],
    'eight-byte hex with chars' => ['od -tx8 -tc o.bin', "0000000                6f77206f6c6c6568                ff807f000a646c72\n          h   e   l   l   o       w   o   r   l   d  \\n  \\0 177 200 377\n0000020\n"],
    'concatenated types' => ['od -t x1o1 o.bin', "0000000  68  65  6c  6c  6f  20  77  6f  72  6c  64  0a  00  7f  80  ff\n        150 145 154 154 157 040 167 157 162 154 144 012 000 177 200 377\n0000020\n"],
    'size letters' => ['od -t dC -t uS -t oI -t xL o.bin', "0000000  104  101  108  108  111   32  119  111  114  108  100   10    0  127 -128   -1\n            25960     27756      8303     28535     27762      2660     32512     65408\n                15433062550         15735620157         01231066162         37740077400\n                               6f77206f6c6c6568                        ff807f000a646c72\n0000020\n"],
    'default size is int' => ['od -t d o.bin', "0000000  1819043176  1870078063   174353522    -8356096\n0000020\n"],
    'eight-byte types' => ['od -t d8 -t u8 -t o8 o.bin', "0000000    8031924123371070824     -35889158867882894\n           8031924123371070824   18410854914841668722\n        0675671006755433062550 1776003760001231066162\n0000020\n"],
    'two and four byte types' => ['od -t d2 -t u4 -t o4 o.bin', "0000000  25960  27756   8303  28535  27762   2660  32512   -128\n           1819043176    1870078063     174353522    4286611200\n          15433062550   15735620157   01231066162   37740077400\n0000020\n"],
    'hexl trailer' => ['od -t x1z o.bin', "0000000 68 65 6c 6c 6f 20 77 6f 72 6c 64 0a 00 7f 80 ff  >hello world.....<\n0000020\n"],
    'hexl trailer partial' => ["printf 'abc' | od -t x2z", "0000000 6261 0063                                >abc<\n0000003\n"],
    'traditional options' => ['od -a -b -B -d -D -h -H -i -l -o -O -s -X o.bin', "0000000   h   e   l   l   o  sp   w   o   r   l   d  nl nul del nul del\n        150 145 154 154 157 040 167 157 162 154 144 012 000 177 200 377\n         062550  066154  020157  067567  066162  005144  077400  177600\n          25960   27756    8303   28535   27762    2660   32512   65408\n             1819043176      1870078063       174353522      4286611200\n           6568    6c6c    206f    6f77    6c72    0a64    7f00    ff80\n               6c6c6568        6f77206f        0a646c72        ff807f00\n             1819043176      1870078063       174353522        -8356096\n                    8031924123371070824              -35889158867882894\n         062550  066154  020157  067567  066162  005144  077400  177600\n            15433062550     15735620157     01231066162     37740077400\n          25960   27756    8303   28535   27762    2660   32512    -128\n               6c6c6568        6f77206f        0a646c72        ff807f00\n0000020\n"],
    'more traditional' => ['od -I -L o.bin', "0000000  8031924123371070824   -35889158867882894\n         8031924123371070824   -35889158867882894\n0000020\n"],
    'read bytes' => ['od -N 5 -c o.bin', "0000000   h   e   l   l   o\n0000005\n"],
    'skip bytes' => ['od -j 6 -c o.bin', "0000006   w   o   r   l   d  \\n  \\0 177 200 377\n0000020\n"],
    'skip hex and limit' => ['od -j 0x2 -N 010 -c o.bin', "0000002   l   l   o       w   o   r   l\n0000012\n"],
    'skip with multiplier' => ['od -j 1b -c z', '', "od: cannot skip past end of combined input\n", 1],
    'skip with k' => ['od -N 1k -c o.bin', "0000000   h   e   l   l   o       w   o   r   l   d  \\n  \\0 177 200 377\n0000020\n"],
    'skip to end' => ['od -j 16 o.bin', "0000020\n"],
    'skip past end' => ['od -j 100 o.bin', '', "od: cannot skip past end of combined input\n", 1],
    'decimal addresses' => ['od -Ad -tx1 -w4 o.bin', "0000000 68 65 6c 6c\n0000004 6f 20 77 6f\n0000008 72 6c 64 0a\n0000012 00 7f 80 ff\n0000016\n"],
    'hex addresses' => ['od -A x -t x1 -w 4 z', "000000 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00\n*\n000040 78\n000041\n", "od: 4: No such file or directory\n", 1],
    'address radix takes the first char' => ['od -A dx o.bin', "0000000 062550 066154 020157 067567 066162 005144 077400 177600\n0000016\n"],
    'width' => ['od -w5 -tx1 o.bin', "0000000 68 65 6c 6c 6f\n0000005 20 77 6f 72 6c\n0000012 64 0a 00 7f 80\n0000017 ff\n0000020\n"],
    'width alone is 32' => ['od -w -tx1 o.bin', "0000000 68 65 6c 6c 6f 20 77 6f 72 6c 64 0a 00 7f 80 ff\n0000020\n"],
    'bundled width' => ['od -vw -tx1 z', "0000000 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00\n0000040 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00\n0000100 78\n0000101\n"],
    'long width' => ['od --width=4 -c o.bin', "0000000   h   e   l   l\n0000004   o       w   o\n0000010   r   l   d  \\n\n0000014  \\0 177 200 377\n0000020\n"],
    'long width alone' => ['od --width -c o.bin', "0000000   h   e   l   l   o       w   o   r   l   d  \\n  \\0 177 200 377\n0000020\n"],
    'invalid width for type' => ['od -t x2 -w3 o.bin', "0000000 6568\n0000002 6c6c\n0000004 206f\n0000006 6f77\n0000010 6c72\n0000012 0a64\n0000014 7f00\n0000016 ff80\n0000020\n", "od: warning: invalid width 3; using 2 instead\n"],
    'zero width' => ['od -w0 o.bin', '', "od: invalid -w argument '0'\n", 1],
    'duplicates collapse' => ['od -tx1 z', "0000000 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00\n*\n0000100 78\n0000101\n"],
    'output duplicates' => ['od -v -tx1 z', "0000000 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00\n0000020 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00\n0000040 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00\n0000060 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00\n0000100 78\n0000101\n"],
    'empty input' => ['od e', "0000000\n"],
    'empty input no address' => ['od -An e', ''],
    'stdin' => ["printf 'hi' | od -c", "0000000   h   i\n0000002\n"],
    'files are one stream' => ['od -c o.bin - o.bin < o.bin', "0000000   h   e   l   l   o       w   o   r   l   d  \\n  \\0 177 200 377\n*\n0000060\n"],
    'missing file' => ['od nope o.bin', "0000000 062550 066154 020157 067567 066162 005144 077400 177600\n0000020\n", "od: nope: No such file or directory\n", 1],
    'invalid type char' => ['od -t q o.bin', '', "od: invalid character 'q' in type string 'q'\n", 1],
    'invalid type size' => ['od -t x3 o.bin', '', "od: invalid type string 'x3';\nthis system doesn't provide a 3-byte integral type\n", 1],
    'empty type string' => ["od -t '' o.bin", "0000000 062550 066154 020157 067567 066162 005144 077400 177600\n0000020\n"],
    'invalid radix' => ['od -A q o.bin', '', "od: invalid output address radix 'q'; it must be one character from [doxn]\n", 1],
    'empty radix' => ["od -A '' o.bin", '', "od: invalid output address radix '\x00'; it must be one character from [doxn]\n", 1],
    'invalid skip' => ['od -j x o.bin', '', "od: invalid -j argument 'x'\n", 1],
    'invalid count' => ['od -N x o.bin', '', "od: invalid -N argument 'x'\n", 1],
    'bad option' => ['od -y', '', "od: invalid option -- 'y'\nTry 'od --help' for more information.\n", 1],
    'long options' => ['od --address-radix=n --format=c --read-bytes=3 --skip-bytes=1 --output-duplicates o.bin', "   e   l   l\n"],
]);
