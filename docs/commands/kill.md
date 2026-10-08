# `kill`

Terminates one worker slot; the daemon restarts it under the policy.

## Usage

```bash
kill NAME SLOT [options]
```

## Arguments

| Name | Type | Required | Description |
|---|---|---|---|
| `name` | `string` | No | Name of the supervisor. |
| `slot` | `int` | No | Zero-based worker slot to terminate. |

## Options

| Option | Type | Required | Description |
|---|---|---|---|
| `--json` | `bool` | No | Render the result as JSON. |
| `--dir` | `string` | No | Runtime directory override (defaults to ~/.strukt/supervisor). |
