<?php

namespace Strukt;

use Strukt\Contract\AbstractProcess as CompatibleProcess;

/**
 * Runs non-interactive commands and streams their output to a callback.
 */
class Process extends CompatibleProcess
{
    /**
     * Whether logical output and error channels should be swapped.
     */
    private static bool $switch = false;

    /** Pending partial output line for poll(). */
    private string $pollBuffer = '';

    /**
     * Creates a process from proc_open resources.
     *
     * @param resource $process Operating-system process handle.
     * @param resource $stdin Standard input pipe.
     * @param resource $stdout Standard output pipe.
     * @param resource $stderr Standard error pipe.
     *
     * @throws \InvalidArgumentException When a supplied handle is not a resource.
     */
    public function __construct(mixed $process, mixed $stdin, mixed $stdout, mixed $stderr)
    {
        $this->assertResource($process, 'process');
        $this->assertResource($stdin, 'stdin');
        $this->assertResource($stdout, 'stdout');
        $this->assertResource($stderr, 'stderr');

        $this->process = $process;
        $this->stdin = $stdin;

        if (static::$switch) {
            $this->stdout = $stderr;
            $this->stderr = $stdout;
        } else {
            $this->stdout = $stdout;
            $this->stderr = $stderr;
        }
    }

    /**
     * Selects whether logical output and error channels are swapped.
     *
     * @param bool $switch True to expose stderr as output and stdout as error.
     * @return void
     */
    public static function switchChannels(bool $switch = true): void
    {
        static::$switch = $switch;
    }

    /**
     * Waits for the process while draining both output channels.
     *
     * The callback receives each complete output line, including its line
     * ending. Standard error is drained concurrently but is not sent to the
     * callback.
     *
     * @param callable $callback Callback receiving output lines.
     * @return void
     */
    public function wait(callable $callback): void
    {
        $this->closeInput();

        $streams = [];
        if (is_resource($this->stdout)) {
            stream_set_blocking($this->stdout, false);
            $streams[] = $this->stdout;
        }

        if (is_resource($this->stderr)) {
            stream_set_blocking($this->stderr, false);
            $streams[] = $this->stderr;
        }

        $lineBuffer = '';

        while ($streams !== []) {
            $read = $streams;
            $write = null;
            $except = null;
            $selected = @stream_select($read, $write, $except, 0, 100000);

            if ($selected === false) {
                $streams = $this->removeClosedStreams($streams);
                if ($streams !== []) {
                    usleep(1000);
                }

                continue;
            }

            if ($selected === 0) {
                $streams = $this->removeClosedStreams($streams);
                continue;
            }

            foreach ($read as $stream) {
                $chunk = @fread($stream, 8192);
                if ($chunk === false) {
                    $streams = $this->removeStream($streams, $stream);
                    continue;
                }

                if ($chunk === '') {
                    if (feof($stream)) {
                        $streams = $this->removeStream($streams, $stream);
                    }

                    continue;
                }

                if ($stream === $this->stdout) {
                    $this->recordOutput($chunk);
                    $lineBuffer .= $chunk;
                    $this->dispatchLines($lineBuffer, $callback);
                } else {
                    $this->recordError($chunk);
                }
            }
        }

        if ($lineBuffer !== '') {
            $callback($lineBuffer);
        }

        $this->close();
    }

    /**
     * Returns all output captured by wait() or read().
     *
     * @return string Captured standard output.
     */
    public function output(): string
    {
        return $this->capturedOutput();
    }

    /**
     * Drains whatever output is available right now without blocking.
     *
     * Complete standard-output lines are dispatched to the callback and
     * retained for output(); standard error is drained and retained only. A
     * trailing fragment without a newline is delivered once the process exits,
     * so callers can poll() from a supervisor loop and never lose the tail.
     *
     * @param callable|null $callback Callback receiving complete output lines.
     * @return bool True while the process is still running.
     */
    public function poll(?callable $callback = null): bool
    {
        $callback ??= static function (string $line): void {
        };

        $streams = [];
        if (is_resource($this->stdout)) {
            stream_set_blocking($this->stdout, false);
            $streams[] = $this->stdout;
        }

        if (is_resource($this->stderr)) {
            stream_set_blocking($this->stderr, false);
            $streams[] = $this->stderr;
        }

        if ($streams === []) {
            $this->flushPollBuffer($callback);

            return false;
        }
        $read = $streams;
        $write = null;
        $except = null;
        $selected = @stream_select($read, $write, $except, 0, 100000);

        if ($selected === false || $selected === 0) {
            return $this->isRunning();
        }

        foreach ($read as $stream) {
            $chunk = @fread($stream, 8192);

            if ($chunk === false || $chunk === '') {
                continue;
            }

            if ($stream === $this->stdout) {
                $this->recordOutput($chunk);
                $this->pollBuffer .= $chunk;
                $this->dispatchLines($this->pollBuffer, $callback);
            } else {
                $this->recordError($chunk);
            }
        }

        if (!$this->isRunning()) {
            $this->flushPollBuffer($callback);
        }

        return $this->isRunning();
    }

    /**
     * Delivers any buffered output that never ended in a newline, then clears
     * the pending fragment.
     *
     * @param callable $callback Output callback.
     * @return void
     */
    private function flushPollBuffer(callable $callback): void
    {
        if ($this->pollBuffer === '') {
            return;
        }

        $this->dispatchLines($this->pollBuffer, $callback);

        if ($this->pollBuffer !== '') {
            $callback($this->pollBuffer);

            $this->pollBuffer = '';
        }
    }

    /**
     * Runs commands sequentially.
     *
     * Each command receives its own process and all of its output and error
     * streams are drained before the next command starts.
     *
     * @param list<string>|array<int|string, string> $commands Commands to run.
     * @param callable|null $callback Optional callback receiving output lines.
     * @return ProcessResult Resources and captured command output.
     *
     * @throws \InvalidArgumentException When a command is not a non-empty string.
     * @throws \RuntimeException When a command cannot be started.
     */
    public static function run(array $commands, ?callable $callback = null): ProcessResult
    {
        $processes = [];
        $outputs = [];
        $errors = [];

        $callback ??= static function (string $line): void {
        };

        try {
            foreach ($commands as $command) {
                if (!is_string($command) || trim($command) === '') {
                    throw new \InvalidArgumentException('Every process command must be a non-empty string.');
                }

                $process = @proc_open(
                    $command,
                    self::descriptorSpec(),
                    $pipes
                );

                if (!is_resource($process) || !is_array($pipes) || count($pipes) < 3) {
                    throw new \RuntimeException(sprintf('Unable to start process command: %s', $command));
                }

                $resource = new static($process, $pipes[0], $pipes[1], $pipes[2]);
                $resource->wait($callback);
                $processes[] = $resource;
                $outputs[] = $resource->output();
                $error = $resource->error();
                $errors[] = $error === false ? '' : $error;
            }
        } catch (\Throwable $exception) {
            foreach ($processes as $resource) {
                $resource->closeAll();
            }

            throw $exception;
        }

        return new ProcessResult($processes, $outputs, $errors);
    }

    /**
     * Returns the standard three-pipe descriptor specification.
     *
     * @return array<int, array{0: string, 1: string}> Descriptor specification.
     */
    protected static function descriptorSpec(): array
    {
        return [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
    }

    /**
     * Sends complete output lines to a callback.
     *
     * @param string $lineBuffer Pending output bytes, passed by reference.
     * @param callable $callback Output callback.
     * @return void
     */
    private function dispatchLines(string &$lineBuffer, callable $callback): void
    {
        while (($newline = strpos($lineBuffer, "\n")) !== false) {
            $line = substr($lineBuffer, 0, $newline + 1);
            $lineBuffer = substr($lineBuffer, $newline + 1);
            $callback($line);
        }
    }

    /**
     * Removes streams that have reached EOF.
     *
     * @param list<resource> $streams Active streams.
     * @return list<resource> Streams still available.
     */
    private function removeClosedStreams(array $streams): array
    {
        foreach ($streams as $stream) {
            if (feof($stream)) {
                $streams = $this->removeStream($streams, $stream);
            }
        }

        return $streams;
    }

    /**
     * Removes one stream by identity.
     *
     * @param list<resource> $streams Active streams.
     * @param resource $target Stream to remove.
     * @return list<resource> Remaining streams.
     */
    private function removeStream(array $streams, mixed $target): array
    {
        foreach ($streams as $index => $stream) {
            if ($stream === $target) {
                unset($streams[$index]);
            }
        }

        return array_values($streams);
    }

    /**
     * Ensures a constructor argument is a live resource.
     *
     * @param mixed $value Value to validate.
     * @param string $name Argument name.
     * @return void
     *
     * @throws \InvalidArgumentException When the value is not a resource.
     */
    private function assertResource(mixed $value, string $name): void
    {
        if (!is_resource($value)) {
            throw new \InvalidArgumentException(sprintf('The %s handle must be a resource.', $name));
        }
    }
}
