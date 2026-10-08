# Installation and requirements

## Requirements

Strukt Process targets PHP 8.2 and later on Unix-like systems.

The **core** needs nothing beyond PHP: running commands, capturing output,
interactive prompts, pools, and supervision all work with the standard stream
functions.

Two optional extensions unlock additional features:

| Extension | Unlocks |
| --- | --- |
| `pcntl` | Managed daemons (`ManagedSupervisor`) — process forking and signal handling. |
| `posix` | Daemon lifecycle (`posix_setsid`) and static control (`posix_kill` for status/kill/stop). |

Without them, the daemon API degrades gracefully: `ManagedSupervisor::start()`
throws on a missing `pcntl_fork`, and `stop()`/`kill()`/`running()` return
`false`/`null` when `posix_kill` is unavailable.

## Composer

```bash
composer require strukt/process
```

The package depends on `strukt/base` (for the helper registry) and, since the
supervisor CLI is wired through it, `strukt/console`.

## Autoloading

The package ships one PSR-4 namespace mapping and one file mapping:

- `Strukt\` → `src/Strukt/` (classes)
- `src/helpers.php` (global helper functions)

Composer's `files` autoload registers the helpers, so `process()`, `pool()`,
`supervisor()`, `pprocess()`, and `switchChannels()` are available in any file
once the autoloader is in play.

## The binary

`bin/supervise` is advertised as a Composer binary, so it is installed in
`vendor/bin/` alongside your dependencies. The shim resolves the autoloader
from the **current working directory**, which means:

- run it from the project root (where `vendor/autoload.php` lives), or
- invoke it through a wrapper that sets the working directory.

```bash
# project-root working directory
php vendor/bin/supervise list
```

## Development install

If you cloned the source, install dev dependencies and run the suite:

```bash
composer update
composer test      # Pest suite
composer lint      # php -l on src, bin, and tests
composer validate --no-check-publish
```

The test suite covers command batches, line callbacks, no-argument callbacks,
standard-error capture, channel switching, interactive input, worker pools,
supervision policy, the managed daemon, the CLI, and helper registration.

## Next steps

- [Quick start](quickstart.md) for a worked example.
- [Running commands](running-commands.md) for the command API in depth.