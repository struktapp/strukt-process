# Strukt Process

[![Latest Stable Version](https://img.shields.io/packagist/v/strukt/process.svg)](https://packagist.org/packages/strukt/process)
[![License](https://img.shields.io/packagist/l/strukt/process.svg)](https://packagist.org/packages/strukt/process)

Typed, resource-safe process execution for PHP 8.2 and later. Strukt Process
supports sequential command batches, line callbacks, captured output,
interactive prompt processes, supervised worker pools, and a `supervise`
command-line tool for long-running, daemonized supervised processes.

The package is a Strukt 3 rewrite of 'strukt2/process'. It keeps the original
global helpers and process concepts while improving type declarations,
resource cleanup, output draining, error capture, and documentation.

## Installation

```bash
composer require strukt/process
```

The package depends on 'strukt/base', which supplies the helper registry.

## Documentation

The full documentation lives in [docs/](docs/README.md): a topic guide, an
[architecture deep dive](docs/architecture.md), a generated per-command
reference, and a complete [API reference](docs/api-reference.md). In
particular:

- [Quick start](docs/quickstart.md) — from one command to a supervised daemon.
- [Managed supervisor](docs/managed-supervisor.md) — daemonizing pools and the
  pid/state/log files that make them observable.
- [Supervisor CLI](docs/cli.md) — the `supervise` binary in detail.
- [Operations and troubleshooting](docs/operations.md) — exit codes, failure
  modes, and debugging daemons.

## Quick start

Run one command and inspect its output:

```php
<?php

$result = process('printf "hello\n"');

echo $result->outputs();
```

Stream complete output lines while a command runs:

```php
$result = process(
    'printf "first\nsecond\n"',
    static function (string $line): void {
        echo "received: {$line}";
    },
);
```

process() waits for each command to finish, drains standard output and
standard error concurrently, and returns a Strukt\ProcessResult.

## Global helpers

The autoloaded src/helpers.php registers all process helpers with the Strukt
helper registry.

| Helper | Parameters | Return type | Description |
| --- | --- | --- | --- |
| switchChannels | bool $switch = true | void | Swaps the logical output and error channels for subsequently created Process objects. |
| process | string\|array<int\|string, string> $commands, ?callable $callback = null | ProcessResult | Runs one command or a sequential command batch. |
| pprocess | string $command | PromptableProcessInterface | Starts an interactive process that accepts input through standard input. |
| pool | int $size, string\|list<string> $command, ?string $cwd = null, ?array $env = null | ProcessPool | Starts a pool of concurrent worker processes. |
| supervisor | int $size, string\|list<string> $command, int $restartCap = -1, float $backoff = 0.0, ?string $cwd = null, ?array $env = null | ProcessSupervisor | Starts a supervised pool that restarts dead workers under a restart policy. |

The helpers are available only when their names are not already defined, so
they can coexist with applications that provide their own global functions.

## Running commands

### One command

```php
$result = process('php -r "echo 2 + 2;"');

echo $result->outputs(); // 4
$process = $result->resource();
```

### Multiple commands

Commands run in array order. Each command has its own process resource and
captured output:

```php
$result = process([
    'printf "compile\n"',
    'printf "test\n"',
]);

echo $result->outputs(0); // compile
echo $result->outputs(1); // test

$first = $result->resource(0);
$second = $result->resource(1);
```

An empty command list returns an empty ProcessResult. Every command must be
a non-empty string; invalid commands throw InvalidArgumentException.

### Line callbacks

The callback receives complete logical standard-output lines, including their
line endings. A final output fragment without a newline is delivered after
the process closes its output stream.

```php
$lines = [];

process('printf "one\ntwo\n"', static function (string $line) use (&$lines): void {
    $lines[] = trim($line);
});

// ['one', 'two']
```

Callbacks may also declare no parameters. Standard error is drained to avoid
pipe back-pressure, but it is not sent to the callback.

## ProcessResult

Strukt\ProcessResult extends ArrayIterator, so it can be iterated as a list
of Strukt\Process objects.

### resource

```php
public function resource(int $idx = 0): Process
```

Returns the managed process at a zero-based command index. It throws
OutOfBoundsException when the index is absent.

### outputs

```php
public function outputs(int $idx = 0): ?string
```

Returns captured logical standard output for a command. It returns null for
an index that has no captured entry and an empty string when the command
produced no output.

### errors

```php
public function errors(int $idx = 0): ?string
```

Returns captured logical standard error for a command. It follows the same
null-versus-empty behavior as outputs.

## Error output and exit codes

Both output channels are retained:

```php
$result = process('sh -c "printf failure >&2; exit 7"');

echo $result->errors();                 // failure
echo $result->resource()->error();      // failure
echo $result->resource()->close();      // 7, already reaped exit code
```

Process::run() throws RuntimeException when a command cannot be started.
The process object uses InvalidArgumentException for invalid constructor
handles or empty commands.

## Switching channels

The default mapping is standard output to output() and standard error to
error(). The mapping can be switched before starting a process:

```php
switchChannels(true);

$result = process('sh -c "printf output; printf error >&2"');

echo $result->outputs(); // error
echo $result->errors();  // output

switchChannels(false);
```

The setting affects new Process instances and remains enabled until changed.
It does not change an already-created process.

## Interactive processes

Use pprocess() when a command expects input:

```php
$prompt = pprocess('read password; printf "%s\n" "$password"');

$prompt->write('secret');
$prompt->closeInput();

echo $prompt->read(); // secret
```

write() appends PHP_EOL to the supplied input and returns the number of bytes
written. It throws RuntimeException when standard input is closed or the
write fails. Close standard input when the child must receive EOF.

Interactive processes expose the common process methods as well:

```php
$prompt->read();
$prompt->error();
$prompt->readline();
$prompt->isRunning();
$prompt->terminate();
$prompt->close();
```

Read output before calling close() if it has not already been captured.
Calling closeInput(), closeOutput(), closeError(), closePipes(), or closeAll()
more than once is safe.

## Process pool

ProcessPool supervises a group of concurrently running workers: it spawns a
fixed number of copies of one command, lets you drain their output without
blocking, and tears them all down together. This fits the classic "spawn N
workers, feed a queue, later stop the pool" loop where workers live for the
whole batch rather than for one command.

```php
$pool = new ProcessPool(3);

$pool->start(PHP_BINARY . ' ' . escapeshellarg($workerScript));

while ($pool->running()) {
    $pool->drain(static function (int $index, string $line): void {
        printf("worker %d: %s", $index, $line);
    });
    usleep(100000);
}

$pool->stop();
```

Start the pool with the pool() helper when you do not need the intermediate
instance:

```php
$pool = pool(3, [PHP_BINARY, $workerScript, '--prefix=demo']);
```

### start

Spawns $size concurrent copies of the command, each with its own standard
three-pipe set so their output stays separate. A string command runs through
the shell; an array command runs the program directly. When the string is a
simple command (no pipes, redirection, quoting, or substitution), the pool
wraps it in `exec` so the shell replaces itself: the tracked pid is then the
real worker, and terminating the pool kills the worker rather than orphaning
a child of the shell. Standard input is closed up front because supervised
workers rarely read from the parent, and a left-open input pipe risks a
deadlock. Starting twice throws RuntimeException.

### drain

Reads whatever each worker produced since the last poll without ever blocking
on a worker. Complete standard-output lines go to the callback as
(int $index, string $line); standard error is drained and retained on each
worker (see Process::error()) but not dispatched. With no callback, drain()
still records the output on the workers for later Process::output().

### stop

Terminates every still-running worker and reaps its exit code, returning an
array of codes keyed by spawn index.

### Introspection

| Method | Return | Behavior |
| --- | --- | --- |
| count | int | The configured pool size. |
| active | int | Workers still running. |
| running | bool | True while any worker runs. |
| processes | list\<Process\> | Workers in spawn order. |
| process($index) | Process | One worker by spawn index (throws OutOfBoundsException). |

The pool terminates its workers in __destruct() so an abandoned pool never
leaves processes behind.

## Process supervisor

ProcessSupervisor composes a ProcessPool with a restart policy: when a worker
exits (crashes or finishes), supervise() replaces it with a fresh copy of the
original command. The replacement keeps the same slot index, so callers that
track per-slot state keep their numbering across restarts.

```php
$supervisor = new ProcessSupervisor(3, restartCap: 5, backoff: 0.5);

$supervisor->onExit(static function (int $index, int $exitCode): void {
    printf("worker %d exited with %d\n", $index, $exitCode);
});

$supervisor->start(PHP_BINARY . ' ' . escapeshellarg($workerScript));

while (true) {
    $supervisor->supervise(static function (int $index, string $line): void {
        printf("worker %d: %s", $index, $line);
    });
    usleep(100000);
}
```

Start a supervisor with the supervisor() helper when you do not need the
intermediate instance:

```php
$supervisor = supervisor(3, [PHP_BINARY, $workerScript], restartCap: 5);
```

### Policy

- `restartCap` bounds how many times one slot may be restarted; once the cap is
  reached the slot is left dead. A cap of `-1` (default) never gives up.
- `backoff` throttles restarts of the same slot: after a restart, at least
  `$backoff` seconds must pass before that slot restarts again, breaking tight
  crash loops.
- `onExit()` fires once whenever a worker is found dead -- whether or not it
  will be restarted -- so an owner can reclaim anything the dead worker left in
  flight (re-queue an un-finished job, log the crash, ...).

### supervise

supervise() is the only thing a long-running owner needs to call in its loop.
It drains worker output first (complete lines go to the optional callback as
(int $index, string $line)), so the exit hook sees any final lines a worker
wrote before dying, and then revives each dead slot under the policy.

### Introspection

| Method | Return | Behavior |
| --- | --- | --- |
| count | int | The configured pool size. |
| active | int | Workers still running. |
| running | bool | True while any worker runs. |
| processes | list\<Process\> | Workers in slot order. |
| process($index) | Process | One worker by slot index (throws OutOfBoundsException). |
| restarts($index) | int | How many times a slot has been restarted. |
| totalRestarts | int | All restarts across every slot. |
| stop | array\<int, int\> | Terminates every worker and returns exit codes keyed by slot. |

## Managed supervisor

ProcessSupervisor supervises from inside the process that owns it. When the
owner is a long-running job -- a queue consumer, a web worker, a scheduled
task -- you usually want it to keep running as a background daemon and to be
observable from other shells. Strukt\ManagedSupervisor adds that layer: it
runs the same supervision loop as a detached daemon and persists everything
worth seeing to a runtime directory.

```php
use Strukt\ManagedSupervisor;

$supervisor = new ManagedSupervisor('mail', size: 3, restartCap: 5);
$supervisor->start(PHP_BINARY . ' ' . escapeshellarg($workerScript));
```

For every supervisor, the daemon writes three artifacts under the runtime
directory (default `~/.strukt/supervisor`, overridable with the
`STRUKT_SUPERVISOR_DIR` environment variable or the `$dir` argument):

| Artifact | Purpose |
| --- | --- |
| `<name>/supervise.pid` | Pid of the daemon itself. |
| `<name>/state.json` | Snapshot of the pool written every supervision pass. |
| `<name>/output.log` | Combined worker output, one line per slot and supervisor events. |

The inspection surface is static on purpose -- `status()`, `ps()`, `tail()`,
`kill()` and `stop()` read the pid and state files and never join the daemon:

```php
$state = ManagedSupervisor::status('mail');       // snapshot incl. workers
$running = ManagedSupervisor::running('mail');    // true while the pid is alive
$all = ManagedSupervisor::ps();                   // every pool in the directory
$tail = ManagedSupervisor::tail('mail', 50);      // last 50 log lines
ManagedSupervisor::kill('mail', 0);               // SIGTERM one slot; respawns
ManagedSupervisor::stop('mail');                  // SIGTERM the daemon; reaps
```

The state snapshot includes the daemon pid, the command, the pool size, the
per-slot worker pids, their running flag, exit code, and restart count, plus
the total restart count and the log path.

## Supervisor CLI

The package ships a `supervise` binary built on the Strukt console. It wraps
ManagedSupervisor with commands to start, list, inspect, kill, and stop
long-running supervised processes:

```bash
# daemonize a 2-worker pool that runs worker.php forever
php vendor/bin/supervise start mail "php worker.php" --size=2 --dir=/var/run/sv

# view every supervised pool
php vendor/bin/supervise ps

# show one pool's live state (workers, pids, restarts)
php vendor/bin/supervise status mail

# terminate one worker slot; the daemon restarts it in place
php vendor/bin/supervise kill mail 0

# print the combined worker log
php vendor/bin/supervise logs mail --lines=100

# stop the daemon (reaps workers, removes the pid file)
php vendor/bin/supervise stop mail
```

Add `--json` to any command to render the result as JSON for scripting. Run
`php vendor/bin/supervise list` for the command reference, or generate
markdown documentation with `php vendor/bin/supervise docs`.

## Process API

### Process::run

```php
public static function run(
    array $commands,
    ?callable $callback = null,
): ProcessResult
```

Runs commands sequentially and returns their resources and captured streams.
The array values must be non-empty command strings.

### Process::wait

```php
public function wait(callable $callback): void
```

Drains the logical output and error pipes until the process exits. Complete
output lines are passed to the callback. The implementation uses non-blocking
pipes and stream_select() so a child that writes a large amount of standard
error cannot block while the parent waits on standard output.

### Process::output

```php
public function output(): string
```

Returns all logical standard output captured by wait() or read().

### Process::poll

```php
public function poll(?callable $callback = null): bool
```

Drains whatever output is available right now without blocking, dispatching
complete standard-output lines to the callback. Standard error is drained and
retained only. A trailing fragment without a newline is delivered once the
process exits. Returns true while the process is still running; used by
ProcessPool::drain().

### Process::switchChannels

```php
public static function switchChannels(bool $switch = true): void
```

Changes the channel mapping used by subsequently constructed processes.

### `Process::__construct`

```php
public function __construct(
    mixed $process,
    mixed $stdin,
    mixed $stdout,
    mixed $stderr,
)
```

Creates a process from the process and pipe resources returned by proc_open().
The four values must be live resources. PHP has no resource parameter type,
so the implementation validates these arguments at runtime.

## Interactive process API

Strukt\Pprocess extends Strukt\AbstractPromptableProcess.

### Pprocess::run

```php
public static function run(string $command): static
```

Starts a command with standard input, output, and error pipes and returns the
interactive process instance.

### `Pprocess::__construct`

```php
public function __construct(
    mixed $process,
    mixed $stdin,
    mixed $stdout,
    mixed $stderr,
)
```

Creates an interactive process around existing proc_open() resources.

## Common process contract

Strukt\Contract\ProcessInterface defines the shared process operations:

| Method | Parameters | Return type | Behavior |
| --- | --- | --- | --- |
| getStatus | none | array | Returns the proc_get_status() array; throws when the process handle is closed. |
| read | none | string\|false | Reads available standard output and retains it. |
| error | none | string\|false | Reads available standard error and retains it. |
| readline | none | string\|false | Reads one output line, including its line ending. |
| terminate | none | void | Terminates a running process; it is safe when already stopped. |
| close | none | int | Closes resources and returns the process exit code. |
| closeInput | none | bool | Closes standard input. |
| closeOutput | none | bool | Closes standard output. |
| closeError | none | bool | Closes standard error. |
| closePipes | none | void | Closes all three pipes. |
| closeAll | none | void | Terminates a running process and closes it. |
| isRunning | none | bool | Reports whether the process is still running. |

Strukt\Contract\PromptableProcessInterface extends this contract with:

```php
public function write(string $input): int
```

The abstract implementations, AbstractProcess and
AbstractPromptableProcess, provide the resource management used by Process
and Pprocess. Their destructors are defensive and never allow cleanup
exceptions to escape during shutdown.

For source compatibility with Strukt 2, the package also provides
Strukt\Contract\AbstractProcess and
Strukt\Contract\AbstractPromptableProcess as deprecated compatibility
subclasses. New code should extend the root-namespace abstract classes.

## Resource lifecycle

Process::run() closes standard input before waiting so commands receive EOF,
drains both output channels, dispatches pending output, and reaps the child
process. The captured strings remain available through ProcessResult and the
returned Process objects after cleanup.

For manually managed Pprocess instances:

1. Call write() as needed.
2. Call closeInput() when no more input will be sent.
3. Read output and error.
4. Call close() or closeAll().

All process objects also attempt safe cleanup in `__destruct()`.

## Performance characteristics

- Output and error pipes are drained concurrently with non-blocking reads.
- The callback is invoked directly, avoiding one event wrapper per line.
- Command batches are sequential, preserving deterministic ordering.
- Captured output is appended in chunks rather than rebuilding the complete
  string for every line.
- Pipe and process cleanup is idempotent, reducing repeated resource checks in
  application code.

## Development

Install development dependencies and run the Pest suite:

```bash
composer update
composer test
```

Validate the package metadata:

```bash
composer validate --no-check-publish
```

The test suite covers command batches, line callbacks, no-argument callbacks,
standard-error capture, channel switching, interactive input, worker pools,
cleanup, and helper registration.

## License

Strukt Process is released under the MIT license.
