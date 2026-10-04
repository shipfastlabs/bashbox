<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\ExecOptions;

// Expected outputs come from GNU bash 5.3 (`bash -c '<script>'` fed the given stdin), with "bash: line N:" shortened to "bash:".

test('fds, read, umask, kill and help', function (string $script, string $stdin, string $stdout, string $stderr, int $exitCode): void {
    $bashExecResult = (new Bash)->exec($script, new ExecOptions(stdin: $stdin));

    expect($bashExecResult->stdout)->toBe($stdout)
        ->and($bashExecResult->stderr)->toBe($stderr)
        ->and($bashExecResult->exitCode)->toBe($exitCode);
})->with([
    'exec 3>file keeps writing until closed' => ['exec 3>f; echo a >&3; echo b >&3; exec 3>&-; cat f; echo x >&3; echo "rc=$?"', '', "a\nb\nrc=1\n", "bash: 3: Bad file descriptor\n", 0],
    'exec 3>>file appends' => ['echo zero >f; exec 3>>f; echo one >&3; cat f', '', "zero\none\n", '', 0],
    'reopening an fd truncates again' => ['exec 3>f; echo one >&3; exec 3>f; echo two >&3; cat f', '', "two\n", '', 0],
    'read -u reads an input fd line by line, cat <&3 the rest' => ['printf "l1\\nl2\\nl3\\nl4\\n" > in; exec 3<in; read -u 3 a; read -u 3 b; echo "$a|$b"; cat <&3; exec 3<&-; read -u 3 c; echo "rc=$?"', '', "l1|l2\nl3\nl4\nrc=1\n", "bash: read: 3: invalid file descriptor: Bad file descriptor\n", 0],
    '<> reads and writes at one shared offset' => ['printf "abcdef\\n" > rw; exec 3<>rw; echo XY >&3; read -u 3 r; echo "r=$r"; cat rw', '', "r=def\nXY\ndef\n", '', 0],
    '<> creates a missing file' => ['exec 3<>new; echo hi >&3; cat new', '', "hi\n", '', 0],
    'a missing input file is an error' => ['exec 3<missing; echo $?', '', "1\n", "bash: missing: No such file or directory\n", 0],
    'an input fd sees later writes to its file' => ['echo one >f; exec 3<f; read -u 3 a; echo two >>f; read -u 3 b; echo "$a $b"', '', "one two\n", '', 0],
    'an input fd outlives its file being removed' => ['printf "a\\nb\\n" >f; exec 3<f; rm f; read -u 3 x; echo "$x"', '', "a\n", '', 0],
    'true writes nothing, so a closed stdout is fine' => ['true >&-; echo $?', '', "0\n", '', 0],
    'an unopened fd is a bad file descriptor' => ['echo a >&5; echo "rc=$?"; cat <&5; echo "rc=$?"; read -u 5 x; echo "rc=$?"', '', "rc=1\nrc=1\nrc=1\n", "bash: 5: Bad file descriptor\nbash: 5: Bad file descriptor\nbash: read: 5: invalid file descriptor: Bad file descriptor\n", 0],
    '3>&1 1>&2 2>&3 swaps stdout and stderr' => ['{ echo out; echo err >&2; } 3>&1 1>&2 2>&3', '', "err\n", "out\n", 0],
    'a function sees the fds of its call' => ['f() { echo x >&3; }; f 3>o5; cat o5; f; echo rc=$?', '', "x\nrc=1\n", "bash: 3: Bad file descriptor\n", 0],
    'exec >file redirects the rest of the script' => ['exec >o; echo a; echo b | cat; { echo c; }; echo d >&2; exec >&2; echo e; cat o', '', '', "d\ne\na\nb\nc\n", 0],
    'exec 2>file collects later errors' => ['exec 2>e; echo x >&2; cd /nonexistent-dir; exec 2>&1; echo y >&2; cat e', '', "y\nx\nbash: cd: /nonexistent-dir: No such file or directory\n", '', 0],
    'exec 3>&1 lets $(...) output bypass the capture' => ['exec 3>&1; x=$(echo hi >&3; echo there); echo "x=$x"', '', "hi\nx=there\n", '', 0],
    'output to a dup of stdout keeps its order inside $(...)' => ['exec 3>&1; f() { echo a; echo b >&3; echo c; }; x=$(f); echo "[$x]"', '', "b\n[a\nc]\n", '', 0],
    'a dup of stderr from inside a quieted $(...)' => ['exec 4>&2; x=$( { echo e >&4; echo o; } 2>/dev/null ); echo "$x"', '', "o\n", "e\n", 0],
    'exec 3>&1 bypasses a pipe' => ['exec 3>&1; { echo a >&3; echo b; } | tr a-z A-Z', '', "a\nB\n", '', 0],
    '|& sends stderr into the pipe even after exec 2>file' => ['exec 2>e; { echo err >&2; } |& tr a-z A-Z; cat e', '', "ERR\n", '', 0],
    'exec in a subshell stays there' => ['( exec >o2; echo in ); echo out; cat o2', '', "out\nin\n", '', 0],
    'exec in a group with redirections keeps new fds' => ['{ exec 3>f; } >g; echo x >&3; cat f g', '', "x\n", '', 0],
    'exec cmd applies its redirections and ends the script' => ['exec echo hi >o3; echo notreached', '', '', '', 0],
    'exec cmd that is not found sends the error through its redirections' => ['exec nosuch >o4 2>&1; echo after', '', '', '', 127],
    'builtin exec with no command changes nothing' => ['builtin exec; echo still', '', "still\n", '', 0],
    'exec <file reads the rest of the script input from it' => ['printf "p\\nq\\n" >in2; exec <in2; read a; echo "a=$a"; cat', "stdin\n", "a=p\nq\n", '', 0],
    '0<&3 and 4<&0 duplicate input fds' => ['printf "p\\nq\\n" > in2; exec 3<in2; exec 0<&3; read a; echo $a; exec 4<&0; read -u 4 b; echo $b', '', "p\nq\n", '', 0],
    'a saved stdin comes back after exec <file' => ['printf "a\\n" >f; exec 3<&0; exec <f; read x; exec <&3; read y; echo "$x$y"', "S\n", "aS\n", '', 0],
    'read from a closed or write-only stdin fails' => ['read x <&-; echo $?; exec 3>f; read y <&3; echo $?; exec 0<&-; read z; echo $?', "S\n", "1\n1\n1\n", "bash: read: 0: read error: Bad file descriptor\nbash: read: 0: read error: Bad file descriptor\nbash: read: 0: read error: Bad file descriptor\n", 0],
    'a loop can read two inputs at once' => ['printf "1\\n2\\n" > f; while read -u 3 l; do read x; echo "$l$x"; done 3<f', "A\nB\n", "1A\n2B\n", '', 0],
    'separate opens of a file keep separate offsets' => ['printf "1\\n2\\n3\\n" > f; { read a; read b <&3; read c; echo "$a$b$c"; } 3<f <f', '', "112\n", '', 0],
    'a failed redirection reports through the fds opened before it' => ['echo hi 2>/dev/null >/nonexist/x; echo rc=$?', '', "rc=1\n", '', 0],
    '>&word other than for fd 1, and <&word, are ambiguous' => ['echo hi 2>&foo; echo rc=$?; cat <&foo; echo rc=$?; echo hi >&foo2; cat foo2', '', "rc=1\nrc=1\nhi\n", "bash: foo: ambiguous redirect\nbash: foo: ambiguous redirect\n", 0],
    'closing an fd that was never open is fine' => ['exec 7>&-; echo rc=$?; exec 1>&-; echo hi; echo rc=$? >&2', '', "rc=0\n", "bash: echo: write error: Bad file descriptor\nrc=1\n", 0],
    'errors of builtins that run commands go through their redirections' => ['builtin nosuch 2>/dev/null; echo $?; source 2>/dev/null; echo $?; source nofile 2>/dev/null; echo $?; eval "if" 2>/dev/null; echo $?; nosuch 2>/dev/null; echo $?', '', "1\n2\n1\n2\n127\n", '', 0],
    'command -v output follows its redirections' => ['command -v echo >f; cat f; command -v echo >&-; echo $?', '', "echo\n0\n", '', 0],
    'an arithmetic command error follows its redirections' => ['(( 1/0 )) 2>/dev/null; echo $?', '', "1\n", '', 0],
    'xtrace follows the shell stderr' => ['{ set -x; echo a; set +x; } 2>t; cat t', '', "a\n+ echo a\n+ set +x\n", '', 0],
    // bash on macOS runs BSD head ("head: stdout: ..."); GNU head (checked with ghead) says "write error".
    'writing to a closed fd fails for external commands too' => ['echo hi >&-; echo "rc=$?"; : >in; exec 3<in; echo hi >&3; echo "rc=$?"; printf x >&-; echo "rc=$?"; echo x | head -1 >&-; echo "rc=$?"', '', "rc=1\nrc=1\nrc=1\nrc=1\n", "bash: echo: write error: Bad file descriptor\nbash: echo: write error: Bad file descriptor\nbash: printf: write error: Bad file descriptor\nhead: write error: Bad file descriptor\n", 0],
    // bash also prints "line 1:" here.
    'swapping works for a single command too' => ['cd /nonexistent-dir 3>&1 1>&2 2>&3 | tr a-z A-Z', '', "BASH: CD: /NONEXISTENT-DIR: NO SUCH FILE OR DIRECTORY\n", '', 0],
    '-n stops after N characters, the rest stays for the next read' => ['read -n 3 a; echo "[$a]"; read b; echo "[$b]"', "abcdef\nxyz\n", "[abc]\n[def]\n", '', 0],
    '-n stops early at the delimiter' => ['read -n 10 a; echo "[$a]"; read b; echo "[$b]"', "ab\ncd\n", "[ab]\n[cd]\n", '', 0],
    '-n splits fields' => ['read -n 3 a b; echo "[$a][$b]"; read -n 5 c d; echo "[$c][$d]"', "a bcdef\nx y z  \n", "[a][b]\n[cdef][]\n", '', 0],
    '-n fails at EOF' => ['read -n 2 a; echo "rc=$? [$a]"', 'a', "rc=1 [a]\n", '', 0],
    '-n 0 reads nothing' => ['read -n 0 a; echo "rc=$? [$a]"', "abc\n", "rc=0 []\n", '', 0],
    '-n with -r counts a backslash' => ['read -n 3 -r a; echo "[$a]"', "a\\bcd\n", "[a\\b]\n", '', 0],
    '-n counts an escaped character once' => ['read -n 3 a; echo "[$a]"', "a\\bcd\n", "[abc]\n", '', 0],
    '-n skips a line continuation' => ['read -n 3 a; echo "rc=$? [$a]"', "a\\\nbcd", "rc=0 [abc]\n", '', 0],
    '-n drops a backslash at EOF' => ['read -n 3 a; echo "rc=$? [$a]"', 'ab\\', "rc=1 [ab]\n", '', 0],
    '-n with -d stops at that delimiter' => ['read -n 3 -d , a; echo "[$a]"', "a,bcd\n", "[a]\n", '', 0],
    '-n with -d "" stops at NUL' => ['read -d "" -n 2 a; echo "rc=$? [$a]"', "abc\n", "rc=0 [ab]\n", '', 0],
    '-n leaves REPLY untrimmed' => ['read -n 3; echo "[$REPLY]"', "  abc\n", "[  a]\n", '', 0],
    '-n reads a lone newline as an empty line' => ['read -n 1 a; echo "rc=$? [$a]"', "\nx", "rc=0 []\n", '', 0],
    '-n with -a' => ['read -n1 -a arr; echo "rc=$? [${arr[*]}]"', 'ab', "rc=0 [a]\n", '', 0],
    'grouped -rn2' => ['read -rn2 a; echo "[$a]"; read -rd, b; echo "[$b]"', "abc,d\n", "[ab]\n[c]\n", '', 0],
    '-N reads through newlines' => ['read -N 4 a; echo "[$a]"; read b; echo "[$b]"', "ab\ncd\n", "[ab\nc]\n[d]\n", '', 0],
    '-N does not split or trim' => ['read -N 5 a b; echo "[$a][$b]"; IFS=: read -N 5 c d; echo "[$c][$d]"', "x y zx:y:z\n", "[x y z][]\n[x:y:z][]\n", '', 0],
    '-N keeps surrounding blanks' => ['read -N 5 a; echo "rc=$? [$a]"; read -N 3; echo "[$REPLY]"', "  abc  abc\n", "rc=0 [  abc]\n[  a]\n", '', 0],
    '-N fails at EOF' => ['read -N 5 a; echo "rc=$? [$a]"', 'ab', "rc=1 [ab]\n", '', 0],
    '-N ignores -d' => ['read -N 3 -d , a; echo "[$a]"', "a,bcd\n", "[a,b]\n", '', 0],
    '-N still handles escapes' => ['read -N 3 a; echo "[$a]"; read -N 3 b; echo "rc=$? [$b]"', "a\\bcd\\\nef", "[abc]\nrc=0 [def]\n", '', 0],
    '-N 1 can read a newline' => ['read -N 1 a; echo "rc=$? [$a]"', "\n", "rc=0 [\n]\n", '', 0],
    '-N with -a makes one element' => ['read -N4 -a arr; echo "rc=$? [${arr[*]}] ${#arr[@]}"', 'a b c', "rc=0 [a b ] 1\n", '', 0],
    'invalid counts' => ['read -n x a; echo "rc=$?"; read -n -1 a; echo "rc=$?"', "abc\n", "rc=1\nrc=1\n", "bash: read: x: invalid number\nbash: read: -1: invalid number\n", 0],
    '-t 0 reports input without reading it' => ['a=x; read -t 0 a; echo "rc=$? [$a]"; read b; echo "[$b]"', "abc\n", "rc=0 [x]\n[abc]\n", '', 0],
    '-t 0 succeeds at EOF too' => ['read -t 0; echo "rc=$?"', '', "rc=0\n", '', 0],
    'a timeout never expires on input that is all there' => ['read -t 1.5 a; echo "rc=$? [$a]"; read -t .5 b; echo "rc=$? [$b]"', "abc\n", "rc=0 [abc]\nrc=1 []\n", '', 0],
    '-t at EOF fails like a plain read' => ['read -t 1 a; echo "rc=$? [$a]"', '', "rc=1 []\n", '', 0],
    'invalid timeouts' => ['read -t x a; echo "rc=$?"; read -t -1 a; echo "rc=$?"', "abc\n", "rc=1\nrc=1\n", "bash: read: x: invalid timeout specification\nbash: read: -1: invalid timeout specification\n", 0],
    'no prompt when input is not a terminal' => ['read -p "P> " a; echo "rc=$? [$a]"', "abc\n", "rc=0 [abc]\n", '', 0],
    '-s -e -i -E change nothing without a terminal' => ['read -s -e -i def a; echo "rc=$? [$a]"; read -E b; echo "[$b]"', "abc\nxyz\n", "rc=0 [abc]\n[xyz]\n", '', 0],
    'invalid option' => ['read -x a; echo "rc=$?"', "abc\n", "rc=2\n", "bash: read: -x: invalid option\nread: usage: read [-Eers] [-a array] [-d delim] [-i text] [-n nchars] [-N nchars] [-p prompt] [-t timeout] [-u fd] [name ...]\n", 0],
    'missing option argument' => ['read -n; echo "rc=$?"', "abc\n", "rc=2\n", "bash: read: -n: option requires an argument\nread: usage: read [-Eers] [-a array] [-d delim] [-i text] [-n nchars] [-N nchars] [-p prompt] [-t timeout] [-u fd] [name ...]\n", 0],
    '-- ends the options' => ['read -- a; echo "rc=$? [$a]"', "abc\n", "rc=0 [abc]\n", '', 0],
    'invalid -u' => ['read -u x a; echo "rc=$?"', "abc\n", "rc=1\n", "bash: read: x: invalid file descriptor specification\n", 0],
    '-u 0 is stdin' => ['read -u 0 a; echo "rc=$? [$a]"', "abc\n", "rc=0 [abc]\n", '', 0],
    '-u on an output fd is a read error' => ['read -u 1 a; echo "rc=$? [$a]"', "abc\n", "rc=1 []\n", "bash: read: 1: read error: Bad file descriptor\n", 0],
    '-u is checked before -t 0' => ['read -t 0 -u 3 a; echo "rc=$? [$a]"', "abc\n", "rc=1 []\n", "bash: read: 3: invalid file descriptor: Bad file descriptor\n", 0],
    'umask shows the mask in octal, symbolically and reusably' => ['umask 0022; umask; umask -S; umask -p; umask -pS; umask -S -p', '', "0022\nu=rwx,g=rx,o=rx\numask 0022\numask -S u=rwx,g=rx,o=rx\numask -S u=rwx,g=rx,o=rx\n", '', 0],
    'umask with a number' => ['umask 027; umask; umask -S; umask 1; umask; umask 07777; umask; umask -- 044; umask; umask 00022; umask', '', "0027\nu=rwx,g=rx,o=\n0001\n7777\n0044\n0022\n", '', 0],
    'umask -S with a mode prints the new mask, -p does not' => ['umask -S 077; echo rc=$?; umask -p 0022; umask', '', "u=rwx,g=,o=\nrc=0\n0022\n", '', 0],
    'umask symbolic modes set what files may get' => ['umask 0022; umask u=rwx,g=rx,o=; umask; umask o+w,g-r; umask; umask a=; umask; umask =r; umask; umask ugo+rwx; umask', '', "0027\n0065\n0777\n0333\n0000\n", '', 0],
    'umask symbolic modes chain operators and copy classes' => ['umask 0022; umask u=g; umask; umask 0022; umask u=rw+x; umask; umask ug=rw-w; umask; umask 0022; umask go=u-w; umask', '', "0222\n0022\n0332\n0022\n", '', 0],
    'umask X adds execute only where some is allowed, s and t do nothing' => ['umask 0177; umask u+X; umask; umask 0166; umask a+X; umask; umask u+st; umask', '', "0177\n0066\n0066\n", '', 0],
    'umask rejects bad modes and keeps the old one' => ['umask 0022; umask x=r; echo rc=$?; umask u=rz; echo rc=$?; umask 8; echo rc=$?; umask 1x; echo rc=$?; umask', '', "rc=1\nrc=1\nrc=1\nrc=1\n0022\n", "bash: umask: `x': invalid symbolic mode operator\nbash: umask: `z': invalid symbolic mode character\nbash: umask: 8: octal number out of range\nbash: umask: 1x: octal number out of range\n", 0],
    'umask rejects a trailing comma' => ['umask u=r,; echo rc=$?', '', "rc=1\n", "bash: umask: `\0': invalid symbolic mode operator\n", 0],
    'umask rejects bad options' => ['umask -x; echo rc=$?', '', "rc=2\n", "bash: umask: -x: invalid option\numask: usage: umask [-p] [-S] [mode]\n", 0],
    // Unlike chmod's mode_adjust(), bash's umask copies classes and tests X against the mask it started from.
    'umask copies classes from the starting mask' => ['umask 0022; umask u=r,g=u; umask; umask 0022; umask u=r,g+u; umask; umask 0177; umask u+x,g+X; umask; umask 0022; umask u=gw; umask', '', "0302\n0302\n0077\n0022\n", '', 0],
    'commands like chmod honour the shell umask' => ['touch f; chmod 0 f; umask 077; chmod +r f; ls -l f | cut -c1-10; umask 022; chmod +w f; ls -l f | cut -c1-10; umask u=rwx,g=rx,o=; chmod +x f; ls -l f | cut -c1-10', '', "-r--------\n-rw-------\n-rwx--x---\n", '', 0],
    'kill -l translates numbers and names' => ['kill -l 9 KILL sigkill SIGKILL kill 137 0 EXIT 15 +9 " 9"; kill -L 2', '', "KILL\n9\n9\n9\n9\nKILL\nEXIT\n0\nTERM\nKILL\nKILL\nINT\n", '', 0],
    'kill -l rejects what is no signal' => ['kill -l 99 300 128 x SIGEXIT sigerr; echo rc=$?; kill -l x 9; echo rc=$?', '', "rc=1\nKILL\nrc=1\n", "bash: kill: 99: invalid signal specification\nbash: kill: 300: invalid signal specification\nbash: kill: 128: invalid signal specification\nbash: kill: x: invalid signal specification\nbash: kill: SIGEXIT: invalid signal specification\nbash: kill: sigerr: invalid signal specification\nbash: kill: x: invalid signal specification\n", 0],
    'kill -l skips options after it' => ['kill -l -- 9; kill -l -9 | head -1', '', "KILL\n 1) SIGHUP\t 2) SIGINT\t 3) SIGQUIT\t 4) SIGILL\t 5) SIGTRAP\n", '', 0],
    'help prints the long form' => ['help true :', '', "true: true\n    Return a successful result.\n    \n    Exit Status:\n    Always succeeds.\n:: :\n    Null command.\n    \n    No effect; the command does nothing.\n    \n    Exit Status:\n    Always succeeds.\n", '', 0],
    'help -s and -d for several patterns' => ['help -s cd pwd "e*"; help -d cd nosuch; echo rc=$?', '', "cd: cd [-L|[-P [-e]]] [-@] [dir]\npwd: pwd [-LP]\necho: echo [-neE] [arg ...]\nenable: enable [-a] [-dnps] [-f filename] [name ...]\neval: eval [arg ...]\nexec: exec [-cl] [-a name] [command [argument ...]] [redirection ...]\nexit: exit [n]\nexport: export [-fn] [name[=value] ...] or export -p [-f]\ncd - Change the shell working directory.\nrc=0\n", '', 0],
    'help falls back to prefix matches' => ['help -d rea', '', "read - Read a line from the standard input and split it into fields.\nreadarray - Read lines from a file into an array variable.\nreadonly - Mark shell variables as unchangeable.\n", '', 0],
    'help -d [ test' => ['help -d "[" test', '', "[ - Evaluate conditional expression.\ntest - Evaluate conditional expression.\n", '', 0],
    'help with no match' => ['help nosuch; echo rc=$?', '', "rc=1\n", "bash: help: no help topics match `nosuch'.  Try `help help' or `man -k nosuch' or `info nosuch'.\n", 0],
    'help with a bad option' => ['help -x; echo rc=$?', '', "rc=2\n", "bash: help: -x: invalid option\nhelp: usage: help [-dms] [pattern ...]\n", 0],
    'help -- ends the options' => ['help -- -d; echo rc=$?', '', "rc=1\n", "bash: help: no help topics match `-d'.  Try `help help' or `man -k -d' or `info -d'.\n", 0],
    // bash's table on Linux/glibc (bash on macOS lists the 31 BSD signals): 32 and 33 are reserved, real-time signals are named from RTMIN and RTMAX.
    'kill -l lists the Linux signal table' => ['kill -l', '', " 1) SIGHUP\t 2) SIGINT\t 3) SIGQUIT\t 4) SIGILL\t 5) SIGTRAP\n 6) SIGABRT\t 7) SIGBUS\t 8) SIGFPE\t 9) SIGKILL\t10) SIGUSR1\n11) SIGSEGV\t12) SIGUSR2\t13) SIGPIPE\t14) SIGALRM\t15) SIGTERM\n16) SIGSTKFLT\t17) SIGCHLD\t18) SIGCONT\t19) SIGSTOP\t20) SIGTSTP\n21) SIGTTIN\t22) SIGTTOU\t23) SIGURG\t24) SIGXCPU\t25) SIGXFSZ\n26) SIGVTALRM\t27) SIGPROF\t28) SIGWINCH\t29) SIGIO\t30) SIGPWR\n31) SIGSYS\t34) SIGRTMIN\t35) SIGRTMIN+1\t36) SIGRTMIN+2\t37) SIGRTMIN+3\n38) SIGRTMIN+4\t39) SIGRTMIN+5\t40) SIGRTMIN+6\t41) SIGRTMIN+7\t42) SIGRTMIN+8\n43) SIGRTMIN+9\t44) SIGRTMIN+10\t45) SIGRTMIN+11\t46) SIGRTMIN+12\t47) SIGRTMIN+13\n48) SIGRTMIN+14\t49) SIGRTMIN+15\t50) SIGRTMAX-14\t51) SIGRTMAX-13\t52) SIGRTMAX-12\n53) SIGRTMAX-11\t54) SIGRTMAX-10\t55) SIGRTMAX-9\t56) SIGRTMAX-8\t57) SIGRTMAX-7\n58) SIGRTMAX-6\t59) SIGRTMAX-5\t60) SIGRTMAX-4\t61) SIGRTMAX-3\t62) SIGRTMAX-2\n63) SIGRTMAX-1\t64) SIGRTMAX\t\n", '', 0],
    // bash also matches help topics for keywords and `variables`/`%`/`!`, which BashBox has no text for.
    'help with a glob first names the keywords' => ['help -s "r*"; help -d "?" "*s"', '', "Shell commands matching keyword `r*'\n\nread: read [-Eers] [-a array] [-d delim] [-i text] [-n nchars] [-N nchars] [-p prompt] [-t timeout] [-u fd] [name ...]\nreadarray: readarray [-d delim] [-n count] [-O origin] [-s count] [-t] [-u fd] [-C callback] [-c quantum] [array]\nreadonly: readonly [-aAf] [name[=value] ...] or readonly -p\nreturn: return [n]\nShell commands matching keywords `?, *s'\n\n. - Execute commands from a file in the current shell.\n: - Null command.\n[ - Evaluate conditional expression.\nalias - Define or display aliases.\ndirs - Display directory stack.\ngetopts - Parse option arguments.\njobs - Display status of jobs.\ntimes - Display process times.\nunalias - Remove each NAME from the list of defined aliases.\n", '', 0],
    // The version line is BashBox's BASH_VERSION on Linux.
    'help -d wins over -m and -s, -m over -s' => ['help -ds cd; help -dm cd; help -sm true', '', "cd - Change the shell working directory.\ncd - Change the shell working directory.\nNAME\n    true - Return a successful result.\n\nSYNOPSIS\n    true\n\nDESCRIPTION\n    Return a successful result.\n    \n    Exit Status:\n    Always succeeds.\n\nSEE ALSO\n    bash(1)\n\nIMPLEMENTATION\n    GNU bash, version 5.2.0(1)-release (x86_64-pc-linux-gnu)\n    Copyright (C) 2025 Free Software Foundation, Inc.\n    License GPLv3+: GNU GPL version 3 or later <http://gnu.org/licenses/gpl.html>\n\n", '', 0],
]);

test('help without a pattern lists the synopses in two columns, starring disabled builtins', function (): void {
    // The layout of bash's own listing, over the builtins BashBox has.
    expect((new Bash)->exec('enable -n cd; help')->stdout)->toBe("GNU bash, version 5.2.0(1)-release (x86_64-pc-linux-gnu)\nThese shell commands are defined internally.  Type `help' to see this list.\nType `help name' to find out more about the function `name'.\nUse `info bash' to find out more about the shell in general.\nUse `man -k' or `info' to find out more about commands not in this list.\n\nA star (*) next to a name means that the command is disabled.\n\n . [-p path] filename [arguments]        kill [-s sigspec | -n signum | -sigs>\n :                                       let arg [arg ...]\n [ arg... ]                              local [option] name[=value] ...\n alias [-p] [name[=value] ... ]          logout [n]\n bg [job_spec ...]                       mapfile [-d delim] [-n count] [-O or>\n break [n]                               popd [-n] [+N | -N]\n builtin [shell-builtin [arg ...]]       printf [-v var] format [arguments]\n caller [expr]                           pushd [-n] [+N | -N | dir]\n*cd [-L|[-P [-e]]] [-@] [dir]            pwd [-LP]\n command [-pVv] command [arg ...]        read [-Eers] [-a array] [-d delim] [>\n compgen [-V varname] [-abcdefgjksuv] >  readarray [-d delim] [-n count] [-O >\n complete [-abcdefgjksuv] [-pr] [-DEI]>  readonly [-aAf] [name[=value] ...] o>\n compopt [-o|+o option] [-DEI] [name .>  return [n]\n continue [n]                            set [-abefhkmnptuvxBCEHPT] [-o optio>\n declare [-aAfFgiIlnrtux] [name[=value>  shift [n]\n dirs [-clpv] [+N] [-N]                  shopt [-pqsu] [-o] [optname ...]\n disown [-h] [-ar] [jobspec ... | pid >  source [-p path] filename [arguments>\n echo [-neE] [arg ...]                   suspend [-f]\n enable [-a] [-dnps] [-f filename] [na>  test [expr]\n eval [arg ...]                          times\n exec [-cl] [-a name] [command [argume>  trap [-Plp] [[action] signal_spec ..>\n exit [n]                                true\n export [-fn] [name[=value] ...] or ex>  type [-afptP] name [name ...]\n false                                   typeset [-aAfFgiIlnrtux] name[=value>\n fg [job_spec]                           ulimit [-SHabcdefiklmnpqrstuvxPRT] [>\n getopts optstring name [arg ...]        umask [-p] [-S] [mode]\n hash [-lr] [-p pathname] [-dt] [name >  unalias [-a] name [name ...]\n help [-dms] [pattern ...]               unset [-f] [-v] [-n] [name ...]\n jobs [-lnprs] [jobspec ...] or jobs ->  wait [-fn] [-p var] [id ...]\n");
});

test('time reports real, user and system time in the requested format', function (string $script, string $stderr, string $stdout = ''): void {
    $bashExecResult = (new Bash)->exec($script);

    expect($bashExecResult->stdout)->toBe($stdout)
        ->and($bashExecResult->stderr)->toMatch($stderr)
        ->and($bashExecResult->exitCode)->toBe(0);
})->with([
    'the default format' => ['time true', "/^\nreal\t0m0\\.\\d{3}s\nuser\t0m0\\.\\d{3}s\nsys\t0m0\\.\\d{3}s\n$/"],
    'time -p is POSIX, whatever TIMEFORMAT says' => ['TIMEFORMAT=x; time -p true', "/^real 0\\.\\d{2}\nuser 0\\.\\d{2}\nsys 0\\.\\d{2}\n$/"],
    'precision, long form, %% and a trailing %' => ['TIMEFORMAT="%0R|%0lR|%%|%2U %3S|a%"; time true', "/^0\\|0m0s\\|%\\|0\\.\\d{2} 0\\.\\d{3}\\|a%\n$/"],
    'up to six places, %E for real' => ['TIMEFORMAT="%6lE %9R"; time true', "/^0m0\\.\\d{6}s 0\\.\\d{6}\n$/"],
    // bash scales %P for milliseconds but prints it as microseconds, so the fraction is always .00.
    'CPU percentage' => ['TIMEFORMAT=%P; time true', "/^\\d+\\.00\n$/"],
    'an empty TIMEFORMAT prints nothing' => ['TIMEFORMAT=; time true', '/^$/'],
    'an invalid format character' => ['TIMEFORMAT="x%y"; time true', "/^bash: TIMEFORMAT: `y': invalid format character\n$/"],
    'a format ending in a precision' => ['TIMEFORMAT="%3"; time true', "/^bash: TIMEFORMAT: `\\x00': invalid format character\n$/"],
    'l does not apply to %P' => ['TIMEFORMAT="%lP"; time true', "/^bash: TIMEFORMAT: `P': invalid format character\n$/"],
    "the report goes to the shell's stderr, not the command's" => ['TIMEFORMAT=%0R; time echo hi 2>/dev/null', "/^0\n$/", "hi\n"],
    'a redirected group around time catches it' => ['TIMEFORMAT=%0R; { time echo hi; } 2>/dev/null', '/^$/', "hi\n"],
]);

test('time measures the CPU time the pipeline used', function (): void {
    $bashExecResult = (new Bash)->exec('TIMEFORMAT="%3U %3S %3R"; time for i in $(seq 1 3000); do x=$((i * 2)); done');

    expect($bashExecResult->stderr)->toMatch("/^\\d+\\.\\d{3} \\d+\\.\\d{3} \\d+\\.\\d{3}\n$/");
    [$user, $sys, $real] = array_map(floatval(...), explode(' ', trim($bashExecResult->stderr)));
    expect($user + $sys)->toBeGreaterThan(0.0)
        ->and($user + $sys)->toBeLessThanOrEqual($real + 0.01);
});
