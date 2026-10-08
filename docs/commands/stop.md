# `stop`

Stops a supervised pool; the daemon reaps its workers.

## Usage

```bash
stop NAME [options]
```

## Arguments

| Name | Type | Required | Description |
|---|---|---|---|
| `name` | `string` | No | Name of the supervisor. |

## Options

| Option | Type | Required | Description |
|---|---|---|---|
| `--json` | `bool` | No | Render the result as JSON. |
| `--dir` | `string` | No | Runtime directory override (defaults to ~/.strukt/supervisor). |
