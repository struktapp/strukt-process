# Strukt Process

[![Latest Stable Version](https://img.shields.io/packagist/v/strukt/process.svg)](https://packagist.org/packages/strukt/process)
[![License](https://img.shields.io/packagist/l/strukt/process.svg)](https://packagist.org/packages/strukt/process)

Typed, resource-safe process execution for PHP 8.2 and later. Strukt Process
supports sequential command batches, line callbacks, captured output and
interactive prompt processes.

The package is a Strukt 3 rewrite of 'strukt2/process'. It keeps the original
global helpers and process concepts while improving type declarations,
resource cleanup, output draining, error capture, and documentation.

## Installation

```bash
composer require strukt/process
```

The package depends on 'strukt/base', which supplies the helper registry.

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
| process | string\|array<int|string, string> $commands, ?callable $callback = null | ProcessResult | Runs one command or a sequential command batch. |
| pprocess | string $command | PromptableProcessInterface | Starts an interactive process that accepts input through standard input. |

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
standard-error capture, channel switching, interactive input, cleanup, and
helper registration.

## License

Strukt Process is released under the MIT license.
