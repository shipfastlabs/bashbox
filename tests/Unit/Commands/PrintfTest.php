<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashExecResult;
use BashBox\BashOptions;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\Limits;

test('printf', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'string flags, width and precision' => ["printf '%s|%5s|%-5s|%.2s|%%\\n' a b c xyz", "a|    b|c    |xy|%\n"],
    'integers' => ["printf '%d %i %+d %05d %o %x %X %u\\n' 7 -3 5 42 8 255 255 9", "7 -3 +5 00042 10 ff FF 9\n"],
    'hex and octal integer arguments' => ["printf '%d %d %d\\n' 0x1F 010 ' 5'", "31 8 5\n"],
    'floats' => ["printf '%f|%.1f|%05.1f|%F\\n' 1e2 2.25 3.14159 1", "100.000000|2.2|003.1|1.000000\n"],
    'char takes the first character' => ["printf '%c%c\\n' hello w", "hw\n"],
    'missing arguments are empty or zero' => ["printf '%s|%d|%c|%f\\n'", "|0|\0|0.000000\n"],
    'format is reused for leftover arguments' => ["printf '%s-%s\\n' a b c", "a-b\nc-\n"],
    'format without conversions is printed once' => ["printf 'plain\\n' extra args", "plain\n"],
    'format escapes' => ["printf '\\101|\\0101|\\x41|\\e|\\t|\\\\|\\q|\\c\\n'", "A|\x081|A|\e|\t|\\|\\q|\\c\n"],
    '%b expands escapes in the argument' => ["printf '%b|%b\\n' 'a\\tb' '\\0101\\101'", "a\tb|AA\n"],
    '%b \\c stops all further output' => ["printf '%b|%s\\n' 'x\\cy' z", 'x'],
    'invalid numbers are reported but printed by prefix' => [
        "printf '%d|%d|%f\\n' abc 12abc xyz",
        "0|12|0.000000\n",
        "printf: abc: invalid number\nprintf: 12abc: invalid number\nprintf: xyz: invalid number\n",
        1,
    ],
    'an explicitly empty number is invalid' => ["printf '%d\\n' ''", "0\n", "printf: : invalid number\n", 1],
    'invalid conversion stops output' => ["printf 'a%kb\\n'", 'a', "printf: `k': invalid format character\n", 1],
    '%e and %E' => ["printf '[%e|%E|%.0e|%#.0e|%10.3e|%+.3e|%e|%.10e]\\n' 100 0.000123 12345 5 1234.5678 0 1e100 1", "[1.000000e+02|1.230000E-04|1e+04|5.e+00| 1.235e+03|+0.000e+00|1.000000e+100|1.0000000000e+00]\n"],
    '%g picks %e or %f and drops trailing zeros' => ["printf '[%g|%G|%g|%g|%#g|%g|%.3g|%g|%.0g|%#.3g|%G|%g]\\n' 100000 1e-5 1234567 0.0001 1 0 3.14159 -2.5e300 15 1 1e20 0.00001234", "[100000|1E-05|1.23457e+06|0.0001|1.00000|0|3.14|-2.5e+300|2e+01|1.00|1E+20|1.234e-05]\n"],
    '%f flags' => ["printf '[%f|%.0f|%#.0f|%10.3f|%-10.2f|%+f|% f|%f|%010.2f]\\n' 1e2 2.5 3 3.14159 2.5 1 1 -0 -3.14159", "[100.000000|2|3.|     3.142|2.50      |+1.000000| 1.000000|-0.000000|-000003.14]\n"],
    'inf and nan' => ["printf '[%f|%F|%e|%g|%G|%5f|%-6e|%06f]\\n' inf -inf nan INFINITY nan inf -inf inf", "[inf|-INF|nan|inf|NAN|  inf|-inf  |   inf]\n"],
    '%a and %A' => ["printf '[%a|%a|%A|%.2a|%a|%a|%#.0a|%.15a|%a]\\n' 1 3 0.1 0.1 0 -2.5 1 1 4.9406564584124654e-320", "[0x1p+0|0x1.8p+1|0X1.999999999999AP-4|0x1.9ap-4|0x0p+0|-0x1.4p+1|0x1.p+0|0x1.000000000000000p+0|0x1.388p-1061]\n", "printf: 4.9406564584124654e-320: Result too large\n", 1],
    '%a rounds ties down like macOS' => ["printf '[%.0a|%.1a|%.0a|%.0a|%.2a|%.0a]\\n' 1.75 1.97265625 2.5 3.5 1.0009765625 1.5", "[0x2p+0|0x2.0p+0|0x1p+1|0x2p+1|0x1.00p+0|0x1p+0]\n"],
    'the # flag on integers' => ["printf '[%#x|%#X|%#o|%#o|%#x|%-#8x|%#08x|%#.0o]\\n' 255 255 8 0 0 255 255 0", "[0xff|0XFF|010|0|0|0xff    |0x0000ff|0]\n"],
    'sign and precision flags on integers' => ["printf '[% d|% d|%+ d|%08.3d|%.0d|%-05d|%.3x]\\n' 5 -5 5 7 0 3 255", "[ 5|-5|+5|     007||3    |0ff]\n"],
    'star width and precision' => ["printf '[%*d|%-*d|%.*f|%*.*s|%*d]\\n' 5 1 5 2 2 3.14159 6 2 abcdef -4 9", "[    1|2    |3.14|    ab|9   ]\n"],
    'invalid star width counts as zero' => ["printf '%*d|%.*d|' x 3 4 y 5 6", '3|0000|    6||', "printf: x: invalid number\nprintf: y: invalid number\n", 1],
    'length modifiers are ignored' => ["printf '[%ld|%lld|%hhx|%Lf|%zd|%jd|%td|%qd]\\n' 1 2 255 1.5 3 4 6 5", "[1|2|ff|1.500000|3|4|6|5d]\n"],
    'the grouping flag is a no-op in the C locale' => ["printf \"[%'d]\\n\" 1234567", "[1234567]\n"],
    'flags that only apply to numbers' => ["printf '[%#s|% s|%+s|%5c|%#c|%#b|%05s|%05c]\\n' x y z w v u e d", "[x|y|z|    w|v|u|0000e|0000d]\n"],
    'bash pads %b and %q itself, never with zeros' => ["printf '[%05b|%05q|%05Q]\\n' 'a\\tb' 'c d' e", "[  a\tb| c\\ d|    e]\n"],
    '%q backslash-quotes shell syntax' => ["printf '%q|' '' '~a' 'a~' 'a:~' 'a=~b' '#a' 'a#' \"it's\" 'a b' ' !\"\$&()*,;<>?[\\]^`{|}'", "''|\\~a|a~|a:\\~|a=\\~b|\\#a|a#|it\\'s|a\\ b|\\ \\!\\\"\\\$\\&\\(\\)\\*\\,\\;\\<\\>\\?\\[\\\\\\]\\^\\`\\{\\|\\}|"],
    "%q uses \$'...' for unprintable bytes" => ["printf '%q|' \"\$(printf 'a\\nb\\tc\\037\\177\\033\\a\\b\\f\\r\\v\\\\\\047')\" \"\$(printf '\\303\\251')\" \"\$(printf '\\377')\"", "\$'a\\nb\\tc\\037\\177\\E\\a\\b\\f\\r\\v\\\\\\''|\$'\\303\\251'|\$'\\377'|"],
    '%q and %Q with width and precision' => ["printf '[%10q|%-6q|%.2q|%.2Q|%5Q]\\n' 'a b' x abc 'a bc' 'a b'", "[      a\\ b|x     |ab|a\\ | a\\ b]\n"],
    '%(fmt)T formats epoch seconds' => ["printf '[%(%Y-%m-%d)T|%12(%m)T|%-6.2(%Y)T]\\n' 2980800 2980800 0", "[1970-02-04|          02|19    ]\n"],
    '%(fmt)T with an invalid time' => ["printf '[%(%Y)T]\\n' abc", "[1970]\n", "printf: abc: invalid number\n", 1],
    '%T needs a time format' => ["printf '%T\\n'", '', "printf: `T': invalid format character\n", 1],
    'integer argument forms' => ["printf '%d %d %d %d %d %d %i %X\\n' \"'A\" '\"B' '+0x10' '-010' \"'\" ' 12' 0x7f 3054", "65 66 16 -8 0 12 127 BEE\n"],
    'character constants use the first byte' => ["printf '%d %f\\n' \"'\$(printf '\\303\\251')\" \"'B\"", "195 66.000000\n"],
    'invalid integers name the expected base' => ["printf '%d|' 0x1g 09x 0xg 1x -0x 012z ' 0x5' x", '1|0|0|1|0|10|5|0|', "printf: 0x1g: invalid hex number\nprintf: 09x: invalid octal number\nprintf: 0xg: invalid hex number\nprintf: 1x: invalid number\nprintf: -0x: invalid number\nprintf: 012z: invalid octal number\nprintf: x: invalid number\n", 1],
    'out of range integers' => ["printf '%x %o %u %d %d %d\\n' -1 -1 -1 9999999999999999999 -9999999999999999999 -9223372036854775808", "ffffffffffffffff 1777777777777777777777 18446744073709551615 9223372036854775807 -9223372036854775808 -9223372036854775808\n", "printf: 9999999999999999999: Result too large\nprintf: -9999999999999999999: Result too large\n", 1],
    'float argument forms' => ["printf '%f|' .5 5. 1e3x 0x1.8p1 0x.8 0x10 +1 - '' 0e5", '0.500000|5.000000|1000.000000|3.000000|0.500000|16.000000|1.000000|0.000000|0.000000|0.000000|', "printf: 1e3x: invalid number\nprintf: -: invalid number\nprintf: : invalid number\n", 1],
    'out of range floats' => ["printf '%f|' 1e400 1e-400 -1e400 2.2250738585072014e-308 2e-308", 'inf|0.000000|-inf|0.000000|0.000000|', "printf: 1e400: Result too large\nprintf: 1e-400: Result too large\nprintf: -1e400: Result too large\nprintf: 2e-308: Result too large\n", 1],
    'a lone percent is a missing format character' => ["printf '%'", '', "printf: `%': missing format character\n", 1],
    'an unterminated conversion is reported' => ["printf 'a%5'", 'a', "printf: `%5': missing format character\n", 1],
    'dangling flags' => ["printf '%-'", '', "printf: `%-': missing format character\n", 1],
    'dangling length modifiers' => ["printf '%ll'", '', "printf: `%ll': missing format character\n", 1],
    'a width on %% is invalid' => ["printf '%5%|\\n'", '', "printf: `%': invalid format character\n", 1],
    '-- ends options' => ["printf -- '[%s]\\n' a", "[a]\n"],
    '-- after the format is an argument' => ["printf '[%s]\\n' -- a", "[--]\n[a]\n"],
    'no format' => ['printf', '', "printf: usage: printf [-v var] format [arguments]\n", 2],
    '-v assigns through the shell' => ['printf -v x %s a; echo "$x"', "a\n"],
    'an invalid option' => ['printf -xyz', '', "printf: -x: invalid option\nprintf: usage: printf [-v var] format [arguments]\n", 2],
    'a lone dash is the format' => ['printf -', '-'],
]);

test('printf stops at the output size limit instead of exhausting memory', function (string $script): void {
    expect(fn (): BashExecResult => new Bash(new BashOptions(limits: new Limits(maxOutputSize: 1000)))->exec($script))
        ->toThrow(ExecutionLimitException::class, 'printf: output size limit exceeded');
})->with([
    'a huge width' => ['printf %999999999d 1'],
    'a huge precision from an argument' => ['printf %.*d 999999999 1'],
    'widths adding up in one pass' => ['printf %600s%600s a b'],
    'the format reused' => ['printf %s-- $(seq 1 250)'],
]);
