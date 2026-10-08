# `status`

Reports the live state of one supervised pool.

## Usage

```bash
status NAME [options]
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
