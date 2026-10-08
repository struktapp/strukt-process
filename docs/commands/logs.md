# `logs`

Prints the combined worker log of a supervised pool.

## Usage

```bash
logs NAME [options]
```

## Arguments

| Name | Type | Required | Description |
|---|---|---|---|
| `name` | `string` | No | Name of the supervisor. |

## Options

| Option | Type | Required | Description |
|---|---|---|---|
| `--lines` | `int` | No | Last N lines to print. |
| `--dir` | `string` | No | Runtime directory override (defaults to ~/.strukt/supervisor). |
