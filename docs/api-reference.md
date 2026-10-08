# API reference

Generated from the current source. Every public class, method, and interface
is listed with its signature and behavior. Signatures use PHP's own type
declarations.

- [Contracts](#contracts)
- [AbstractProcess](#abstractprocess)
- [Process](#process)
- [Pprocess](#pprocess)
- [ProcessResult](#processresult)
- [ProcessPool](#processpool)
- [ProcessSupervisor](#processsupervisor)
- [ManagedSupervisor](#managedsupervisor)
- [CLI classes](#cli-classes)
- [Deprecated compatibility classes](#deprecated-compatibility-classes)

## Contracts

### `Strukt\Contract\ProcessInterface`

Contracts describe a managed operating-system process. All methods throw on
invalid use rather than returning garbage.

| Method | Signature | Returns | Notes |
| --- | --- | --- | --- |
| `status` | `status(): array` | `array<string,mixed>` | `proc_get_status()` array; throws when the handle is closed. |
| `read` | `read(): string\|false` | bytes | Reads available standard output and retains it. `false` when no output pipe exists. |
| `error` | `error(): string\|false` | bytes | Reads available standard error and retains it. |
| `readline` | `readline(): string\|false` | line | One output line, including its line ending; `false` at EOF. |
| `terminate` | `terminate(): void` | — | SIGTERM a running process; safe when already stopped. |
| `close` | `close(): int` | int | Reap and return exit code; `-1` when no handle remains. |
| `closeInput` | `closeInput(): bool` | bool | Close standard input (idempotent). |
| `closeOutput` | `closeOutput(): bool` | bool | Close standard output (idempotent). |
| `closeError` | `closeError(): bool` | bool | Close standard error (idempotent). |
| `closePipes` | `closePipes(): void` | — | Close all three pipes. |
| `closeAll` | `closeAll(): void` | — | Terminate if running, then close. |
| `isRunning` | `isRunning(): bool` | bool | True while the OS process runs. |

### `Strukt\Contract\PromptableProcessInterface`

Extends `ProcessInterface` with interactive input.

| Method | Signature | Returns | Notes |
| --- | --- | --- | --- |
| `write` | `write(string $input): int` | int | Append `PHP_EOL` to `$input` and write to standard input; throws when the input pipe is closed or the write fails. |

## AbstractProcess

`Strukt\AbstractProcess implements ProcessInterface` — shared resource and
pipe management for the facades.

- Implements every `ProcessInterface` method listed above.
- Owns `$process`, `$stdin`, `$stdout`, `$stderr`, `$exitCode`,
  `$outputBuffer`, `$errorBuffer`.
- `read()` and `error()` append to the retained buffers; `readline()` records
  each line.
- All pipe closes are idempotent; `terminate()` no-ops on a stopped process.
- `__destruct()` attempts terminate+close and swallows exceptions so shutdown
  never fails.

## AbstractPromptableProcess

`Strukt\AbstractPromptableProcess extends AbstractProcess implements PromptableProcessInterface`

Adds `write(string $input): int` — writes `$input . PHP_EOL` to standard
input and returns bytes written, throwing `RuntimeException` when the pipe is
closed or the write fails.

## Process

```php
class Process extends Strukt\Contract\AbstractProcess implements Strukt\Contract\ProcessInterface
```

The non-interactive facade. It extends the deprecated compatibility abstract
class `Strukt\Contract\AbstractProcess` (which itself extends the root
`Strukt\AbstractProcess`), so it satisfies the classic contract while
inheriting the root implementation — see [upgrading.md](upgrading.md).

### Constructor

```php
public function __construct(mixed $process, mixed $stdin, mixed $stdout, mixed $stderr)
```

Wraps existing proc_open resources. All four must be live resources
(`InvalidArgumentException` otherwise). `switchChannels()` state decides
whether logical output is fed by the physical stdout or stderr pipe.

### Static

| Method | Signature | Returns | Notes |
| --- | --- | --- | --- |
| `run` | `run(array $commands, ?callable $callback = null): ProcessResult` | ProcessResult | Run commands sequentially. Empty command → `InvalidArgumentException`; start failure → `RuntimeException`. |
| `switchChannels` | `switchChannels(bool $switch = true): void` | void | Set logical channel mapping for newly constructed processes. |

### Instance

| Method | Signature | Returns | Notes |
| --- | --- | --- | --- |
| `wait` | `wait(callable $callback): void` | void | Non-blocking drain of both channels until exit; complete lines to `$callback` (including line ending). |
| `output` | `output(): string` | string | All logical output captured by `wait()`/`read()`. |
| `poll` | `poll(?callable $callback = null): bool` | bool | Drain whatever is available now; trailing half-line flushed at exit; returns running state. |

`wait()` uses `stream_select()` over both pipes so a child that floods
standard error cannot block the parent while it reads standard output.

## Pprocess

```php
class Pprocess extends Strukt\Contract\AbstractPromptableProcess
```

Interactive (input-accepting) facade. It extends the deprecated compatibility
abstract class `Strukt\Contract\AbstractPromptableProcess`, mirroring how
`Process` extends `Strukt\Contract\AbstractProcess`.

| Method | Signature | Returns | Notes |
| --- | --- | --- | --- |
| `run` | `run(string $command): static` | static | Start via `proc_open` with three pipes. Empty command → `InvalidArgumentException`; failure → `RuntimeException`. |
| `write` | `write(string $input): int` | int | Appends `PHP_EOL`, writes to stdin. |
| Constructor | `__construct(mixed $process, mixed $stdin, mixed $stdout, mixed $stderr)` | — | Wrap existing resources; all must be live. |

## ProcessResult

```php
final class ProcessResult extends \ArrayIterator
```

The result of a command batch. Iterates as `list<Process>`.

| Method | Signature | Returns | Notes |
| --- | --- | --- | --- |
| Constructor | `__construct(array $processes, array $outputs, array $errors)` | — | Array values are re-indexed with `array_values()`. |
| `resource` | `resource(int $idx = 0): Process` | Process | Process by command index; `OutOfBoundsException` when absent. |
| `outputs` | `outputs(int $idx = 0): ?string` | `?string` | Captured output; `null` for absent index, `''` for empty. |
| `errors` | `errors(int $idx = 0): ?string` | `?string` | Captured error; same null-versus-empty behavior. |

## ProcessPool

```php
final class ProcessPool
```

Fixed-size concurrent worker set.

| Method | Signature | Returns | Notes |
| --- | --- | --- | --- |
| Constructor | `__construct(int $size)` | — | `$size = 0` is a valid inert pool. |
| `start` | `start(string\|array $command, ?string $cwd = null, ?array $env = null): void` | void | Spawn `$size` workers, each with own pipes. Double start → `RuntimeException`. String commands are exec-wrapped when simple. |
| `restart` | `restart(int $index, string\|array $command, ?string $cwd = null, ?array $env = null): void` | void | Replace one slot in place (preserves index). Unknown index → `OutOfBoundsException`. |
| `drain` | `drain(?callable $callback = null): void` | void | Non-blocking read of every worker; lines → `(int $index, string $line)`. With no callback, output is still retained on workers. |
| `stop` | `stop(): array` | `array<int,int>` | Terminate + reap all workers; exit codes keyed by spawn index. |
| `running` | `running(): bool` | bool | True while any worker runs. |
| `active` | `active(): int` | int | Workers currently running. |
| `count` | `count(): int` | int | The configured pool size. |
| `processes` | `processes(): array` | `list<Process>` | Workers in spawn order. |
| `process` | `process(int $index): Process` | Process | Worker by index; `OutOfBoundsException` when absent. |

`__destruct()` terminates and closes all workers.

## ProcessSupervisor

```php
final class ProcessSupervisor
```

Pool plus restart policy.

| Method | Signature | Returns | Notes |
| --- | --- | --- | --- |
| Constructor | `__construct(int $size, int $restartCap = -1, float $backoff = 0.0)` | — | Policy: `restartCap` per slot, `-1` = unlimited; `backoff` seconds between restarts of a slot. |
| `onExit` | `onExit(callable $callback): void` | void | Hook `(int $index, int $exitCode)` fired once per dead worker, whether or not it will restart. |
| `start` | `start(string\|array $command, ?string $cwd = null, ?array $env = null): void` | void | Spawn `$size` workers and record command/cwd/env for resurrection. |
| `supervise` | `supervise(?callable $callback = null): void` | void | Drain output (lines → `(int $index, string $line)`), fire exit hooks, revive dead slots under policy. |
| `stop` | `stop(): array` | `array<int,int>` | Terminate + reap all; exit codes by slot. |
| `running` | `running(): bool` | bool | Pool-wide running state. |
| `active` | `active(): int` | int | Workers currently running. |
| `count` | `count(): int` | int | Pool size. |
| `processes` | `processes(): array` | `list<Process>` | Workers in slot order. |
| `process` | `process(int $index): Process` | Process | Worker by slot. |
| `restarts` | `restarts(int $index): int` | int | Restart count for a slot. |
| `totalRestarts` | `totalRestarts(): int` | int | Sum across all slots. |

The documented restart algorithm: per pass, `drain` first, then each dead slot
fires `onExit` once, then restarts unless inside its backoff window or at cap.
A fresh worker occupies the same slot index.

## ManagedSupervisor

```php
final class ManagedSupervisor
```

Daemonizing, observable supervisor. See
[managed-supervisor.md](managed-supervisor.md) for the full story.

### Instance

| Method | Signature | Returns | Notes |
| --- | --- | --- | --- |
| Constructor | `__construct(string $name, int $size, int $restartCap = -1, float $backoff = 0.0, int $interval = 200, ?string $dir = null)` | — | Name must match `^[A-Za-z0-9][A-Za-z0-9_.-]*$` or `RuntimeException`; `$size >= 1`; `$interval` is the ms between passes/state writes. |
| `start` | `start(string\|array $command, ?string $cwd = null, ?array $env = null): int` | int | Fork the daemon; parent waits up to 5 s for the pid file, returns daemon pid. Requires `pcntl`; double-start → `RuntimeException`. |

### Static (file-based, no join)

| Method | Signature | Returns | Notes |
| --- | --- | --- | --- |
| `stop` | `stop(string $name, ?string $dir = null): bool` | bool | SIGTERM the daemon → graceful shutdown. |
| `kill` | `kill(string $name, int $slot, ?string $dir = null): bool` | bool | SIGTERM one worker slot (resolved from state); daemon respawns it. |
| `running` | `running(string $name, ?string $dir = null): bool` | bool | Daemon pid alive (pid file + `posix_kill($pid, 0)`). |
| `pid` | `pid(string $name, ?string $dir = null): ?int` | `?int` | Daemon pid, or `null` when not running/absent. |
| `status` | `status(string $name, ?string $dir = null): ?array` | `?array` | Pool snapshot; `running` recomputed live; `null` with no state file yet. |
| `ps` | `ps(?string $dir = null): array` | `array<string,mixed>` | Every pool in the directory, keyed by name, sorted; empty when there are none. |
| `tail` | `tail(string $name, int $lines = 50, ?string $dir = null): string` | string | Last `$lines` lines of the combined log; `''` when the log does not exist yet. |
| `defaultDir` | `defaultDir(): string` | string | Runtime base dir: `STRUKT_SUPERVISOR_DIR` env, else `$HOME/.strukt/supervisor`, else temp dir. |
| `pidFile` | `pidFile(string $name, ?string $dir = null): string` | string | `<dir>/<name>/supervise.pid`. |
| `stateFile` | `stateFile(string $name, ?string $dir = null): string` | string | `<dir>/<name>/state.json`. |
| `logFile` | `logFile(string $name, ?string $dir = null): string` | string | `<dir>/<name>/output.log`. |

### `state.json` schema

See [architecture.md#the-runtime-directory](./architecture.md#the-runtime-directory)
for the full annotated schema. Fields: `name`, `pid`, `command`, `size`,
`restart_cap`, `backoff`, `interval_ms`, `started_at`, `running`, `active`,
`total_restarts`, `log`, `workers[]` (`slot`, `pid`, `running`, `restarts`,
`exit_code`).

Behavior notes:

- `status()` recomputes `running` from the pid file rather than trusting the
  stored flag, so a crashed daemon reports `running: false`.
- State writes are atomic (`.tmp` + `rename`).
- `stop()`/`kill()` are one-shot signals; they return `false` when `posix` is
  unavailable or the target pid is missing.

## CLI classes

### `Strukt\Process\Cli\Application`

| Method | Signature | Returns | Notes |
| --- | --- | --- | --- |
| Constructor | `__construct(?string $commandDirectory = null)` | — | Defaults to `__DIR__ . '/Command'`. |
| `run` | `run(array $argv): int` | int | Dispatch through the Strukt console runtime. |

### `Strukt\Process\Cli\Command` (abstract)

| Method | Signature | Returns | Notes |
| --- | --- | --- | --- |
| `respond` | `respond(string $command, array $result, bool $json): void` | void | Print JSON or a short report (header + flattened keys). |

### Command implementations

All implement `Strukt\Console\Runtime\CommandInterface` and use docblock
annotations (`@command`, `@description`, `@usage`, `@argument`, `@option`,
`@flag`, `@type`, `@default`) that drive parsing, `--help`, `list`, and the
generated docs.

| Class | Command | Behavior |
| --- | --- | --- |
| `StartCommand` | `start NAME COMMAND` | Build a `ManagedSupervisor` from options and `start()` it. Options: `--size`(1), `--restart-cap`(-1), `--backoff`(0), `--interval`(200), `--cwd`, `--dir`, `--json`. |
| `PsCommand` | `ps` | Summary of every pool (`running`, `active`, `size`, `total_restarts`); exit `1` when none. Options: `--dir`, `--json`. |
| `StatusCommand` | `status NAME` | Full `status()` snapshot; exit `1` when not running/no state. Options: `--dir`, `--json`. |
| `KillCommand` | `kill NAME SLOT` | SIGTERM one slot; exit `1` when nothing signaled. Options: `--dir`, `--json`. |
| `StopCommand` | `stop NAME` | SIGTERM the daemon; exit `1` when not running. Options: `--dir`, `--json`. |
| `LogsCommand` | `logs NAME` | `tail()` the log. Options: `--lines`(50), `--dir`. |

Scripts: `start --help`, `list`, `docs`.

## Deprecated compatibility classes

For source compatibility with Strukt 2, the package provides two deprecated
subclasses under the old contract namespace:

| Class | Extends |
| --- | --- |
| `Strukt\Contract\AbstractProcess` | `Strukt\AbstractProcess` |
| `Strukt\Contract\AbstractPromptableProcess` | `Strukt\AbstractPromptableProcess` |

New code should extend the root-namespace abstract classes. See
[upgrading.md](upgrading.md).

## Global helpers

Registered by `src/helpers.php`:

- `switchChannels(bool $switch = true): void`
- `process(string|array $commands, ?callable $callback = null): ProcessResult`
- `pprocess(string $command): PromptableProcessInterface`
- `pool(int $size, string|array $command, ?string $cwd = null, ?array $env = null): ProcessPool`
- `supervisor(int $size, string|array $command, int $restartCap = -1, float $backoff = 0.0, ?string $cwd = null, ?array $env = null): ProcessSupervisor`

See [helpers.md](helpers.md).