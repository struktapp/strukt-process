# Upgrading from Strukt 2

Strukt Process is the Strukt 3 rewrite of `strukt2/process`. The modern API is
source-compatible for the common cases, and the legacy contract namespace is
kept so old code continues to work while you migrate.

- [What changed](#what-changed)
- [What stayed the same](#what-stayed-the-same)
- [Compatibility classes](#compatibility-classes)
- [Rename your imports](#rename-your-imports)
- [Behavioral notes](#behavioral-notes)

## What changed

Strukt 3:

- Requires **PHP 8.2+** in contrast to Strukt 2's older floor.
- Rewrites the internals with **typed declarations, strict resource
  validation, concurrent output draining, and defensive destructors**.
- Adds the **supervision stack** that Strukt 2 did not have:
  `ProcessPool`, `ProcessSupervisor`, `ManagedSupervisor`, and the `supervise`
  CLI.
- Moves the primary contract implementations to the **root `Strukt`
  namespace** (`Strukt\Process`, `Strukt\Pprocess`) with the old
  `Strukt\Contract\*` names kept as deprecated subclasses.

## What stayed the same

- The **global helpers** — `process()`, `pprocess()`, `switchChannels()` —
  keep their names and shapes, so most application code that calls helpers
  needs no text changes.
- The process concepts (status, read/error/readline, terminate/close,
  closeInput/Output/Error/Pipes/All, isRunning) are unchanged.
- `process()` still returns a `ProcessResult` exposing `outputs()`, `errors()`,
  `resource()`, and iteration over `Process` objects.

## Compatibility classes

These deprecated classes exist purely so old `use` statements still resolve:

| Strukt 3 (preferred) | Strukt 2 (deprecated, still works) |
| --- | --- |
| `Strukt\AbstractProcess` | `Strukt\Contract\AbstractProcess` |
| `Strukt\AbstractPromptableProcess` | `Strukt\Contract\AbstractPromptableProcess` |

`Process` and `Pprocess` themselves extend the compatibility classes, so
`instanceof Strukt\Contract\*` checks keep passing.

## Rename your imports

If you were importing the concrete classes, prefer the root namespace:

```diff
- use Strukt\Contract\Process;
- use Strukt\Contract\Pprocess;
+ use Strukt\Process;
+ use Strukt\Pprocess;
```

Keep `use Strukt\Contract\ProcessInterface;` (the interface namespace is
unchanged and modern code continues to rely on it).

## Behavioral notes

- **Output draining is now concurrent.** The old sequential read-and-wait
  could deadlock when a child wrote a lot to stderr; `wait()` now uses
  `stream_select()` across both pipes. If you previously worked around this
  with workarounds, delete them.
- **Exit codes** are reaped at `close()`; for `process()` they are already
  available without an extra call.
- **`switchChannels()`** still exists with the same semantics: affects
  subsequent, newly constructed processes.
- **_Pipelines/`exec`-safety is new._** Supervisor worker pools wrap simple
  string commands in `exec` so termination targets the real worker rather than
  a shell wrapper — behavior is equivalent for running, but teardown is more
  honest.
- **Memory layout**: everything captured is retained in non-rebuilt chunks,
  and cleanup is idempotent; if your Strukt 2 code manually re-closed pipes it
  can simplify.

## Migration checklist

1. `composer require strukt/process` on PHP 8.2+.
2. Replace `use Strukt\Contract\{Process, Pprocess}` with the root-namespace
   classes.
3. Re-run the full suite; helper-based calls should need no edits.
4. Introduce supervision incrementally: `process()` → `pool()` →
   `supervisor()` → `ManagedSupervisor` + `supervise` for long-running pools.
5. Delete obsolete workarounds for the stderr-drain deadlock and for manual
   pipe re-closing.