<?php

use Strukt\Process;
use Strukt\ProcessSupervisor;

test('the supervisor helper spawns the configured number of workers', function (): void {
    $supervisor = supervisor(3, 'sleep 2');

    expect($supervisor)->toBeInstanceOf(ProcessSupervisor::class)
        ->and($supervisor->count())->toBe(3)
        ->and($supervisor->processes())->toHaveCount(3)
        ->and($supervisor->active())->toBe(3)
        ->and($supervisor->running())->toBeTrue()
        ->and($supervisor->totalRestarts())->toBe(0);

    $supervisor->stop();
});

test('a dead worker is restarted in place and keeps the same slot index', function (): void {
    $supervisor = new ProcessSupervisor(2);
    $supervisor->start('sleep 30');

    expect($supervisor->active())->toBe(2);

    // Kill the first worker behind the supervisor's back.
    $supervisor->process(0)->terminate();
    usleep(200000);

    $supervisor->supervise();

    expect($supervisor->active())->toBe(2)
        ->and($supervisor->processes())->toHaveCount(2)
        ->and($supervisor->restarts(0))->toBe(1)
        ->and($supervisor->restarts(1))->toBe(0)
        ->and($supervisor->totalRestarts())->toBe(1)
        ->and($supervisor->process(0))->toBeInstanceOf(Process::class)
        ->and($supervisor->process(0)->isRunning())->toBeTrue();

    $supervisor->stop();
});

test('a worker that exits cleanly is also replaced', function (): void {
    $supervisor = new ProcessSupervisor(1);
    $supervisor->start('sh -c "exit 0"');
    usleep(200000);

    $supervisor->supervise();

    expect($supervisor->restarts(0))->toBe(1)
        ->and($supervisor->process(0)->isRunning())->toBeTrue();

    $supervisor->stop();
});

test('onExit fires for every restart with the slot index and exit code', function (): void {
    $exits = [];
    $supervisor = new ProcessSupervisor(1);
    $supervisor->onExit(static function (int $index, int $code) use (&$exits): void {
        $exits[] = [$index, $code];
    });
    $supervisor->start('sh -c "exit 7"');
    usleep(200000);

    $supervisor->supervise();

    expect($exits)->toHaveCount(1)
        ->and($exits[0])->toBe([0, 7]);

    $supervisor->stop();
});

test('supervise drains worker output before deciding to restart', function (): void {
    $supervisor = new ProcessSupervisor(1);
    $supervisor->start('printf "last words"; exit 1');
    usleep(200000);

    $lines = [];
    $supervisor->supervise(static function (int $index, string $line) use (&$lines): void {
        $lines[] = $line;
    });

    expect(implode('', $lines))->toBe('last words')
        ->and($supervisor->restarts(0))->toBe(1);

    $supervisor->stop();
});

test('a restart cap leaves the slot dead once exhausted', function (): void {
    $supervisor = new ProcessSupervisor(1, restartCap: 2);
    $supervisor->start('sh -c "exit 1"');

    // Let the worker die, then run the full supervision cycle three times.
    for ($i = 0; $i < 3; $i++) {
        usleep(200000);
        $supervisor->supervise();
    }

    expect($supervisor->restarts(0))->toBe(2)
        ->and($supervisor->totalRestarts())->toBe(2)
        ->and($supervisor->active())->toBe(0)
        ->and($supervisor->running())->toBeFalse();

    $supervisor->stop();
});

test('onExit still fires once when the restart cap blocks a respawn', function (): void {
    $exits = [];
    $supervisor = new ProcessSupervisor(1, restartCap: 0);
    $supervisor->onExit(static function (int $index, int $code) use (&$exits): void {
        $exits[] = [$index, $code];
    });
    $supervisor->start('sh -c "exit 3"');

    // First pass: die + exit hook fires once, but no restart is allowed.
    usleep(200000);
    $supervisor->supervise();
    expect($exits)->toHaveCount(1)
        ->and($supervisor->restarts(0))->toBe(0);

    // Later passes must not re-notify the already-reported death.
    usleep(200000);
    $supervisor->supervise();
    expect($exits)->toHaveCount(1);

    $supervisor->stop();
});

test('a backoff throttles how fast the same slot is restarted', function (): void {
    $supervisor = new ProcessSupervisor(1, backoff: 0.3);
    $supervisor->start('sh -c "exit 1"');
    usleep(200000);

    // First restart is allowed immediately.
    $supervisor->supervise();
    expect($supervisor->restarts(0))->toBe(1);

    // A second supervision within the backoff window must not restart again.
    usleep(50000);
    $supervisor->supervise();
    expect($supervisor->restarts(0))->toBe(1);

    // After the backoff elapses the slot may be restarted again.
    usleep(400000);
    $supervisor->supervise();

    expect($supervisor->restarts(0))->toBe(2);

    $supervisor->stop();
});

test('supervise on an untouched supervisor is inert', function (): void {
    $supervisor = new ProcessSupervisor(2);

    expect($supervisor->active())->toBe(0)
        ->and($supervisor->running())->toBeFalse();

    $supervisor->supervise();

    expect($supervisor->totalRestarts())->toBe(0);
});

test('stop terminates workers and returns their exit codes', function (): void {
    $supervisor = new ProcessSupervisor(2);
    $supervisor->start('sleep 30');

    $codes = $supervisor->stop();

    expect($supervisor->active())->toBe(0)
        ->and($supervisor->running())->toBeFalse()
        ->and($codes)->toHaveCount(2)
        ->and($codes)->each->toBeInt();
});