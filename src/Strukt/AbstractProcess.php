<?php

namespace Strukt;

use Strukt\Contract\ProcessInterface;

/**
 * Provides common resource and pipe management for process implementations.
 */
abstract class AbstractProcess implements ProcessInterface
{
    /** @var resource|null Operating-system process handle. */
    protected mixed $process = null;

    /** @var resource|null Standard input pipe. */
    protected mixed $stdin = null;

    /** @var resource|null Standard output pipe. */
    protected mixed $stdout = null;

    /** @var resource|null Standard error pipe. */
    protected mixed $stderr = null;

    /** Exit code captured when the process is closed. */
    protected ?int $exitCode = null;

    /** All output bytes captured while the process was managed. */
    protected string $outputBuffer = '';

    /** All error bytes captured while the process was managed. */
    protected string $errorBuffer = '';

    /**
     * Returns the current operating-system process status.
     *
     * @return array<string, mixed> Status returned by proc_get_status().
     *
     * @throws \RuntimeException When this object no longer owns a process.
     */
    public function status(): array
    {
        if (!is_resource($this->process)) {
            throw new \RuntimeException('The process handle is closed.');
        }

        $status = @proc_get_status($this->process);
        if (!is_array($status)) {
            throw new \RuntimeException('Unable to read the process status.');
        }

        return $status;
    }

    /**
     * Reads available standard output and retains it for later access.
     *
     * @return string|false Output bytes, or false when the output pipe is absent.
     */
    public function read(): string|false
    {
        if (!is_resource($this->stdout)) {
            return $this->outputBuffer === '' ? false : $this->outputBuffer;
        }

        $output = @stream_get_contents($this->stdout);
        if ($output === false) {
            return false;
        }

        if ($output !== '') {
            $this->recordOutput($output);
        }

        return $output;
    }

    /**
     * Reads available standard error and retains it for later access.
     *
     * @return string|false Error bytes, or false when the error pipe is absent.
     */
    public function error(): string|false
    {
        if (!is_resource($this->stderr)) {
            return $this->errorBuffer === '' ? false : $this->errorBuffer;
        }

        $error = @stream_get_contents($this->stderr);
        if ($error === false) {
            return false;
        }

        if ($error !== '') {
            $this->recordError($error);
        }

        return $error;
    }

    /**
     * Reads one line from standard output.
     *
     * @return string|false A line including its line ending, or false at EOF.
     */
    public function readline(): string|false
    {
        if (!is_resource($this->stdout)) {
            return false;
        }

        $line = @fgets($this->stdout);
        if ($line === false) {
            return false;
        }

        $this->recordOutput($line);

        return $line;
    }

    /**
     * Terminates the process when it is still running.
     *
     * @return void
     *
     * @throws \RuntimeException When the operating system rejects termination.
     */
    public function terminate(): void
    {
        if (!is_resource($this->process) || !$this->isRunning()) {
            return;
        }

        if (!@proc_terminate($this->process)) {
            if ($this->isRunning()) {
                throw new \RuntimeException('Unable to terminate the process.');
            }
        }
    }

    /**
     * Closes pipes, reaps the process, and returns its exit code.
     *
     * @return int Process exit code, or -1 when already closed.
     */
    public function close(): int
    {
        if (!is_resource($this->process)) {
            return $this->exitCode ?? -1;
        }

        $this->closePipes();
        $exitCode = @proc_close($this->process);
        $this->process = null;
        $this->exitCode = is_int($exitCode) ? $exitCode : -1;

        return $this->exitCode;
    }

    /**
     * Closes standard input.
     *
     * @return bool True when the input pipe is closed or already closed.
     */
    public function closeInput(): bool
    {
        return $this->closePipe('stdin');
    }

    /**
     * Closes standard output.
     *
     * @return bool True when the output pipe is closed or already closed.
     */
    public function closeOutput(): bool
    {
        return $this->closePipe('stdout');
    }

    /**
     * Closes standard error.
     *
     * @return bool True when the error pipe is closed or already closed.
     */
    public function closeError(): bool
    {
        return $this->closePipe('stderr');
    }

    /**
     * Closes all process pipes.
     *
     * @return void
     */
    public function closePipes(): void
    {
        $this->closeInput();
        $this->closeOutput();
        $this->closeError();
    }

    /**
     * Terminates a running process and then closes its resources.
     *
     * @return void
     */
    public function closeAll(): void
    {
        $this->terminate();
        $this->close();
    }

    /**
     * Checks whether the process is still running.
     *
     * @return bool True while the process is running.
     */
    public function isRunning(): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }

        $status = @proc_get_status($this->process);

        return is_array($status) && ($status['running'] ?? false) === true;
    }

    /**
     * Releases process resources without allowing destructor errors to escape.
     *
     * @return void
     */
    public function __destruct()
    {
        try {
            $this->terminate();
        } catch (\Throwable) {
            // Destructors must not interrupt shutdown.
        }

        try {
            $this->close();
        } catch (\Throwable) {
            // Destructors must not interrupt shutdown.
        }
    }

    /**
     * Adds bytes to the retained standard-output buffer.
     *
     * @param string $output Output bytes.
     * @return void
     */
    protected function recordOutput(string $output): void
    {
        $this->outputBuffer .= $output;
    }

    /**
     * Adds bytes to the retained standard-error buffer.
     *
     * @param string $error Error bytes.
     * @return void
     */
    protected function recordError(string $error): void
    {
        $this->errorBuffer .= $error;
    }

    /**
     * Returns all retained standard output.
     *
     * @return string Captured output.
     */
    protected function capturedOutput(): string
    {
        return $this->outputBuffer;
    }

    /**
     * Returns all retained standard error.
     *
     * @return string Captured error output.
     */
    protected function capturedError(): string
    {
        return $this->errorBuffer;
    }

    /**
     * Closes one named pipe and clears its resource handle.
     *
     * @param string $property Pipe property name.
     * @return bool True when the pipe is closed or already closed.
     */
    private function closePipe(string $property): bool
    {
        $pipe = $this->{$property};
        if (!is_resource($pipe)) {
            $this->{$property} = null;

            return true;
        }

        $closed = @fclose($pipe);
        $this->{$property} = null;

        return $closed;
    }
}
