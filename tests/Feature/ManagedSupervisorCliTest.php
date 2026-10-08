<?php

use Strukt\ManagedSupervisor;

beforeEach(function (): void {
    if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
        test()->markTestSkipped('pcntl/posix not available');
    }
});

afterEach(function (): void {
    foreach (ManagedSupervisor::ps($this->dir ?? '.strukt-test') as $name => $state) {
        ManagedSupervisor::stop($name, $this->dir ?? '.strukt-test');
    }
});

function supervise_cli(array $argv): array
{
    $cmd = implode(' ', array_map('escapeshellarg', array_merge([PHP_BINARY, 'bin/supervise'], $argv)));
    exec($cmd . ' 2>&1', $out, $code);

    return [$code, implode("\n", $out)];
}

function wait_for(callable $fn, int $timeoutMs = 5000): void
{
    $deadline = microtime(true) + $timeoutMs / 1000;
    while (microtime(true) < $deadline) {
        if ($fn()) {
            return;
        }
        usleep(50_000);
    }

    throw new RuntimeException('Timed out waiting for condition.');
}

test('start daemonizes a supervised pool, then status, ps and logs observe it', function (): void {
    $this->dir = sys_get_temp_dir() . '/strukt-supervisor-feature-' . uniqid();
    $worker = __DIR__ . '/fixtures/beat.php';

    [$code, $out] = supervise_cli(['start', 'mail', PHP_BINARY . ' ' . $worker, '--dir=' . $this->dir, '--size=2']);

    expect($code)->toBe(0);

    wait_for(fn () => ManagedSupervisor::running('mail', $this->dir));
    wait_for(fn () => (ManagedSupervisor::status('mail', $this->dir)['active'] ?? 0) === 2);

    $state = ManagedSupervisor::status('mail', $this->dir);

    expect($state['running'])->toBeTrue()
        ->and($state['size'])->toBe(2)
        ->and($state['active'])->toBe(2)
        ->and($state['command'])->toContain('beat.php')
        ->and($state['workers'])->toHaveLength(2)
        ->and($state['workers'][0]['pid'])->toBeInt()
        ->and($state['workers'][0]['running'])->toBeTrue();

    [$psCode, $psOut] = supervise_cli(['ps', '--dir=' . $this->dir]);
    expect($psCode)->toBe(0)->and($psOut)->toContain('mail');

    [$statusCode, $statusOut] = supervise_cli(['status', 'mail', '--dir=' . $this->dir, '--json']);
    $decoded = json_decode($statusOut, true);

    expect($statusCode)->toBe(0)
        ->and($decoded['running'])->toBeTrue()
        ->and($decoded['active'])->toBe(2);

    [$logsCode, $logsOut] = supervise_cli(['logs', 'mail', '--dir=' . $this->dir, '--lines=10']);
    expect($logsCode)->toBe(0)
        ->and($logsOut)->toContain('beat')
        ->and($logsOut)->toContain('[slot 0]')
        ->and($logsOut)->toContain('[slot 1]');
});

test('killing a worker slot leaves the pool alive and the slot is restarted in place', function (): void {
    $this->dir = sys_get_temp_dir() . '/strukt-supervisor-kill-' . uniqid();
    $worker = __DIR__ . '/fixtures/beat.php';

    supervise_cli(['start', 'pool', PHP_BINARY . ' ' . $worker, '--dir=' . $this->dir, '--size=2']);
    wait_for(fn () => (ManagedSupervisor::status('pool', $this->dir)['active'] ?? 0) === 2);

    $pidBefore = ManagedSupervisor::status('pool', $this->dir)['workers'][0]['pid'];

    [$killCode, $killOut] = supervise_cli(['kill', 'pool', '0', '--dir=' . $this->dir]);

    expect($killCode)->toBe(0);

    wait_for(function () use ($pidBefore): bool {
        $state = ManagedSupervisor::status('pool', $this->dir);

        return $state !== null
            && $state['running']
            && $state['active'] === 2
            && $state['workers'][0]['pid'] !== $pidBefore
            && $state['workers'][0]['restarts'] >= 1;
    });

    $state = ManagedSupervisor::status('pool', $this->dir);

    expect($state['workers'][0]['restarts'])->toBeGreaterThanOrEqual(1)
        ->and($state['workers'][1]['restarts'])->toBe(0);
});

test('stop reaps the workers and removes the pid file', function (): void {
    $this->dir = sys_get_temp_dir() . '/strukt-supervisor-stop-' . uniqid();
    $worker = __DIR__ . '/fixtures/beat.php';

    supervise_cli(['start', 'pool', PHP_BINARY . ' ' . $worker, '--dir=' . $this->dir, '--size=1']);
    wait_for(fn () => (ManagedSupervisor::status('pool', $this->dir)['active'] ?? 0) === 1);

    [$stopCode, $stopOut] = supervise_cli(['stop', 'pool', '--dir=' . $this->dir]);

    expect($stopCode)->toBe(0);

    wait_for(fn () => !ManagedSupervisor::running('pool', $this->dir));

    $state = ManagedSupervisor::status('pool', $this->dir);

    expect($state['running'])->toBeFalse()
        ->and($state['active'])->toBe(0);
});

test('a stale pid file reports the supervisor as not running', function (): void {
    $this->dir = sys_get_temp_dir() . '/strukt-supervisor-stale-' . uniqid();

    [$code] = supervise_cli(['status', 'ghost', '--dir=' . $this->dir, '--json']);

    expect($code)->toBe(1);
});