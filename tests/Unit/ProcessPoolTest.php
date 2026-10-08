<?php

use Strukt\Process;
use Strukt\ProcessPool;

test('the pool helper spawns a fixed number of concurrent workers', function (): void {
    $pool = pool(3, 'sleep 2');

    expect($pool)->toBeInstanceOf(ProcessPool::class)
        ->and($pool->count())->toBe(3)
        ->and($pool->processes())->toHaveCount(3)
        ->and($pool->active())->toBe(3)
        ->and($pool->running())->toBeTrue();

    $pool->stop();
});

test('every worker in the pool is a separate process', function (): void {
    $pool = pool(3, 'php -r "echo getmypid(), PHP_EOL;"');
    usleep(300000);

    $pids = [];
    $pool->drain(static function (int $index, string $line) use (&$pids): void {
        $pids[$index] = trim($line);
    });

    $pool->stop();

    expect(array_values($pids))->toHaveCount(3)
        ->and(array_unique($pids))->toHaveCount(3);
});

test('drain relays each worker output line with its spawn index', function (): void {
    $pool = new ProcessPool(2);
    $pool->start(['sh', '-c', 'echo worker0; sleep 2']);
    usleep(300000);

    $lines = [];
    $pool->drain(static function (int $index, string $line) use (&$lines): void {
        $lines[] = [$index, trim($line)];
    });

    expect($lines)->toContain([0, 'worker0'])
        ->and($lines)->toContain([1, 'worker0']);

    $pool->stop();
});

test('drain without a callback still retains output on the workers', function (): void {
    $pool = new ProcessPool(1);
    $pool->start('printf "hello\n"');
    usleep(300000);

    $pool->drain();

    expect($pool->process(0)->output())->toBe("hello\n");

    $pool->stop();
});

test('a trailing line without a newline is delivered once the process exits', function (): void {
    $pool = new ProcessPool(1);
    $pool->start('printf "partial"');
    usleep(300000);

    $lines = [];
    $pool->drain(static function (int $index, string $line) use (&$lines): void {
        $lines[] = $line;
    });

    expect(implode('', $lines))->toBe('partial');

    $pool->stop();
});

test('stop terminates running workers and reaps their exit codes', function (): void {
    $pool = new ProcessPool(2);
    $pool->start('sleep 30');

    expect($pool->active())->toBe(2);

    $codes = $pool->stop();

    expect($pool->active())->toBe(0)
        ->and($pool->running())->toBeFalse()
        ->and($codes)->toHaveCount(2)
        ->and($codes)->each->toBeInt();
});

test('stop reaps string commands down to the real process, not the shell', function (): void {
    if (!is_dir('/proc')) {
        test()->markTestSkipped('requires /proc to inspect processes');
    }

    $running = static function (string $exact) : bool {
        $procs = glob('/proc/[0-9]*/cmdline') ?: [];
        foreach ($procs as $cmdline) {
            $args = explode("\0", (string) @file_get_contents($cmdline));
            unset($args[array_key_last($args)]);
            if (implode(' ', array_values($args)) === $exact) {
                return true;
            }
        }

        return false;
    };

    $pool = new ProcessPool(1);
    $pool->start('sleep 73');

    expect($running('sleep 73'))->toBeTrue();

    $pool->stop();

    expect($running('sleep 73'))->toBeFalse();
});

test('the pool is readonly once started', function (): void {
    $pool = new ProcessPool(1);

    $pool->start('sleep 1');

    $pool->start('sleep 1');
})->throws(RuntimeException::class, 'already been started');

test('an empty pool reports nothing running', function (): void {
    $pool = new ProcessPool(0);

    expect($pool->active())->toBe(0)
        ->and($pool->running())->toBeFalse()
        ->and($pool->stop())->toBe([]);
});

test('out-of-range worker access throws', function () {
    (new ProcessPool(1))->process(4);
})->throws(OutOfBoundsException::class);

test('restart replaces one worker slot and preserves its position', function (): void {
    $pool = new ProcessPool(2);
    $pool->start('sleep 30');

    $before = $pool->processes();
    $firstPid = $before[0]->status()['pid'];

    $pool->restart(0, 'sleep 30');

    expect($pool->processes())->toHaveCount(2)
        ->and($pool->active())->toBe(2)
        ->and($pool->process(0)->status()['pid'])->not->toBe($firstPid)
        ->and($pool->process(1)->status()['pid'])->toBe($before[1]->status()['pid']);

    $pool->stop();
});

test('restart accepts a replacement command', function (): void {
    $pool = new ProcessPool(1);
    $pool->start('sleep 30');

    $pool->restart(0, 'sh -c "echo replaced; sleep 30"');
    usleep(300000);

    $line = null;
    $pool->drain(static function (int $index, string $lineOut) use (&$line): void {
        $line = trim($lineOut);
    });

    expect($line)->toBe('replaced');

    $pool->stop();
});

test('restart on an unknown slot throws', function () {
    $pool = new ProcessPool(1);
    $pool->start('sleep 30');

    $pool->restart(4, 'sleep 30');
})->throws(OutOfBoundsException::class);