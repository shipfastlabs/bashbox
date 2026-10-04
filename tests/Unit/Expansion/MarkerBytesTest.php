<?php

declare(strict_types=1);

use BashBox\Bash;

// Every expected output below was checked against GNU bash 5.3 (`bash -c '<script>'`).

test('control bytes in values', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'values holding the bytes the expander uses internally survive expansion' => ["v=\$(printf 'a\\001b\\002c\\003d\\004e\\005f\\006g\\177h'); show() { printf '%s' \"\$1\" | tr '\\001\\002\\003\\004\\005\\006\\177' '1234567'; echo; }\nshow \"\$v\"; echo \${#v}\nfor w in \$v; do show \"[\$w]\"; done\nset -- \"\$v\" x; show \"<\$1><\$2>\$#\"\ny=\${v#a}; show \"\$y\"; show \"\${v%g*}\"; show \"\${v/c?d/-}\"; show \"\${v^^}\"\ncase \"\$v\" in a*g?h) echo matched;; esac\narr=(\"\$v\" \"q\"); show \"\${arr[0]}|\${arr[1]}\"; show \"\${arr[*]}\"\nz=\"\${v:-x}\"; [[ \$z == \"\$v\" ]] && echo same", "a1b2c3d4e5f6g7h\n15\n[a1b2c3d4e5f6g7h]\n<a1b2c3d4e5f6g7h><x>2\n1b2c3d4e5f6g7h\na1b2c3d4e5f6\na1b2-4e5f6g7h\nA1B2C3D4E5F6G7H\nmatched\na1b2c3d4e5f6g7h|q\na1b2c3d4e5f6g7h q\nsame\n"],
    'a byte used as IFS splits, and an escaped one in a pattern matches itself' => ["v=\$(printf 'a\\001b\\002c'); show() { printf '%s' \"\$1\" | tr '\\001\\002' '12'; echo; }\nIFS=\$(printf '\\001'); for w in \$v; do show \"(\$w)\"; done\nunset IFS; w=\$(printf 'x\\001 y'); for q in \$w; do show \"{\$q}\"; done; : \${u:=\$v}; show \"\$u\"; k=\$(printf '\\004'); echo \"\${k:+set}\" \"\${#k}\"\nset -- \"\$k\"; for p in \"\$@\"; do show \"[\$p]\"; done; set --; for p in \"\$@\" \"\$k\"; do show \"<\$p>\"; done\nb=\$(printf '\\002'); [[ \"x\${b}y\" == x?y ]] && echo one-char; c=\"\$b*\"; [[ \"\${b}zz\" == \$c ]] && echo glob", "(a)\n(b2c)\n{x1}\n{y}\na1b2c\nset 1\n[\x04]\n<\x04>\none-char\nglob\n"],
    'outside word splitting nothing is marked' => ['a=(x y); y=${a[1]}; v=$(printf \'p\\002q\'); w=${u:=$v}; printf \'%s|\' "$y" "$w" | tr \'\\002\' \'2\'', 'y|p2q|'],
]);
