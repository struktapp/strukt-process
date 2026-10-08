# Process pools

A `ProcessPool` is the concurrency primitive: a fixed number of copies of one
command, all draining from the same parent without blocking.

See [architecture.md](architecture.md) for where pools sit in the stack.

- [When to use a pool](#when-to-use-a-pool)
- [Starting a pool](#starting-a-pool)
- [The pool() helper](#the-pool-helper)
- [Draining output](#draining-output)
- [Stopping a pool](#stopping-a-pool)
- [Restarting one slot](#restarting-one-slot)
- [Introspection](#introspection)
- [Command forms and the exec wrapper](#command-forms-and-the-exec-wrapper)
- [Lifecycle guarantees](#lifecycle-guarantees)

## When to use a pool

A pool fits the "spawn N workers, feed a queue, later tear them all down"
loop, where every worker lives for the whole batch rather than for one
command. Typically each worker is the same script and picks work until told
to stop:

```php
use Strukt\ProcessPool;

$pool = new ProcessPool(3);
$pool->start(PHP_BINARY . ' ' . escapeshellarg('/path/to/worker.php'));

while ($pool->running()) {
    $pool->drain(static function (int $index, string $line): void {
        printf("worker %d: %s", $index, $line);
    });
    // 'running' is false once all workers have exited
}

$pool->stop();
```

If you need a pool that also **restarts dead workers**, see
[process-supervisor.md](process-supervisor.md) — `ProcessSupervisor` is a pool
plus a restart policy. If you need the pool to keep running as a background
daemon, see [managed-supervisor.md](managed-supervisor.md).

## Starting a pool

```php
public function __construct(int $size)
public function start(string|array $command, ?string $cwd = null, ?array $env = null): void
```

`start()` spawns `$size` concurrent copies. Each worker gets its own three
pipes, so outputs stay separate. Calling `start()` twice throws
`RuntimeException`.

`$size = 0` is a valid, inert pool: nothing runs, `running()` is `false`,
`stop()` returns `[]`.

## The pool() helper

`pool()` constructs **and starts** in one call:

```php
$pool = pool(3, PHP_BINARY . ' ' . escapeshellarg($workerScript));
// equivalent to:
$pool = new ProcessPool(3);
$pool->start(PHP_BINARY . ' ' . escapeshellarg($workerScript));
```

## Draining output

```php
public function drain(?callable $callback = null): void
```

`drain()` reads whatever each worker produced since the last poll, **without
blocking**. Complete standard-output lines go to the callback as
`(int $index, string $line)`; the spawn index lets you attribute lines to a
worker:

```php
$pool->drain(static function (int $index, string $line): void {
    printf("[worker %d] %s", $index, $line);
});
```

Notes:

- Standard error is drained and retained on the worker (see `Process::error()`)
  but is never dispatched.
- With no callback, `drain()` still records output on the workers for later
  `Process::output()` access.
- A trailing fragment without a newline is delivered on the worker's next
  drain once the worker has exited.

## Stopping a pool

```php
public function stop(): array   // map<int, int> exit codes keyed by spawn index
```

`stop()` terminates every still-running worker, reaps it, and returns exit
codes keyed by spawn index. After `stop()`, `active()` and `running()` report
the pool as empty.

## Restarting one slot

```php
public function restart(int $index, string|array $command, ?string $cwd = null, ?array $env = null): void
```

Replaces one worker in place: the current worker (whether dead or alive) is
closed and a fresh copy of `$command` spawns at the same index. Slot identity
is preserved, so callers that track per-slot state keep their numbering. An
unknown index throws `OutOfBoundsException`.

## Introspection

| Method | Returns | Behavior |
| --- | --- | --- |
| `count()` | int | The configured pool size. |
| `active()` | int | Workers still running right now. |
| `running()` | bool | True while any worker runs. |
| `processes()` | list\<Process\> | Workers in spawn order. |
| `process(int $index)` | Process | One worker by spawn index; throws `OutOfBoundsException`. |

## Command forms and the exec wrapper

`start()`/`restart()` accept `string|array`:

- **string** — runs through `/bin/sh -c`; pipelines, redirection, and
  `$VAR` expansion work.
- **array** — runs the program directly; no shell involved, ideal for
  untrusted or heavily quoted arguments.

For a plain string command (no pipes, redirection, quoting, or
substitution), the pool **wraps it in `exec `** so the shell replaces itself
and the tracked pid is the real worker. This is deliberate: without it, the
shell could fork a child for the command, and terminating the pool would kill
only the shell and orphan the actual worker. See
[architecture.md](./architecture.md#design-decisions).

```php
$pool->start('php worker.php');          // exec-wrapped → real pid tracked
$pool->start('tail -f /var/log/app');    // has redirection → shell keeps it
$pool->start([PHP_BINARY, 'worker.php']); // no shell at all
```

## Lifecycle guarantees

- Workers have standard input closed up front (supervised workers rarely read
  it, and an open unused input pipe risks a deadlock).
- `ProcessPool::__destruct()` terminates and closes every worker, so an
  abandoned pool never leaves processes behind.
- Pipe close operations are idempotent.

## Next steps

- [Process supervisor](process-supervisor.md) — pools that revive dead workers.
- [Managed supervisor](managed-supervisor.md) — pools as observable daemons.