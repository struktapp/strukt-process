<?php

use Strukt\ManagedSupervisor;

test('the runtime directory defaults to the STRUKT_SUPERVISOR_DIR override', function (): void {
    putenv('STRUKT_SUPERVISOR_DIR=/tmp/strukt-personal');

    expect(ManagedSupervisor::defaultDir())->toBe('/tmp/strukt-personal');

    putenv('STRUKT_SUPERVISOR_DIR');
});

test('an unsafe supervisor name is rejected', function (): void {
    expect(fn () => new ManagedSupervisor('../evil', 1))->toThrow(RuntimeException::class);
});

test('a zero worker pool is rejected', function (): void {
    expect(fn () => new ManagedSupervisor('ok', 0))->toThrow(RuntimeException::class);
});

test('pid, state and log files live under the supervisor directory', function (): void {
    $dir = sys_get_temp_dir() . '/strukt-supervisor-files-' . uniqid();

    expect(ManagedSupervisor::pidFile('mail', $dir))->toBe("$dir/mail/supervise.pid")
        ->and(ManagedSupervisor::stateFile('mail', $dir))->toBe("$dir/mail/state.json")
        ->and(ManagedSupervisor::logFile('mail', $dir))->toBe("$dir/mail/output.log");
});

test('status, ps and tail return empty before anything is started', function (): void {
    $dir = sys_get_temp_dir() . '/strukt-supervisor-empty-' . uniqid();

    expect(ManagedSupervisor::status('mail', $dir))->toBeNull()
        ->and(ManagedSupervisor::ps($dir))->toBe([])
        ->and(ManagedSupervisor::tail('mail', 5, $dir))->toBe('')
        ->and(ManagedSupervisor::running('mail', $dir))->toBeFalse();
});

test('status repopulates the running flag from the pid file', function (): void {
    $dir = sys_get_temp_dir() . '/strukt-supervisor-state-' . uniqid();
    mkdir("$dir/mail", 0775, true);

    file_put_contents("$dir/mail/state.json", json_encode([
        'name' => 'mail',
        'pid' => getmypid(),
        'running' => true,
    ]));

    // No pid file yet, so the pool is reported as not running.
    $state = ManagedSupervisor::status('mail', $dir);

    expect($state)->not->toBeNull()
        ->and($state['name'])->toBe('mail')
        ->and($state['running'])->toBeFalse();

    // A live pid file makes the pool running again.
    file_put_contents("$dir/mail/supervise.pid", (string) getmypid());

    expect(ManagedSupervisor::status('mail', $dir)['running'])->toBeTrue();

    // A stale/dead pid flips the flag back to false.
    file_put_contents("$dir/mail/supervise.pid", '99999999');

    expect(ManagedSupervisor::status('mail', $dir)['running'])->toBeFalse();
});