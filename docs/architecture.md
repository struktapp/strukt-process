# Architecture

This document explains how Strukt Process is organised, why it is layered the
way it is, and how the pieces collaborate at runtime.

- [Layers at a glance](#layers-at-a-glance)
- [The process contract hierarchy](#the-process-contract-hierarchy)
- [Facades: Process and Pprocess](#facades-process-and-pprocess)
- [The three supervision layers](#the-three-supervision-layers)
- [Data flow in the supervision loop](#data-flow-in-the-supervision-loop)
- [The managed supervisor daemon](#the-managed-supervisor-daemon)
- [The runtime directory](#the-runtime-directory)
- [The CLI layer](#the-cli-layer)
- [Helpers and the Strukt registry](#helpers-and-the-strukt-registry)
- [Resource lifecycle and destroying acks](#resource-lifecycle-and-destroying-acks)
- [Design decisions](#design-decisions)

## Layers at a glance

```
┌───────────────────────────────────────────────────────────────┐
│  Global helpers   switchChannels  process  pprocess  pool     │
│                   supervisor                                  │
├───────────────────────────────────────────────────────────────┤
│  Facades          Process (run/wait/poll)   Pprocess (write)  │
├───────────────────────────────────────────────────────────────┤
│  Base layers      AbstractProcess  AbstractPromptableProcess  │
│  Contracts        ProcessInterface  PromptableProcessInterface│
├───────────────────────────────────────────────────────────────┤
│  Supervision      ProcessPool  ProcessSupervisor              │
│                   ManagedSupervisor (daemon)                  │
├───────────────────────────────────────────────────────────────┤
│  CLI              bin/supervise  Process\Cli\Application +    │
│                   Command\*  (strukt/console runtime)         │
└───────────────────────────────────────────────────────────────┘
```

Each layer only depends on the ones beneath it:

- The **contracts** (root `Strukt` and compatibility `Strukt\Contract`)
  describe what a managed process is without saying how it is started.
- The **base layers** implement resource and pipe management once, shared by
  the facades.
- The **facades** are what users actually touch for single commands and
  prompts.
- The **supervision layers** build on top: `ProcessPool` adds concurrency,
  `ProcessSupervisor` adds resurrection, `ManagedSupervisor` adds
  daemonization and external observability.
- The **CLI** is a thin dispatch shim over `ManagedSupervisor`, so every CLI
  command is also available as a static library call.

## The process contract hierarchy

Two interfaces define the shared vocabulary. Both live under `Strukt\Contract`.

```
ProcessInterface
├── status() / isRunning()      introspection
├── read() / error() / readline()   output
├── terminate() / close() / closeAll()   lifecycle
├── closeInput() / closeOutput() / closeError() / closePipes()
└── PromptableProcessInterface  adds write(string): int
```

`AbstractProcess` implements `ProcessInterface` and owns the single source of
truth for resource management:

- `$process` — the proc_open handle
- `$stdin`, `$stdout`, `$stderr` — the three pipes
- `$exitCode` — captured at close time
- `$outputBuffer`, `$errorBuffer` — retained bytes

`AbstractPromptableProcess` extends it with `write()`. The deprecated
`Strukt\Contract\AbstractProcess` and `Strukt\Contract\AbstractPromptableProcess`
are thin subclasses kept for Strukt 2 source compatibility.

## Facades: Process and Pprocess

`Process` wraps a proc_open allocation and adds the two draining modes used
everywhere else:

- `wait(callable)` — blocks until the child exits, draining **both** output
  channels concurrently with `stream_select()`. This is the Power Mode for
  one-shot commands: standard error can never fill its pipe and block the
  parent while it waits on standard output.
- `poll(?callable)` — reads whatever is available right now without blocking,
  returns whether the child still runs, and flushes a trailing half-line once
  the process exits. This is what lets a caller supervise without committing
  to a blocking loop.

`Process::run(array $commands, ?callable)` is the static entry point for
sequential batches. `Pprocess::run(string)` is the interactive counterpart and
keeps standard input open so callers can write into the child.

Both facades take pre-built proc_open resources in their constructors, so they
are also usable when `proc_open` is called by hand.

## The three supervision layers

Supervision is built as a stack. Each layer does one job and delegates the
rest.

### Layer 1 — ProcessPool (concurrency)

Spawns `$size` copies of one command, each with its own three pipes, and gives
you three operations:

- `drain(?callback)` — non-blocking read of every worker, relaying complete
  lines as `(int $index, string $line)`.
- `run()/stop()` — termination and reaping, returning exit codes.
- introspection — `count`, `active`, `running`, `processes`, `process($i)`.

The pool knows nothing about *why* a worker died. It only guarantees: spawn N,
let you read them concurrently, tear them all down.

A subtlety worth knowing: when a command is passed as a **string**, it runs
through `/bin/sh`, and `sh` may fork a child for the real program. `stop()`
would then reap the shell and orphan the program. The pool therefore wraps
simple string commands in `exec ` so the shell replaces itself and the tracked
process *is* the worker. Commands containing pipes, redirection, quoting, or
substitution are handed to the shell untouched. Array commands bypass the
shell entirely.

### Layer 2 — ProcessSupervisor (resurrection)

Composes a `ProcessPool` with a restart policy. On every `supervise()` call it:

1. drains worker output (so exit hooks see a worker's final lines),
2. walks the slots; for each dead worker:
   - fires `onExit($index, $exitCode)` exactly once,
   - restarts the slot **unless** the backoff window has not elapsed or the
     per-slot restart cap is exhausted,
3. keeps a running per-slot restart count.

Slot identity is preserved across restarts: the replacement occupies the same
index, so callers tracking per-slot state keep their numbering.

### Layer 3 — ManagedSupervisor (daemonization and observability)

Separates worker supervision from the process that owns it. When the
owner is itself a long-running job (a queue consumer, a cron loop), you often
want the pool to keep running even after the terminal closes, and you want
other processes to be able to inspect it. `ManagedSupervisor` forks once:

- the **parent** waits (up to five seconds) for the pid file and returns the
  daemon pid;
- the **child** detaches (`posix_setsid`), redirects its three standard
  streams to `/dev/null`, installs SIGTERM/SIGINT handlers, runs the
  `ProcessSupervisor` loop, and writes its state every cycle.

Everything worth observing lands in a runtime directory — see
[The runtime directory](#the-runtime-directory) — and every control operation
(`status`, `ps`, `tail`, `kill`, `stop`) is **static** and file-based: it
reads the pid/state/log files and never joins the daemon's process.

## Data flow in the supervision loop

For a supervised worker, one iteration looks like this:

```
ManagedSupervisor loop (every $interval ms)
│
├─ ProcessSupervisor::supervise()
│   ├─ pool->drain(callback)
│   │   └─ per worker: Process::poll(callback)
│   │        └─ complete lines → callback(slot, line) → writeLog("[slot N] …")
│   └─ per slot: if not running
│        ├─ fire ProcessSupervisor::onExit  → writeLog("[supervisor] worker N exited …")
│        └─ if allowed by cap/backoff: pool->restart(slot)
│
└─ persist() → state.json (atomic rename via .tmp)
```

The pool drain is non-blocking, so a supervisor loop never stalls on a worker
that has stopped writing. The exit hook always precedes the restart of the
same pass, and the state file is rewritten after every pass, so an observer
never sees a stale snapshot for more than one interval.

## The managed supervisor daemon

The daemon transient sequence of `ManagedSupervisor::start()`:

```
caller                 parent (pid > 0)            child (pid == 0)
  │                         │                         │
  ├─ pcntl_fork() ──────────┼─────────────────────────┤
  │                         │                         ├─ mkdir(runtime dir)
  │                         │                         ├─ posix_setsid() (detach)
  │                         │                         ├─ detachStdio() → /dev/null
  │                         │                         ├─ register SIGTERM/SIGINT
  │                         │                         ├─ write supervise.pid
  │                         │                         ├─ ProcessSupervisor->start()
  │                         │                         │
  │    ╭── wait for pid ◄───┤                         ├─ loop:
  │    │   (up to 5s)       │                         │   supervise() → drain+revive
  │    │                    │                         │   persist() → state.json
  │    │                    │                         │   usleep(interval)
  │    └─ return daemon pid │                         │
  │                         │                         ├─ SIGTERM/SIGINT → $stopping
  │                         │                         ├─ supervisor->stop()  (reap all)
  │                         │                         ├─ unlink supervise.pid
  │                         │                         └─ exit(0)
```

Graceful shutdown is the key guarantee: a stop signal sets a flag that the loop
checks after the current pass, then the daemon stops the inner supervisor
(which terminates and reaps every worker), removes the pid file, writes a final
state snapshot, and exits with code 0.

## The runtime directory

Each supervisor owns a directory `$dir/$name`. The directory is resolved by
`ManagedSupervisor::dirFor()` from, in order of precedence:

1. the `$dir` constructor argument,
2. the `STRUKT_SUPERVISOR_DIR` environment variable,
3. `$HOME/.strukt/supervisor` (falling back to `sys_get_temp_dir()`).

Inside it there are exactly three files:

| File | Writer | Reader | Purpose |
| --- | --- | --- | --- |
| `supervise.pid` | daemon (child) | status/running/stop | Liveness: `running()` is `posix_kill($pid, 0)`. |
| `state.json` | daemon (child) | status/ps | Pool snapshot written every supervision pass. |
| `output.log` | daemon (child) | tail/logs | Combined worker output + supervisor events. |

State writes are atomic in practice: the daemon writes `state.json.tmp` and
`rename()`s it over the destination, so an observer never reads a torn JSON
document.

The `state.json` schema:

```jsonc
{
  "name":           "mail",
  "pid":            1234,
  "command":        "php worker.php",
  "size":           2,
  "restart_cap":    -1,
  "backoff":        0.0,
  "interval_ms":    200,
  "started_at":     1712345678.12,     // microtime at start
  "running":        true,              // recomputed from the pid file
  "active":         2,
  "total_restarts": 0,
  "log":            "/home/u/.strukt/supervisor/mail/output.log",
  "workers": [
    { "slot": 0, "pid": 4321, "running": true, "restarts": 0, "exit_code": null },
    { "slot": 1, "pid": 4322, "running": true, "restarts": 0, "exit_code": null }
  ]
}
```

`running` is deliberately **not** read from the file; `status()` recomputes it
from the pid file so a crashed-but-not-yet-cleaned daemon is reported as not
running.

## The CLI layer

`bin/supervise` is a five-line shim that locates the nearest Composer
autoloader (from the current working directory) and hands `$argv` to
`Process\Cli\Application`, which delegates to the `strukt/console` runtime.
Commands live under `src/Strukt/Process/Cli/Command/*` and are self-describing
through PHPDoc annotations (`@command`, `@description`, `@usage`, `@argument`,
`@option`, `@flag`, `@type`, `@default`).

The console runtime derives argument/option parsing, `--help`, the `list`
command, and the `docs` generator from those annotations. This keeps the
implementations tiny: every command is at most a couple of `ManagedSupervisor`
static calls plus a rendering decision (JSON vs short text report).

```
supervise
├── start  NAME COMMAND [--size --restart-cap --backoff --interval --cwd --dir --json]
├── ps     [--dir --json]
├── status NAME [--dir --json]
├── kill   NAME SLOT [--dir --json]
├── stop   NAME [--dir --json]
├── logs   NAME [--lines --dir]
├── list
└── docs
```

The base `Command::respond()` renders human-readable reports or, with `--json`,
a JSON document suitable for scripting. Exit codes are meaningful: `ps`,
`status`, `kill`, `stop`, and `logs` return `0` on success and `1` when there
was nothing to report or the operation failed.

## Helpers and the Strukt registry

`src/helpers.php` is autoloaded through Composer's `files` directive. Every
helper:

1. checks `function_exists()` first, so application-defined globals win;
2. registers itself with the Strukt Base helper registry via `helper_add()`;
3. delegates to a class — never implements logic inline.

| Helper | Delegates to | Purpose |
| --- | --- | --- |
| `switchChannels` | `Process::switchChannels` | Flip output/error mapping for new processes. |
| `process` | `Process::run` | Sequential command batch → ProcessResult. |
| `pprocess` | `Pprocess::run` | Interactive process. |
| `pool` | `(new ProcessPool)->start` | Started pool. |
| `supervisor` | `(new ProcessSupervisor)->start` | Started supervisor. |

The `pool` and `supervisor` helpers start immediately and return the live
instance, unlike their constructors which require an explicit `start()`.

## Resource lifecycle and destroying acks

Two guarantees hold throughout:

1. **Idempotent cleanup.** Closing an already-closed pipe returns `true`
   (`closeInput/Output/Error`), `close()` on a closed handle returns the last
   exit code (or `-1`), and `terminate()` on a stopped process is a no-op.
2. **Defensive destructors.** `AbstractProcess::__destruct()` terminates and
   closes; `ProcessPool::__destruct()` closes every worker. Both swallow
   exceptions so teardown never interrupts PHP shutdown.

A single-process command flows like this inside `Process::run()`:
`closeInput()` (so the child sees EOF) → `wait($callback)` (non-blocking drain
of both channels until exit) → `close()` (reap, capture exit code) → the
captured strings stay alive on the `Process` object and the `ProcessResult`.

## Design decisions

- **Non-blocking draining everywhere a loop exists.** `poll()` and `drain()`
  never block; `wait()` uses `stream_select()` so neither channel can stall
  the other. This is what lets a supervisor loop detect death quickly.
- **Channels are logical, not physical.** `Process::switchChannels()` swaps
  which physical pipe feeds `output()` vs `error()`. The flag is static and
  applies only to *newly constructed* processes; switching is for programs
  that write results to stderr.
- **Simple strings get `exec`.** The pool wraps simple string commands so the
  tracked pid is the worker, not a shell wrapper — termination is real, no
  orphaned grandchildren.
- **Statics for control.** `ManagedSupervisor::status/kill/stop/ps` are static
  and file-based. Other processes never need to run supervision code to
  observe or control a daemon.
- **PHPDoc-driven CLI.** Rather than hand-maintained argument tables, command
  metadata lives in the docblock and the console runtime parses it. The `docs`
  command regenerates the reference, so docs and implementation stay in sync.
- **Backwards compatibility kept thin.** The `Strukt\Contract\*` classes are
  deprecated subclasses; new code extends the root-namespace abstracts.