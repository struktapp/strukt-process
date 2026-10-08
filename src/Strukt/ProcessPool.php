<?php

namespace Strukt;

use RuntimeException;

/**
 * Manages a pool of concurrently running worker processes.
 *
 * The pool spawns a fixed number of copies of one command and supervises them
 * as a group: poll() drains whatever output each worker produced so far
 * (without blocking), and stop() terminates and reaps them all. This fits the
 * classic "spawn N workers, feed a queue, later tear them down" supervisor
 * loop, where the workers live for the whole batch rather than for one command.
 */
final class ProcessPool
{
    /** @var list<Process> Managed workers in spawn order. */
    private array $processes = [];

    /**
     * @param int $size Number of worker processes to keep.
     */
    public function __construct(private readonly int $size)
    {
    }

    /**
     * Spawns the whole pool: $size concurrent copies of the command.
     *
     * Each worker gets its own three pipes, so their output stays separate.
     * Standard input is closed up front: workers in a supervised pool rarely
     * read from the parent, and leaving the pipe open risks a deadlock if a
     * worker inherits it without reading.
     *
     * @param string|list<string> $command Command passed to proc_open();
     *        an array runs the program directly, a string runs through the
     *        shell.
     * @param string|null $cwd Optional working directory for the workers.
     * @param array<string, string>|null $env Optional environment overrides.
     *
     * @throws RuntimeException When a worker cannot be started.
     */
    public function start(string|array $command, ?string $cwd = null, ?array $env = null): void
    {
        if ($this->processes !== []) {
            throw new RuntimeException('The process pool has already been started.');
        }

        for ($i = 0; $i < $this->size; $i++) {
            $this->processes[] = $this->spawn($command, $cwd, $env, $i + 1);
        }
    }

    /**
     * Restarts one worker slot: reaps the current worker (whether it died or is
     * still running) and spawns a fresh copy of the command in its place.
     *
     * Slot identity is preserved, so a supervisor that tracks per-slot state
     * (output, in-flight work) keeps its numbering across restarts.
     *
     * @param int $index Zero-based spawn index to replace.
     * @param string|list<string> $command Command passed to proc_open().
     * @param string|null $cwd Optional working directory for the replacement.
     * @param array<string, string>|null $env Optional environment overrides.
     *
     * @throws \OutOfBoundsException When the index is not present.
     * @throws RuntimeException When the replacement cannot be started.
     */
    public function restart(int $index, string|array $command, ?string $cwd = null, ?array $env = null): void
    {
        if (!array_key_exists($index, $this->processes)) {
            throw new \OutOfBoundsException(sprintf('No worker process exists at index %d.', $index));
        }

        $this->processes[$index]->closeAll();

        $this->processes[$index] = $this->spawn($command, $cwd, $env, $index + 1);
    }

    /**
     * Spawns one worker process with its own three-pipe set.
     *
     * @param string|list<string> $command Command passed to proc_open().
     * @param string|null $cwd Working directory, or null for the parent's.
     * @param array<string, string>|null $env Environment overrides.
     * @param int $position One-based position, only used in the error message.
     *
     * @throws RuntimeException When the worker cannot be started.
     */
    private function spawn(string|array $command, ?string $cwd = null, ?array $env = null, int $position = 1): Process
    {
        $process = @proc_open(
            self::shellSafe($command),
            self::descriptorSpec(),
            $pipes,
            $cwd,
            $env,
        );

        if (!is_resource($process) || !is_array($pipes) || count($pipes) < 3) {
            throw new RuntimeException(sprintf('Unable to start worker process %d.', $position));
        }

        $worker = new Process($process, $pipes[0], $pipes[1], $pipes[2]);
        $worker->closeInput();

        return $worker;
    }

    /**
     * Ensures a string command does not hide the real process behind a shell.
     *
     * proc_open() runs a string command through /bin/sh, which forks a child
     * for the program even for trivial commands. The pool then only knows the
     * shell's pid, and terminating it orphans the actual worker. Prefixing a
     * simple command with exec makes the shell replace itself, so the tracked
     * pid is the real worker and terminate() reaches it. Commands containing
     * shell metacharacters (pipelines, redirection, quoting, substitution)
     * are left untouched: those genuinely need the shell.
     *
     * @param string|list<string> $command Command passed to proc_open().
     * @return string|list<string> Command safe to hand to proc_open().
     */
    private static function shellSafe(string|array $command): string|array
    {
        if (is_array($command)) {
            return $command;
        }

        $command = trim($command);
        if (preg_match('/[\|;&<>\(\)`$"\'\\\r\n]/', $command) === 1) {
            return $command;
        }

        return 'exec ' . $command;
    }

    /**
     * Relays the output every worker has produced since the last poll.
     *
     * This never blocks on a worker: each worker is read up to whatever is
     * available right now. Complete standard-output lines are sent to the
     * callback with the spawn index; standard error is drained and retained on
     * the workers (see Process::error()) but not dispatched.
     *
     * @param callable|null $callback Callback receiving (int $index, string $line).
     * @return void
     */
    public function drain(?callable $callback = null): void
    {
        foreach ($this->processes as $index => $worker) {
            if ($callback === null) {
                $worker->poll();
                continue;
            }

            $worker->poll(static function (string $line) use ($callback, $index): void {
                $callback($index, $line);
            });
        }
    }

    /**
     * Terminates every worker and reaps its process.
     *
     * @return array<int, int> Exit codes keyed by spawn index.
     */
    public function stop(): array
    {
        $codes = [];

        foreach ($this->processes as $index => $worker) {
            $worker->terminate();
            $codes[$index] = $worker->close();
        }

        $this->processes = [];

        return $codes;
    }

    /**
     * Whether any worker is still running.
     */
    public function running(): bool
    {
        return $this->active() > 0;
    }

    /**
     * Number of workers still running.
     */
    public function active(): int
    {
        $active = 0;

        foreach ($this->processes as $worker) {
            if ($worker->isRunning()) {
                $active++;
            }
        }

        return $active;
    }

    /**
     * The configured pool size.
     */
    public function count(): int
    {
        return $this->size;
    }

    /**
     * Returns the managed worker processes in spawn order.
     *
     * @return list<Process>
     */
    public function processes(): array
    {
        return $this->processes;
    }

    /**
     * Returns one worker process by spawn index.
     *
     * @param int $index Zero-based spawn index.
     * @return Process Managed worker.
     *
     * @throws \OutOfBoundsException When the index is not present.
     */
    public function process(int $index): Process
    {
        if (!array_key_exists($index, $this->processes)) {
            throw new \OutOfBoundsException(sprintf('No worker process exists at index %d.', $index));
        }

        return $this->processes[$index];
    }

    /**
     * Returns the standard three-pipe descriptor specification.
     *
     * @return array<int, array{0: string, 1: string}> Descriptor specification.
     */
    private static function descriptorSpec(): array
    {
        return [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
    }

    /**
     * Terminates and reaps all workers without letting cleanup errors escape.
     *
     * @return void
     */
    public function __destruct()
    {
        foreach ($this->processes as $worker) {
            try {
                $worker->closeAll();
            } catch (\Throwable) {
                // Destructors must not interrupt shutdown.
            }
        }
    }
}