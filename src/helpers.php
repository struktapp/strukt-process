<?php

use Strukt\Contract\PromptableProcessInterface;
use Strukt\Pprocess;
use Strukt\Process;
use Strukt\ProcessPool;
use Strukt\ProcessResult;
use Strukt\ProcessSupervisor;

if (function_exists('helper')) {
    helper('process');
}

if (!function_exists('switchChannels') && function_exists('helper_add') && helper_add('switchChannels')) {
    /**
     * Switches the logical standard-output and standard-error channels.
     *
     * @param bool $switch True to expose stderr as output.
     * @return void
     */
    function switchChannels(bool $switch = true): void
    {
        Process::switchChannels($switch);
    }
}

if (!function_exists('process') && function_exists('helper_add') && helper_add('process')) {
    /**
     * Runs one or more commands sequentially.
     *
     * @param string|array<int|string, string> $commands Command or commands.
     * @param callable|null $callback Optional callback receiving output lines.
     * @return ProcessResult Process resources and captured output.
     *
     * @throws \InvalidArgumentException When a command is empty or invalid.
     * @throws \RuntimeException When a command cannot be started.
     */
    function process(string|array $commands, ?callable $callback = null): ProcessResult
    {
        $commands = is_string($commands) ? [$commands] : array_values($commands);

        return Process::run($commands, $callback);
    }
}

if (!function_exists('pprocess') && function_exists('helper_add') && helper_add('pprocess')) {
    /**
     * Starts an interactive prompt process.
     *
     * @param string $command Command passed to proc_open().
     * @return PromptableProcessInterface Interactive process.
     *
     * @throws \InvalidArgumentException When the command is empty.
     * @throws \RuntimeException When the process cannot be started.
     */
    function pprocess(string $command): PromptableProcessInterface
    {
        return Pprocess::run($command);
    }
}

if (!function_exists('pool') && function_exists('helper_add') && helper_add('pool')) {
    /**
     * Starts a pool of concurrent worker processes and returns it.
     *
     * @param int $size Number of worker processes to keep.
     * @param string|list<string> $command Command passed to proc_open().
     * @param string|null $cwd Optional working directory for the workers.
     * @param array<string, string>|null $env Optional environment overrides.
     * @return ProcessPool Managed worker pool.
     *
     * @throws \RuntimeException When a worker cannot be started.
     */
    function pool(int $size, string|array $command, ?string $cwd = null, ?array $env = null): ProcessPool
    {
        $instance = new ProcessPool($size);
        $instance->start($command, $cwd, $env);

        return $instance;
    }
}

if (!function_exists('supervisor') && function_exists('helper_add') && helper_add('supervisor')) {
    /**
     * Starts a pool of worker processes that restarts any worker that dies.
     *
     * @param int $size Number of worker processes to keep.
     * @param string|list<string> $command Command passed to proc_open().
     * @param int $restartCap Maximum restarts per slot before giving up; -1 for unlimited.
     * @param float $backoff Minimum seconds between restarts of the same slot.
     * @param string|null $cwd Optional working directory for the workers.
     * @param array<string, string>|null $env Optional environment overrides.
     * @return ProcessSupervisor Supervised worker pool.
     *
     * @throws \RuntimeException When a worker cannot be started.
     */
    function supervisor(int $size, string|array $command, int $restartCap = -1, float $backoff = 0.0, ?string $cwd = null, ?array $env = null): ProcessSupervisor
    {
        $instance = new ProcessSupervisor($size, $restartCap, $backoff);
        $instance->start($command, $cwd, $env);

        return $instance;
    }
}
