# Process supervisor

`ProcessSupervisor` is a `ProcessPool` with a restart policy: when a worker
exits — crashes or finishes — the supervisor replaces it with a fresh copy of
the original command, up to configurable limits.

See [architecture.md](architecture.md) for where the supervisor sits in the
stack.

- [Concepts](#concepts)
- [Starting a supervisor](#starting-a-supervisor)
- [The supervise() loop](#the-supervise-loop)
- [The restart policy](#the-restart-policy)
- [The exit hook](#the-exit-hook)
- [Stopping](#stopping)
- [Introspection](#introspection)
- [Restart semantics](#restart-semantics)

## Concepts

- **Slot.** Each worker occupies a fixed, zero-based slot. When a slot is
  restarted, the replacement keeps the same index, so callers tracking
  per-slot state keep their numbering.
- **Policy.** Two parameters govern resurrection: `restartCap` (how many times
  one slot may be restarted) and `backoff` (the minimum gap between restarts
  of the same slot).
- **Exit hook.** A callback fired exactly once per dead worker, whether or not
  it will be restarted — the supervisor's way of telling the owner "a worker
  is gone, reclaim its work".

## Starting a supervisor

```php
public function __construct(
    int $size,
    int $restartCap = -1,
    float $backoff = 0.0,
)

public function start(string|array $command, ?string $cwd = null, ?array $env = null): void
```

`start()` spawns `$size` workers and arms the policy. As with pools, the
`supervisor()` helper constructs **and starts** in one call:

```php
$supervisor = supervisor(3, PHP_BINARY . ' ' . escapeshellarg($worker), restartCap: 5);
```

## The supervise() loop

`supervise()` is the only method you must call from your loop:

```php
public function supervise(?callable $callback = null): void
```

Each call does two things, in order:

1. **Drains** worker output, dispatching complete lines to the optional
   callback as `(int $index, string $line)` — so an exit hook sees any final
   lines a worker wrote before dying.
2. **Revives** each dead slot under the restart policy.

```php
while (true) {
    $supervisor->supervise(static function (int $index, string $line): void {
        printf("worker %d: %s", $index, $line);
    });
    usleep(100_000);
}
```

Standard error is drained and retained on the workers but not forwarded to the
callback.

## The restart policy

| Parameter | Default | Effect |
| --- | --- | --- |
| `restartCap` | `-1` | Maximum restarts per slot. `-1` never gives up; `0` restarts no one. |
| `backoff` | `0.0` | Minimum seconds that must elapse after a restart before the same slot may restart again. |

The backoff breaks tight crash loops: a worker that dies instantly is not
restarted a hundred times a second. The restart cap eventually lets a slot
stay dead so the supervisor does not resurrect a permanently broken worker.

A slot that has exhausted its cap, or is inside its backoff window, is left
dead for that pass. On every later `supervise()` call the supervisor keeps
watching it, so the slot can come back once the window passes (when the cap is
not exhausted) — the worker is not abandoned, just throttled.

## The exit hook

```php
public function onExit(callable $callback): void
```

The callback receives `(int $index, int $exitCode)` and fires **once** per
dead worker, before any restart of that slot in the same pass. It fires even
when the worker will not be restarted (cap exhausted, backoff active), which
is exactly when the owner most needs to reclaim in-flight work:

```php
$supervisor->onExit(static function (int $index, int $exitCode): void {
    if ($exitCode !== 0) {
        log()->error("worker $index crashed", ['index' => $index, 'code' => $exitCode]);
        requeueAnythingTheWorkerLeftInFlight($index);
    }
});
```

## Stopping

```php
public function stop(): array   // map<int, int> exit codes keyed by slot
```

`stop()` terminates every worker and reaps it, returning the exit codes.

## Introspection

| Method | Returns | Behavior |
| --- | --- | --- |
| `count()` | int | The configured pool size. |
| `active()` | int | Workers still running. |
| `running()` | bool | True while any worker runs. |
| `processes()` | list\<Process\> | Workers in slot order. |
| `process(int $index)` | Process | One worker by slot index; throws `OutOfBoundsException`. |
| `restarts(int $index)` | int | How many times a slot has been restarted. |
| `totalRestarts()` | int | All restarts across every slot. |

## Restart semantics

The slot is replaced with the **original** command, working directory, and
environment captured at `start()`. Replacement spawns through the same
`ProcessPool` machinery (and therefore the same exec-wrapping rules as
[pools](process-pool.md#command-forms-and-the-exec-wrapper)).

The restart does not wait: the fresh worker is spawned immediately, and the
supervisor resumes from its loop. The inner pool handles closing the dead
worker's resources.

## Next steps

- [Managed supervisor](managed-supervisor.md) — running this exact loop as a
  detachable, observable daemon.
- [Supervisor CLI](cli.md) — the same capability as a shell tool.