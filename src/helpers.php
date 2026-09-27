<?php

use Strukt\Contract\PromptableProcessInterface;
use Strukt\Pprocess;
use Strukt\Process;
use Strukt\ProcessResult;

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
