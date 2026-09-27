<?php

namespace Strukt\Contract;

/**
 * Describes a managed operating-system process.
 */
interface ProcessInterface
{
    /**
     * Reads the current process status.
     *
     * @return array<string, mixed> Status returned by proc_get_status().
     *
     * @throws \RuntimeException When the process handle is unavailable.
     */
    public function getStatus(): array;

    /**
     * Reads available standard output.
     *
     * @return string|false Output bytes, or false when no output pipe exists.
     */
    public function read(): string|false;

    /**
     * Reads available standard error.
     *
     * @return string|false Error bytes, or false when no error pipe exists.
     */
    public function error(): string|false;

    /**
     * Reads one line from standard output.
     *
     * @return string|false A line including its line ending, or false at EOF.
     */
    public function readline(): string|false;

    /**
     * Sends a termination signal to a running process.
     *
     * @return void
     *
     * @throws \RuntimeException When termination is rejected by the operating system.
     */
    public function terminate(): void;

    /**
     * Closes the process and returns its exit code.
     *
     * @return int Process exit code, or -1 when no process handle remains.
     */
    public function close(): int;

    /**
     * Closes standard input.
     *
     * @return bool True when the input pipe is closed or already closed.
     */
    public function closeInput(): bool;

    /**
     * Closes standard output.
     *
     * @return bool True when the output pipe is closed or already closed.
     */
    public function closeOutput(): bool;

    /**
     * Closes standard error.
     *
     * @return bool True when the error pipe is closed or already closed.
     */
    public function closeError(): bool;

    /**
     * Closes all process pipes.
     *
     * @return void
     */
    public function closePipes(): void;

    /**
     * Terminates a running process and closes its resources.
     *
     * @return void
     */
    public function closeAll(): void;

    /**
     * Checks whether the process is still running.
     *
     * @return bool True while the operating-system process is running.
     */
    public function isRunning(): bool;
}
