# Quick start

The fastest path from a command to a supervised, externally visible daemon.
Each step builds on the previous one.

## 1. Run one command

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$result = process('printf "hello\n"');

echo $result->outputs(); // hello
```

`process()` runs the command to completion, drains output and error
concurrently, and returns a `Strukt\ProcessResult`. See
[running-commands.md](running-commands.md).

## 2. Stream lines instead of capturing

For a long-running or chatty command, pass a callback. Complete output lines
arrive as they are produced:

```php
process(
    'git log --oneline -5',
    static function (string $line): void {
        echo $line;
    },
);
```

## 3. Run several workers at once with a pool

A `ProcessPool` spawns a fixed number of copies of one command and lets you
drain them without blocking:

```php
use Strukt\ProcessPool;

$pool = new ProcessPool(3);                      // 3 workers
$pool->start(PHP_BINARY . ' ' . escapeshellarg('/path/to/worker.php'));

while ($pool->running()) {
    $pool->drain(static function (int $index, string $line): void {
        printf("worker %d: %s", $index, $line);
    });
    usleep(100_000);
}

$pool->stop();
```

Or use the `pool()` helper, which starts immediately:

```php
$pool = pool(3, PHP_BINARY . ' ' . escapeshellarg($workerScript));
```

## 4. Automatically resurrect dead workers with a supervisor

`ProcessSupervisor` wraps a pool with a restart policy — replace a dead slot
with a fresh copy of the command, capped and throttled:

```php
use Strukt\ProcessSupervisor;

$supervisor = new ProcessSupervisor(3, restartCap: 5, backoff: 0.5);

$supervisor->onExit(static function (int $index, int $exitCode): void {
    printf("worker %d died with exit code %d\n", $index, $exitCode);
});

$supervisor->start(PHP_BINARY . ' ' . escapeshellarg($workerScript));

while (true) {
    $supervisor->supervise();
    usleep(100_000);
}
```

## 5. Move supervision into a daemon with the CLI

When the pool must survive the terminal, daemonize it with `ManagedSupervisor`
or the `supervise` binary. This is what shipping supervisors actually look
like in production:

```bash
# start a 2-worker daemon named "mail", worker.php runs forever
php vendor/bin/supervise start mail "php worker.php" --size=2

# see it
php vendor/bin/supervise ps
php vendor/bin/supervise status mail

# inspect logs
php vendor/bin/supervise logs mail --lines=100

# kill one worker; the daemon respawns it
php vendor/bin/supervise kill mail 0

# stop the daemon and reap workers
php vendor/bin/supervise stop mail
```

The equivalent in pure PHP:

```php
use Strukt\ManagedSupervisor;

$supervisor = new ManagedSupervisor('mail', size: 2);
$pid = $supervisor->start(PHP_BINARY . ' ' . escapeshellarg($workerScript));

// later, from any process:
$state = ManagedSupervisor::status('mail');     // array snapshot
ManagedSupervisor::stop('mail');                // graceful shutdown
```

## Runtime directory

Every daemon writes to `~/.strukt/supervisor/<name>/` by default:
`supervise.pid`, `state.json`, and `output.log`. Override the base directory
with the `--dir` option or the `STRUKT_SUPERVISOR_DIR` environment variable.
See [managed-supervisor.md](managed-supervisor.md).

## Where to go next

- [Running commands](running-commands.md) — output, errors, exit codes, batches.
- [Process pools](process-pool.md) — concurrency and draining.
- [Process supervisor](process-supervisor.md) — restart policy.
- [Managed supervisor](managed-supervisor.md) — the daemon and its files.
- [Supervisor CLI](cli.md) — every command in detail.