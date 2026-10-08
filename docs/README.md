# Strukt Process — Documentation

Strukt Process is a typed, resource-safe process execution library for
PHP 8.2 and later. It runs sequential command batches, streams output line by
line, captures standard error, drives interactive processes, supervises pools
of concurrent workers, and can run those workers as long-lived, externally
observable daemons behind the `supervise` command-line tool.

This documentation tree is the in-depth companion to the package
[README](../README.md). It is split into a topic guide, a reference, and an
architecture deep dive.

## Topic guide

| Document | Covers |
| --- | --- |
| [Installation and requirements](installation.md) | Composer install, PHP extension requirements, autoloading, integration with Strukt Base. |
| [Quick start](quickstart.md) | The fastest path from a command to a supervised daemon, with a worked example. |
| [Running commands](running-commands.md) | The `process()` helper, sequential batches, line callbacks, exit codes, error capture, and output draining. |
| [Channel switching](running-commands.md#channel-switching) | Swapping the logical output and error channels for odd programs. |
| [Interactive processes](interactive-processes.md) | The `pprocess()` helper, writing input, closing pipes, and reading results. |
| [Process pools](process-pool.md) | `ProcessPool`: fixed-size worker sets, non-blocking draining, teardown. |
| [Process supervisor](process-supervisor.md) | `ProcessSupervisor`: restart policy (cap, backoff, exit hook), the `supervise()` loop. |
| [Managed supervisor](managed-supervisor.md) | `ManagedSupervisor`: daemonizing, pid/state/log files, the static inspection and control API. |
| [Supervisor CLI](cli.md) | The `supervise` binary: `start`, `ps`, `status`, `kill`, `stop`, `logs`, JSON output, docs generation. |
| [Operations and troubleshooting](operations.md) | Resource lifecycle, performance, exit codes, common failure modes, and debugging daemons. |
| [Upgrading from Strukt 2](upgrading.md) | Compatibility contracts, breaking changes, and migration notes. |

## Reference

| Document | Covers |
| --- | --- |
| [Global helpers](helpers.md) | The autoloaded helper registry functions. |
| [API reference](api-reference.md) | Every public class, method, and interface, with signatures and contracts. |
| [Command reference](commands/) | Generated per-command reference for the `supervise` CLI. |

## Architecture

[architecture.md](architecture.md) explains how the pieces fit together: the
three supervision layers, the process contract hierarchy, the daemon lifecycle,
the runtime directory layout, and the design decisions behind the package.

## Command reference (generated)

The `supervise` CLI can regenerate its own reference:

```bash
php vendor/bin/supervise docs
```

This rewrites the table for `commands/*.md` from each command's PHPDoc block,
so the reference never drifts from the implementation.