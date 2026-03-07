<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions(
        cwd: '/home/user',
        env: ['USER' => 'testuser', 'HOME' => '/home/user'],
    ));
});

// ===== printf =====
test('printf basic', function (): void {
    $result = $this->bash->exec('printf "%s %s\n" hello world');
    expect($result->stdout)->toBe("hello world\n");
});

test('printf format string with number', function (): void {
    $result = $this->bash->exec('printf "%d\n" 42');
    expect($result->stdout)->toBe("42\n");
});

// ===== cat with file =====
test('cat reads file', function (): void {
    $this->bash->writeFile('/tmp/file.txt', "hello\nworld\n");
    $result = $this->bash->exec('cat /tmp/file.txt');
    expect($result->stdout)->toBe("hello\nworld\n");
});

test('cat with -n flag', function (): void {
    $this->bash->writeFile('/tmp/file.txt', "a\nb\n");
    $result = $this->bash->exec('cat -n /tmp/file.txt');
    expect($result->stdout)->toContain('1');
    expect($result->stdout)->toContain('a');
    expect($result->stdout)->toContain('2');
    expect($result->stdout)->toContain('b');
});

// ===== head =====
test('head reads first lines', function (): void {
    $this->bash->writeFile('/tmp/lines.txt', implode("\n", range(1, 20))."\n");
    $result = $this->bash->exec('head -n 3 /tmp/lines.txt');
    expect($result->stdout)->toBe("1\n2\n3\n");
});

// ===== tail =====
test('tail reads last lines', function (): void {
    $this->bash->writeFile('/tmp/lines.txt', implode("\n", range(1, 20))."\n");
    $result = $this->bash->exec('tail -n 3 /tmp/lines.txt');
    expect($result->stdout)->toBe("18\n19\n20\n");
});

// ===== wc =====
test('wc counts lines', function (): void {
    $result = $this->bash->exec('echo -e "a\nb\nc" | wc -l');
    expect(trim((string) $result->stdout))->toBe('3');
});

// ===== sort =====
test('sort sorts lines', function (): void {
    $result = $this->bash->exec('printf "c\na\nb\n" | sort');
    expect($result->stdout)->toBe("a\nb\nc\n");
});

// ===== uniq =====
test('uniq removes duplicates', function (): void {
    $result = $this->bash->exec('printf "a\na\nb\nb\nc\n" | uniq');
    expect($result->stdout)->toBe("a\nb\nc\n");
});

// ===== tr =====
test('tr translates characters', function (): void {
    $result = $this->bash->exec('echo hello | tr "a-z" "A-Z"');
    expect($result->stdout)->toBe("HELLO\n");
});

// ===== grep =====
test('grep matches lines', function (): void {
    $result = $this->bash->exec('printf "apple\nbanana\napricot\n" | grep "ap"');
    expect($result->stdout)->toBe("apple\napricot\n");
});

// ===== cut =====
test('cut extracts fields', function (): void {
    $result = $this->bash->exec('echo "a:b:c" | cut -d: -f2');
    expect($result->stdout)->toBe("b\n");
});

// ===== seq =====
test('seq generates sequence', function (): void {
    $result = $this->bash->exec('seq 3');
    expect($result->stdout)->toBe("1\n2\n3\n");
});

test('seq with start and end', function (): void {
    $result = $this->bash->exec('seq 2 5');
    expect($result->stdout)->toBe("2\n3\n4\n5\n");
});

// ===== basename/dirname =====
test('basename extracts filename', function (): void {
    $result = $this->bash->exec('basename /path/to/file.txt');
    expect($result->stdout)->toBe("file.txt\n");
});

test('dirname extracts directory', function (): void {
    $result = $this->bash->exec('dirname /path/to/file.txt');
    expect($result->stdout)->toBe("/path/to\n");
});

// ===== true/false =====
test('true returns 0', function (): void {
    $result = $this->bash->exec('true');
    expect($result->exitCode)->toBe(0);
});

test('false returns 1', function (): void {
    $result = $this->bash->exec('false');
    expect($result->exitCode)->toBe(1);
});

// ===== rev =====
test('rev reverses lines', function (): void {
    $result = $this->bash->exec('echo hello | rev');
    expect($result->stdout)->toBe("olleh\n");
});

// ===== base64 =====
test('base64 encodes', function (): void {
    $result = $this->bash->exec('echo -n hello | base64');
    expect(trim((string) $result->stdout))->toBe('aGVsbG8=');
});

// ===== ls =====
test('ls lists files', function (): void {
    $this->bash->exec('echo x > /tmp/a.txt');
    $this->bash->exec('echo y > /tmp/b.txt');

    $result = $this->bash->exec('ls /tmp');
    expect($result->stdout)->toContain('a.txt');
    expect($result->stdout)->toContain('b.txt');
});

// ===== mkdir and rmdir =====
test('mkdir creates directory', function (): void {
    $result = $this->bash->exec('mkdir /tmp/newdir && [[ -d /tmp/newdir ]]');
    expect($result->exitCode)->toBe(0);
});

test('mkdir -p creates nested', function (): void {
    $result = $this->bash->exec('mkdir -p /tmp/a/b/c && [[ -d /tmp/a/b/c ]]');
    expect($result->exitCode)->toBe(0);
});

// ===== rm =====
test('rm removes file', function (): void {
    $this->bash->exec('echo x > /tmp/del.txt');
    $result = $this->bash->exec('rm /tmp/del.txt && [[ ! -f /tmp/del.txt ]]');
    expect($result->exitCode)->toBe(0);
});

// ===== cp =====
test('cp copies file', function (): void {
    $this->bash->exec('echo content > /tmp/src.txt');
    $result = $this->bash->exec('cp /tmp/src.txt /tmp/dst.txt && cat /tmp/dst.txt');
    expect($result->stdout)->toBe("content\n");
});

// ===== mv =====
test('mv moves file', function (): void {
    $this->bash->exec('echo data > /tmp/old.txt');
    $result = $this->bash->exec('mv /tmp/old.txt /tmp/new.txt && cat /tmp/new.txt');
    expect($result->stdout)->toBe("data\n");
});

// ===== touch =====
test('touch creates file', function (): void {
    $result = $this->bash->exec('touch /tmp/touched.txt && [[ -f /tmp/touched.txt ]]');
    expect($result->exitCode)->toBe(0);
});

// ===== tee =====
test('tee writes to file and stdout', function (): void {
    $result = $this->bash->exec('echo hello | tee /tmp/tee.txt');
    expect($result->stdout)->toBe("hello\n");
    expect($this->bash->readFile('/tmp/tee.txt'))->toBe("hello\n");
});

// ===== env/printenv =====
test('env shows variables', function (): void {
    $result = $this->bash->exec('export FOO=bar; env');
    expect($result->stdout)->toContain('FOO=bar');
});

// ===== which =====
test('which finds commands', function (): void {
    $result = $this->bash->exec('which echo');
    expect($result->exitCode)->toBe(0);
});

// ===== pipe chains =====
test('multi-pipe chain', function (): void {
    $result = $this->bash->exec('echo "hello world" | tr " " "\n" | sort');
    expect($result->stdout)->toBe("hello\nworld\n");
});

// ===== complex scripts =====
test('fizzbuzz', function (): void {
    $script = 'for i in $(seq 1 15); do if (( i % 15 == 0 )); then echo FizzBuzz; elif (( i % 3 == 0 )); then echo Fizz; elif (( i % 5 == 0 )); then echo Buzz; else echo $i; fi; done';
    $result = $this->bash->exec($script);
    $lines = explode("\n", trim((string) $result->stdout));
    expect($lines[0])->toBe('1');
    expect($lines[2])->toBe('Fizz');
    expect($lines[4])->toBe('Buzz');
    expect($lines[14])->toBe('FizzBuzz');
});

// ===== which extended =====
test('which not found returns exit code 1', function (): void {
    expect($this->bash->exec('which nonexistent_command_xyz')->exitCode)->toBe(1);
});

// ===== seq extended =====
test('seq with format flag', function (): void {
    $result = $this->bash->exec('seq -f "num:%g" 3');
    expect($result->stdout)->toContain('num:1', 'num:2', 'num:3');
});

test('seq with separator flag', function (): void {
    expect($this->bash->exec('seq -s "," 3')->stdout)->toBe("1,2,3\n");
});

// ===== xargs extended =====
test('xargs with -n option splits into chunks', function (): void {
    expect($this->bash->exec('echo "a b c d" | xargs -n2 echo')->stdout)->toBe("a b\nc d\n");
});

// ===== cut extended =====
test('cut with -b bytes flag', function (): void {
    expect($this->bash->exec('echo "hello world" | cut -b1-5')->stdout)->toBe("hello\n");
});

test('cut -b counts bytes for multibyte input', function (): void {
    expect(bin2hex($this->bash->exec('printf "éx" | cut -b1')->stdout))->toBe('c30a');
});

test('cut with --complement flag', function (): void {
    expect($this->bash->exec('echo "a:b:c" | cut -d: --complement -f2')->stdout)->toBe("a:c\n");
});

// ===== head/tail extended =====
test('head with -c bytes flag', function (): void {
    expect($this->bash->exec('echo "hello world" | head -c5')->stdout)->toBe('hello');
});

test('head -c counts bytes for multibyte input', function (): void {
    expect(bin2hex($this->bash->exec('printf "é" | head -c1')->stdout))->toBe('c3');
});

test('tail with -c bytes flag', function (): void {
    expect($this->bash->exec('echo "hello world" | tail -c5')->stdout)->toBe("orld\n");
});

test('tail -c counts bytes for multibyte input', function (): void {
    expect(bin2hex($this->bash->exec('printf "é" | tail -c1')->stdout))->toBe('a9');
});

test('tail -c0 returns empty output', function (): void {
    expect($this->bash->exec('printf "hello" | tail -c0')->stdout)->toBe('');
});

test('seq with invalid format flag fails cleanly', function (): void {
    $result = $this->bash->exec('seq -f "%q" 3');

    expect($result->exitCode)->toBe(1);
    expect($result->stderr)->toContain('invalid format string');
});

// ===== ls extended =====
test('ls with -d directory flag', function (): void {
    $this->bash->exec('mkdir -p /tmp/lsdtest');
    $result = $this->bash->exec('ls -d /tmp/lsdtest');
    expect($result->exitCode)->toBe(0);
    expect(trim((string) $result->stdout))->toBe('lsdtest');
});

// ===== cp/mv extended =====
test('cp with -p preserve flag', function (): void {
    $this->bash->exec('echo "preserve" > /tmp/cppreserve.txt');
    $result = $this->bash->exec('cp -p /tmp/cppreserve.txt /tmp/cppreserve2.txt');
    expect($result->exitCode)->toBe(0);
    expect($this->bash->exec('test -f /tmp/cppreserve2.txt')->exitCode)->toBe(0);
});

test('mv with -f force flag', function (): void {
    $this->bash->exec('echo "old" > /tmp/mvforce.txt');
    $this->bash->exec('echo "new" > /tmp/mvforce2.txt');
    expect($this->bash->exec('mv -f /tmp/mvforce2.txt /tmp/mvforce.txt')->exitCode)->toBe(0);
    expect($this->bash->exec('cat /tmp/mvforce.txt')->stdout)->toContain('new');
});
