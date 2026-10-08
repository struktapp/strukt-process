# Operations and troubleshooting

Practical notes for running supervised processes in the real world: resource
lifecycle, performance behavior, exit codes, and the failure modes you are
most likely to hit.

- [Exit codes, CLI vs process](#exit-codes-cli-vs-process)
- [The daemon lifecycle, end to end](#the-daemon-lifecycle-end-to-end)
- [Resource lifecycle in your code](#resource-lifecycle-in-your-code)
- [Performance characteristics](#performance-characteristics)
- [Common failure modes](#common-failure-modes)
- [Debugging daemons](#debugging-daemons)
- [Operational checklist](#operational-checklist)

## Exit codes, CLI vs process

CLI exit codes are meaningful for scripts (see [cli.md](cli.md#exit-codes)):

| Command | 0 | 1 |
| --- | --- | --- |
| `ps` | at least one pool | no pools |
| `status` | daemon running | not running / no state yet |
| `kill` | pid signaled | nothing to signal |
| `stop` | signal sent | not running |
| `logs` | log non-empty | no output yet |

`status` is your health-check primitive: combine the exit code with `--json`:

```bash
if php vendor/bin/supervise status mail --json >/dev/null; then
    echo "mail pool healthy"
else
    php vendor/bin/supervise status mail --json
fi
```

Worker exit codes are per-process integers, returned by
`Process::close()` / `ProcessPool::stop()` / `ProcessSupervisor::stop()`. A
dead worker is any non-running slot; an exit code of `0` means it finished
cleanly, anything else means it crashed or was signaled.

## The daemon lifecycle, end to end

The readable story of a daemon, from `start` to `stop`:

```
start       → fork, child detaches (setsid), stdio → /dev/null
            → child writes supervise.pid, starts ProcessSupervisor
            → parent sees pid file, returns daemon pid

each pass   → drain output → log "[slot N] …"
            → revive dead slots → log "[supervisor] worker N exited with code C"
            → rewrite state.json (atomic)

stop        → SIGTERM/SIGINT → finish pass → supervisor->stop()
            → log "[supervisor] stopped" → unlink supervise.pid
            → final state.json → exit 0
```

After a clean stop the only artifacts left are `state.json` (with
`running: false`) and `output.log` (with the `stopped` line). The pid file is
removed, so `start` for the same name can run again.

## Resource lifecycle in your code

- **`process()`** close-input → wait (drain both channels) → reap. You only
  read results afterward.
- **Interactive prompts** (manual lifecycle): `write()` → `closeInput()` →
  read → `close()`/`closeAll()`. Read before closing if output is not yet
  captured.
- **Pools / supervisors** manage workers for you; you only call `drain`/`supervise`
  in a loop and `stop()` when done.
- Every close is idempotent and every process/pool destructor attempts safe
  cleanup, so leaks are the exception, not the rule.

Rule of thumb: if you abandon a long-running pool without stopping it, the
workers keep running; prefer a supervisor with `stop()` (or the CLI) for
anything you cannot tear down deterministically.

## Performance characteristics

- Output and error pipes are drained **concurrently** with non-blocking reads;
  a flood on stderr cannot stall stdout.
- `poll()`/`drain()` never block; a supervisor loop tick is bounded by how
  much output workers produced, not by any worker's lifetime.
- The line callback is invoked directly — no event wrapper per line.
- Command batches are sequential and deterministic in ordering.
- Output is appended in chunks; strings are never rebuilt line-by-line.
- The daemon's state write is one small file per pass; `interval` (default
  200 ms) is the knob if you want coarser or finer state cadence.

### `interval` guidance

- **200 ms** (default): responsive `status`/`ps`, fine for most pools.
- Lower (e.g. 50 ms): journals of change appear faster, but the daemon does
  more file I/O and `usleep` is honored (min 10 ms).
- Higher (e.g. 1000 ms): fewer writes, cheaper on very large pools, but
  `status` can lag reality by up to a second.

Restart latency is not bound by `interval`: a dead slot is detected and
restarted on the next `supervise()` pass.

## Common failure modes

### "Supervisor 'mail' is already running."

A stale pid file or a genuinely live daemon. `running()` verifies the pid with
`posix_kill($pid, 0)`:

1. Check `php vendor/bin/supervise ps` and `ps -p <pid>`.
2. If the pid is alive, `stop` it (or `kill` it if it is wedged).
3. If the pid is stale (process gone), remove the leftover `supervise.pid` and
   retry `start`.

### `stop` returns 1 ("not running") but workers linger

`stop()` only signals the daemon and reports whether the signal went out. If
the daemon crashed hard (SIGKILL) before its graceful shutdown ran, its
workers can be orphaned. Look for them:

```bash
ps -f --ppid "$(cat ~/.strukt/supervisor/mail/supervise.pid 2>/dev/null)"
```

and terminate them explicitly. A crashed daemon also leaves the pid file in
place; `running()` reports `false` because the pid is dead.

### Workers restart in a tight loop

`--backoff` is the throttle. A worker that dies instantly can otherwise be
resurrected thousands of times:

```bash
php vendor/bin/supervise start mail "php worker.php" --backoff=1
```

Set `--restart-cap` to eventually let a permanently broken slot stay dead.

### "Unable to start worker process N."

`proc_open` failed to spawn a worker. Causes: wrong `--cwd`, a command in the
wrong directory, or a missing/broken interpreter path. The exception tells you
the position (`process_pool.php`), and the daemon logs a `[supervisor] worker N
exited with code …` line if a worker starts then dies.

### `logs` shows nothing although workers run

`logs` tails `output.log`. A supervisor with no other output still logs the
`[supervisor] started` line, so "no output yet" means the daemon is not
running or the wrong `--dir` is in play — pass the same `--dir` to `start`,
`status`, and `logs`.

### Beginners: the CLI cannot find the autoloader

```text
Composer autoloader not found. Run 'composer install' in your project.
```

`bin/supervise` resolves the autoloader from the **current working
directory**. Run it from the project root (where `vendor/autoload.php` lives),
or call it via a path wrapper that sets the working directory.

## Debugging daemons

The daemon redirects its own stdout/stderr to `/dev/null`, so exceptions
inside the loop are not visible on a terminal. Best sources of truth:

1. **`output.log`** — the only daemon-authored byte stream: worker lines and
   supervisor events.
2. **`state.json`** — a machine-readable snapshot each pass; check `running`,
   `active`, `total_restarts`, and per-worker `exit_code`.
3. **`ps`** — confirm the daemon and each worker's real pid:

```bash
php bin/supervise ps --json
ps -f --ppid "$(cat ~/.strukt/supervisor/mail/supervise.pid)"
```

To see daemon-side failures while developing, run the inner loop in the
foreground instead — `ProcessSupervisor` + a plain `php script.php` in your
terminal shows every exception directly.

## Operational checklist

- [ ] Run `composer test` before deploying; the suite exercises the whole
      package including the daemon and CLI.
- [ ] Pick a **stable base directory** for daemons (`--dir=/var/run/strukt` or
      `STRUKT_SUPERVISOR_DIR`); make sure it exists and is writable by the
      daemon user.
- [ ] Pass `--backoff` to pools whose workers can crash immediately.
- [ ] Set `--restart-cap` when a worker must not resurrect forever.
- [ ] Put `start` where it is idempotent (a service manager or boot script):
      starting an already-running supervisor throws.
- [ ] Monitor `status --json` exit codes, not just log grep.
- [ ] Stop daemons through `stop` (SIGTERM) so their workers are reaped, and
      test that path as part of your deploy/rollback.