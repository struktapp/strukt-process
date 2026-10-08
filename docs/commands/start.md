# `start`

Starts a supervised pool of worker processes as a background daemon.

## Usage

```bash
start NAME COMMAND [options]
```

## Arguments

| Name | Type | Required | Description |
|---|---|---|---|
| `name` | `string` | No | Name used to identify the supervisor in later commands. |
| `command` | `string` | No | Command to run; quote it when it contains spaces. |

## Options

| Option | Type | Required | Description |
|---|---|---|---|
| `--json` | `bool` | No | Render the result as JSON. |
| `--size` | `int` | No | Number of worker processes to keep. |
| `--restart-cap` | `int` | No | Max restarts per slot before giving up; -1 for unlimited. |
| `--backoff` | `float` | No | Minimum seconds between restarts of the same slot. |
| `--interval` | `int` | No | Milliseconds between supervision passes. |
| `--cwd` | `string` | No | Working directory for the workers. |
| `--dir` | `string` | No | Runtime directory override (defaults to ~/.strukt/supervisor). |
