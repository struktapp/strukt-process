# Supervisor CLI

The package ships a `supervise` binary built on the Strukt console. It manages
`ManagedSupervisor` daemons from the shell: start, list, inspect, kill a
worker, read logs, and stop.

A per-command reference is generated into [commands/](commands/) — regenerate
it any time with `php vendor/bin/supervise docs`.

- [Invocation](#invocation)
- [Global behavior](#global-behavior)
- [start](#start)
- [ps](#ps)
- [status](#status)
- [kill](#kill)
- [stop](#stop)
- [logs](#logs)
- [list and docs](#list-and-docs)
- [Exit codes](#exit-codes)
- [Scripting with --json](#scripting-with---json)

## Invocation

```bash
php vendor/bin/supervise CMD [ARGS] [options]
```

`bin/supervise` resolves the Composer autoloader from the current working
directory, so run it from the project root. The entry point is a
`Strukt\Process\Cli\Application` that delegates to the struk console runtime.

## Global behavior

Two options appear on almost every command:

| Option | Meaning |
| --- | --- |
| `--dir=PATH` | Runtime directory override. Defaults to `~/.strukt/supervisor` (or `STRUKT_SUPERVISOR_DIR`). |
| `--json` | Render the result as JSON instead of a short text report. |

Human-readable output is a compact report like:

```
Strukt Supervisor — status
──────────────────────────
name: mail
pid: 4011
running: true
active: 2
```

## start

```bash
start NAME COMMAND [options]
```

Daemonizes a supervised pool. The command must be a single argument — **quote
it** when it contains spaces:

```bash
php vendor/bin/supervise start mail "php worker.php --queue=high" --size=2
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--size` | `1` | Number of worker processes to keep. |
| `--restart-cap` | `-1` | Max restarts per slot before giving up; `-1` = unlimited. |
| `--backoff` | `0` | Minimum seconds between restarts of the same slot. |
| `--interval` | `200` | Milliseconds between supervision passes / state writes. |
| `--cwd` | — | Working directory for the workers. |
| `--dir` | — | Runtime directory override. |
| `--json` | — | Render the result as JSON. |

It returns the daemon pid as soon as the daemon is alive, so you can chain
`start` → `status` in scripts.

## ps

```bash
ps [options]
```

Lists every supervised pool in the runtime directory, one summary line each
(name, running, active, size, total_restarts).

Returns exit code `0` when at least one pool exists, `1` when none do.

## status

```bash
status NAME [options]
```

Reports the live state of one pool: daemon pid, command, size, restart policy,
active workers, total restarts, log path, and the per-slot worker table (pid,
running, restarts, exit code).

Returns `0` while the daemon is running, `1` when it is not (or has no state
file).

## kill

```bash
kill NAME SLOT [options]
```

Sends SIGTERM to one worker slot. The daemon respawns the slot under the
restart policy, exactly as it would a crashed worker.

```bash
php vendor/bin/supervise kill mail 0
```

Returns `0` if the pid was signaled, `1` otherwise (no such supervisor, no
recorded worker pid for the slot, or the slot is already dead → nothing to
signal).

## stop

```bash
stop NAME [options]
```

Sends SIGTERM to the daemon, which gracefully stops its workers, removes the
pid file, writes a final state snapshot, and exits `0`.

Returns `0` if the signal was sent, `1` if the supervisor is not running.

## logs

```bash
logs NAME [options]
```

Prints the combined worker log (worker lines `[slot N] …` and supervisor
events `[supervisor] …`).

| Option | Default | Meaning |
| --- | --- | --- |
| `--lines` | `50` | Last N lines to print. |
| `--dir` | — | Runtime directory override. |

(`logs` has no `--json`; it prints the raw log either way.)

## list and docs

```bash
php vendor/bin/supervise list
```

Lists the available commands with their descriptions.

```bash
php vendor/bin/supervise docs
```

Regenerates the per-command reference markdown under `docs/commands/` from each
command's PHPDoc annotations.

Add `--help` to any command for its usage:

```bash
php vendor/bin/supervise start --help
```

## Exit codes

| Command | 0 | 1 |
| --- | --- | --- |
| `start` | daemonized | — |
| `ps` | at least one pool | no pools |
| `status` | daemon running | not running / no state |
| `kill` | pid signaled | nothing to signal |
| `stop` | signal sent | not running |
| `logs` | log non-empty | no output yet |

`list`/`docs` exit `0` on success.

## Scripting with --json

Every reporting command accepts `--json` for machine-readable output:

```bash
php vendor/bin/supervise ps --json
php vendor/bin/supervise status mail --json
```

Example with `jq`:

```bash
php vendor/bin/supervise status mail --json --dir=/var/run/sv | jq '.workers[0].pid'
```

This is useful for health checks and monitoring: pair `status --json` with the
exit code (non-zero when not running) to alert on daemon loss.

## Next steps

- [Managed supervisor](managed-supervisor.md) — the library API the CLI wraps.
- [Operations and troubleshooting](operations.md) — exit codes, debugging, and
  common failure modes.