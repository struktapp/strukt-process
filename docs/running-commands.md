# Running commands

The command layer is where everything starts: `process()` runs one command or
a sequential batch, drains output and error streams, and returns the captured
result together with the process objects.

- [The process() helper](#the-process-helper)
- [Output and error](#output-and-error)
- [Exit codes](#exit-codes)
- [Line callbacks](#line-callbacks)
- [Commands that use the shell](#commands-that-use-the-shell)
- [Channel switching](#channel-switching)
- [ProcessResult](#processresult)
- [Process::wait and Process::poll](#processwait-and-processpoll)
- [Error semantics](#error-semantics)

## The process() helper

```php
process(string|array $commands, ?callable $callback = null): ProcessResult
```

Pass a single command string, or an array of commands to run **sequentially**:

```php
$result = process('printf "hello\n"');
echo $result->outputs(); // hello

$result = process([
    'printf "compile\n"',
    'printf "test\n"',
]);
echo $result->outputs(0); // compile
echo $result->outputs(1); // test
```

Each command gets its own process resource and captured streams. An empty
array returns an empty `ProcessResult`. Invalid commands (non-strings, empty
strings) throw `InvalidArgumentException`.

## Output and error

Both standard streams are captured while the command runs:

```php
$result = process('sh -c "printf out; printf err >&2"');

echo $result->outputs(); // out
echo $result->errors();  // err

$result->resource()->output();       // out
$result->resource()->error();        // err
```

- `outputs(int $idx = 0): ?string` — captured standard output; `null` when the
  index has no entry, `''` when the command produced none.
- `errors(int $idx = 0): ?string` — captured standard error with the same
  null-versus-empty behavior.

## Exit codes

The exit code is available once the child has been reaped:

```php
$result = process('sh -c "exit 7"');

echo $result->resource()->close(); // 7
```

`close()` returns the exit code; `-1` when no process handle remains. For
`process()`, which waits internally, the code is already available.

## Line callbacks

The optional callback receives **complete logical output lines** — including
their line endings — as the command runs:

```php
$lines = [];
process('printf "one\ntwo\n"', static function (string $line) use (&$lines) {
    $lines[] = trim($line);
});

// ['one', 'two']
```

A trailing fragment with no newline is delivered once the child closes its
output stream, so nothing is ever silently dropped. Callbacks may also declare
no parameters. Standard error is drained to avoid pipe back-pressure, but it
is not sent to the callback — read it from `errors()` or `error()` instead.

## Commands that use the shell

A **string** command runs through the shell (`/bin/sh -c ...`), which gives
you pipes, redirection, and environment assignments:

```php
$result = process('printf "host: %s\n" "$HOSTNAME"');
```

An **array** command runs the program directly, bypassing the shell. Use it
when you pass untrusted or heavily quoted arguments and do not need shell
features:

```php
$result = process([PHP_BINARY, '/path/to/script.php', '--flag=val']);
```

## Channel switching

By default, output maps to standard output and error maps to standard error.
Some programs write their *useful* result to stderr. `switchChannels()` swaps
the logical mapping for all **newly constructed** processes:

```php
switchChannels(true);

$result = process('sh -c "printf output; printf error >&2"');

echo $result->outputs(); // error
echo $result->errors();  // output

switchChannels(false);   // restore for the rest of the request
```

The switch is static and affects only processes constructed after it; it never
mutates an existing `Process` object. The `switchChannels()` helper wraps
`Process::switchChannels()`.

## ProcessResult

`Strukt\ProcessResult` extends `ArrayIterator`, so it iterates as a list of
`Strukt\Process` objects:

```php
foreach ($result as $process) {
    echo $process->output();
}

$result->resource(0); // Process
$result->resource(9); // throws OutOfBoundsException
```

All three accessors take a zero-based command index and default to `0`.

## Process::wait and Process::poll

You rarely need these directly — `process()` uses them — but they define the
two draining strategies the whole package builds on:

- `wait(callable)` — busy but non-blocking: drains both output and error pipes
  with `stream_select()` until the child exits, dispatching complete output
  lines to the callback.
- `poll(?callable): bool` — reads whatever is available *right now*, returns
  whether the child still runs, and flushes a trailing half-line once it
  exits. Pools use `poll()` under the hood to supervise without blocking.

`Process::output()` returns all output captured through either path.

## Error semantics

| Condition | Exception |
| --- | --- |
| Empty or non-string command | `InvalidArgumentException` |
| `proc_open()` fails to start a command | `RuntimeException` |
| Constructor given a non-resource handle | `InvalidArgumentException` |
| Reading status on a closed handle | `RuntimeException` |
| `terminate()` rejected while running | `RuntimeException` |

## Next steps

- [Interactive processes](interactive-processes.md) for commands that need
  input.
- [Process pools](process-pool.md) for concurrency.