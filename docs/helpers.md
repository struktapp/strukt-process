# Global helpers

`src/helpers.php` is autoloaded via Composer's `files` directive. Each helper
is registered with the Strukt Base helper registry and available as a plain
PHP function **only when its name is not already defined**, so application
globals always win.

| Helper | Signature | Returns | Purpose |
| --- | --- | --- | --- |
| `switchChannels` | `bool $switch = true` | `void` | Swap output/error mapping for newly created processes. |
| `process` | `string\|array $commands, ?callable $callback = null` | `ProcessResult` | Run one command or a sequential batch. |
| `pprocess` | `string $command` | `PromptableProcessInterface` | Start an interactive prompt process. |
| `pool` | `int $size, string\|list<string> $command, ?string $cwd = null, ?array $env = null` | `ProcessPool` | Start a concurrent worker pool. |
| `supervisor` | `int $size, string\|list<string> $command, int $restartCap = -1, float $backoff = 0.0, ?string $cwd = null, ?array $env = null` | `ProcessSupervisor` | Start a pool that restarts dead workers. |

Details for each are in the topic guides; this page is the quick lookup:

- `switchChannels()` — see [running-commands.md](running-commands.md#channel-switching).
- `process()` — see [running-commands.md](running-commands.md).
- `pprocess()` — see [interactive-processes.md](interactive-processes.md).
- `pool()` — see [process-pool.md](process-pool.md#the-pool-helper).
- `supervisor()` — see [process-supervisor.md](process-supervisor.md).

## Design notes

Helpers never implement logic; they construct the corresponding class and
delegate. `process()` and `pprocess()` are pure delegators, while `pool()` and
`supervisor()` are **start-and-return** shortcuts mirroring their constructors
plus a `start()` call.

`ManagedSupervisor` intentionally has **no helper**: it is the one API that is
deliberately class-first, because it deals with names, directories, and
daemonizing rather than a throwaway result.