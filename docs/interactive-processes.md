# Interactive processes

Some commands expect input on standard input — a shell, an FTP client, a
prompted installer. `pprocess()` starts such a command and keeps standard
input open for writing.

- [The pprocess() helper](#the-pprocess-helper)
- [Writing input](#writing-input)
- [Reading the response](#reading-the-response)
- [Controlling streams](#controlling-streams)
- [A worked example](#a-worked-example)

## The pprocess() helper

```php
pprocess(string $command): PromptableProcessInterface
```

It returns a `Strukt\Pprocess` (via `Strukt\Contract\PromptableProcessInterface`)
that behaves like a regular `Process` plus a `write()` method:

```php
$prompt = pprocess('read password; printf "%s\n" "$password"');
```

The command must be non-empty, or `InvalidArgumentException` is thrown.
`proc_open()` failures throw `RuntimeException`.

## Writing input

```php
$prompt->write('secret'); // int: bytes written
```

`write()` appends `PHP_EOL` to the supplied input — you do not add the
newline. It returns the number of bytes written and throws
`RuntimeException` when standard input is closed or the write fails.

### Closing standard input

When the child must see EOF (many prompts read until their input pipe closes),
call `closeInput()`:

```php
$prompt->write('password');
$prompt->closeInput();
```

## Reading the response

`Pprocess` extends `AbstractProcess`, so all standard reading is available:

```php
$prompt->write('secret');
$prompt->closeInput();

echo $prompt->read();       // full captured output
echo $prompt->readline();   // one line, including its line ending
echo $prompt->error();      // captured stderr
$prompt->isRunning();       // bool
```

Read output before calling `close()` if it has not already been captured —
`close()` reaps the child and releases its pipes.

## Controlling streams

Interactive processes support the full `ProcessInterface` lifecycle:

```php
$prompt->terminate();   // SIGTERM a running child
$prompt->close();       // reap, return exit code (int)
$prompt->closeAll();    // terminate (if running) then close
$prompt->closeInput();  // bool
$prompt->closeOutput(); // bool
$prompt->closeError();  // bool
$prompt->closePipes();  // both output and error
```

All close operations are idempotent — calling them twice is safe. The
destructor also attempts cleanup so an abandoned prompt never leaks.

## A worked example

A small scripted session against a prompt that echoes its input back:

```php
$prompt = pprocess('read name; read city; printf "%s lives in %s\n" "$name" "$city"');

$prompt->write('Ada');
$prompt->write('Paris');
$prompt->closeInput();

echo $prompt->read();       // "Ada lives in Paris\n"
echo $prompt->close();      // 0
```

## Next steps

- [Process pools](process-pool.md) for many concurrent workers.
- [Process supervisor](process-supervisor.md) for resilient worker sets.