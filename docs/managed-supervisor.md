# Managed supervisor

`ManagedSupervisor` takes the `ProcessSupervisor` loop and moves it into a
detached, persistent background daemon that other processes can inspect and
control without ever joining its process.

This is the layer behind the `supervise` CLI. In-process supervision is covered
in [process-supervisor.md](process-supervisor.md).

- [Concepts](#concepts)
- [Starting a daemon](#starting-a-daemon)
- [The runtime directory](#the-runtime-directory)
- [Observing state](#observing-state)
- [Controlling the daemon](#controlling-the-daemon)
- [Reading logs](#reading-logs)
- [Graceful shutdown](#graceful-shutdown)
- [Requirements and limits](#requirements-and-limits)

## Concepts

Every daemon is identified by a **name** — also its directory handle and CLI
handle. The daemon is a forked child that:

- detaches from the terminal (`posix_setsid`),
- redirects its three standard streams to `/dev/null`,
- installs SIGTERM/SIGINT handlers for graceful shutdown,
- runs a `ProcessSupervisor` loop, and
- writes its **pid**, a **state snapshot**, and a **combined log** to a
  runtime directory after every supervision pass.

Control (start/stop/kill/status) is **static and file-based**: callers read
the pid and state files and signal pids. They never run the supervision loop
themselves, which keeps the rest of the system free of spawning.

## Starting a daemon

```php
public function __construct(
    string $name,
    int $size,
    int $restartCap = -1,
    float $backoff = 0.0,
    int $interval = 200,
    ?string $dir = null,
)

public function start(string|array $command, ?string $cwd = null, ?array $env = null): int
```

`start()` forks, has the parent wait (up to five seconds) for the pid file,
and returns the daemon pid:

```php
use Strukt\ManagedSupervisor;

$supervisor = new ManagedSupervisor('mail', size: 3, restartCap: 5);
$pid = $supervisor->start(PHP_BINARY . ' ' . escapeshellarg($workerScript));

myProcessStore->remember('mail.daemon_pid', $pid);
```

Validation:

- The name must match `^[A-Za-z0-9][A-Za-z0-9_.-]*$`, or `RuntimeException` is
  thrown (the name becomes a directory and file names).
- `$size` must be at least `1`.
- Starting requires the `pcntl` extension (forking). Starting a supervisor
  that is already running throws `RuntimeException`.
- The daemon returns immediately after spawning the child; it keeps running
  independent of the caller.

## The runtime directory

Each supervisor owns `$dir/$name/`. The base `$dir` resolves in this order:

1. The `$dir` constructor argument (also the CLI `--dir`),
2. The `STRUKT_SUPERVISOR_DIR` environment variable,
3. `$HOME/.strukt/supervisor` (falling back to `sys_get_temp_dir()`).

Resolve it yourself with `ManagedSupervisor::defaultDir(): string`.

Three files live under the directory:

| File | Contains | Read by |
| --- | --- | --- |
| `supervise.pid` | The daemon pid. | `running()`, `status()`, `stop()`. |
| `state.json` | A snapshot of the pool, rewritten every pass. | `status()`, `ps()`. |
| `output.log` | Combined worker output + supervisor events. | `tail()`, the `logs` command. |

State writes are atomic (temp file + `rename`), so an observer never reads a
torn document. See [architecture.md](./architecture.md#the-runtime-directory)
for the full `state.json` schema.

## Observing state

```php
public static function status(string $name, ?string $dir = null): ?array
```

Returns the current pool snapshot, with `running` recomputed live from the pid
file (a crashed daemon reports `running: false` even if its state file is
stale). Returns `null` when the supervisor has no state file yet.

```php
$state = ManagedSupervisor::status('mail');

echo $state['pid'];            // daemon pid
echo $state['active'];         // workers currently running
echo $state['total_restarts'];

foreach ($state['workers'] as $worker) {
    printf("slot %d pid %d running=%s restarts=%d\n",
        $worker['slot'], $worker['pid'],
        $worker['running'] ? 'yes' : 'no', $worker['restarts']);
}
```

List every supervised pool in the directory:

```php
public static function ps(?string $dir = null): array
```

Returns an array keyed by supervisor name, each value a `status()` snapshot,
sorted by name. Empty when the directory does not exist or holds no pools.

Liveness check:

```php
public static function running(string $name, ?string $dir = null): bool
public static function pid(string $name, ?string $dir = null): ?int
```

`running()` is `true` when the pid file exists **and** the pid responds to
signal 0. `pid()` returns that pid, or `null`.

## Controlling the daemon

```php
public static function stop(string $name, ?string $dir = null): bool
public static function kill(string $name, int $slot, ?string $dir = null): bool
```

- `stop()` sends SIGTERM to the **daemon**, which gracefully stops its workers
  and removes the pid file. Returns whether the signal was sent.
- `kill()` sends SIGTERM to one **worker slot** (resolved from the state
  file). The daemon then respawns that slot under the restart policy, as it
  would for a crashed worker. Returns whether the pid was signaled.

Both return `false` when `posix_kill` is unavailable, the supervisor is not
running, or (for `kill`) the slot has no recorded pid.

```php
ManagedSupervisor::kill('mail', 0);     // bounce slot 0; the daemon revives it
ManagedSupervisor::stop('mail');        // stop the whole daemon
```

## Reading logs

```php
public static function tail(string $name, int $lines = 50, ?string $dir = null): string
```

Returns the last `$lines` lines of the combined log (default 50). Returns `''`
when the log file does not exist yet.

The log mixes worker output and supervisor events:

```text
[supervisor] started
[slot 0] processed job 1042 in 12ms
[slot 1] processed job 1043 in 9ms
[supervisor] worker 0 exited with code 1
[slot 0] processed job 1044 in 11ms
...
[supervisor] stopped
```

Worker lines carry their slot; supervisor lines explain lifecycle events such
as a worker's death (the daemon logs the exit code, then quietly respawns the
slot, which is visible only as a later `[slot 0]` line).

## Graceful shutdown

On SIGTERM or SIGINT the daemon finishes the current supervision pass, stops
the inner supervisor (terminating and reaping every worker), removes the pid
file, writes a final state snapshot, and exits with code `0`. The
`[supervisor] stopped` line is appended to the log.

There is deliberately no immediate kill path: the policy always gives the
daemon a chance to reap its children cleanly.

## Requirements and limits

- `pcntl` is required to start a daemon.
- `posix` is required for `setsid`, and for `running()/stop()/kill()` which
  rely on `posix_kill`; without it those return `false`/`null` rather than
  throw.
- One supervisor = one daemon = one directory. Two daemons with the same name
  cannot run in the same `$dir` (the second `start()` throws).
- The daemon keeps no connection to its parent's terminal (all three streams
  go to `/dev/null`), but it inherits the parent's working directory and
  environment; pass `$cwd` explicitly if the workers must run elsewhere.

## Next steps

- [Supervisor CLI](cli.md) — the same API as shell commands with `--json`
  output for scripting.
- [Operations and troubleshooting](operations.md) — exit codes, common
  failure modes, and debugging daemons.