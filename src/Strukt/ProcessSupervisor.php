<?php

namespace Strukt;

use Closure;

/**
 * Keeps a pool of worker processes alive.
 *
 * ProcessSupervisor composes a {@see ProcessPool} with a restart policy: when a
 * worker exits (crashes or finishes), {@see self::supervise()} replaces it with
 * a fresh copy of the original command. The replacement keeps the same slot
 * index, so callers that track per-slot state keep their numbering across
 * restarts.
 *
 * The policy is deliberately simple:
 *
 * - `restartCap` bounds how many times one slot may be restarted; once the cap
 *   is reached the slot is left dead. A cap of `-1` (default) never gives up.
 * - `backoff` throttles restarts of the same slot: after a restart, at least
 *   `$backoff` seconds must pass before that slot restarts again, breaking
 *   tight crash loops.
 * - {@see self::onExit()} fires once whenever a worker is found dead, whether
 *   or not it will be restarted, so an owner can reclaim anything the dead
 *   worker left in flight. The hook receives the slot index and the exit code.
 *
 * `supervise()` is the only thing a long-running owner needs to call in its
 * loop: it drains worker output first (so the exit hook sees any final lines a
 * worker wrote before dying) and then revives any dead slots.
 */
final class ProcessSupervisor
{
    private ?ProcessPool $pool = null;

    /** @var callable(int, int): void|null Fired before a dead slot is restarted. */
    private $onExit = null;

    /** @var array<int, int> Restart count per slot index. */
    private array $restarts = [];

    /** @var array<int, float> Microtime before which a slot may not restart. */
    private array $reviveAfter = [];

    /** @var array<int, bool> Slots whose exit the owner has already been told about. */
    private array $notified = [];

    /** @var string|list<string>|null The command resurrected workers rerun. */
    private string|array|null $command = null;

    private ?string $cwd = null;

    /** @var array<string, string>|null */
    private ?array $env = null;

    public function __construct(
        private readonly int $size,
        private readonly int $restartCap = -1,
        private readonly float $backoff = 0.0,
    ) {
    }

    /**
     * Registers a hook fired when a worker is found dead, right before it is
     * restarted.
     *
     * @param callable(int, int): void $callback Receives (int $index, int $exitCode).
     * @return void
     */
    public function onExit(callable $callback): void
    {
        $this->onExit = $callback;
    }

    /**
     * Spawns $size concurrent copies of the command and arms the restart policy.
     *
     * @param string|list<string> $command Command passed to proc_open().
     * @param string|null $cwd Optional working directory for the workers.
     * @param array<string, string>|null $env Optional environment overrides.
     * @return void
     */
    public function start(string|array $command, ?string $cwd = null, ?array $env = null): void
    {
        $this->command = $command;
        $this->cwd = $cwd;
        $this->env = $env;

        $this->pool()->start($command, $cwd, $env);
        $this->restarts = array_fill_keys(range(0, $this->size - 1), 0);
    }

    /**
     * Drains worker output, detects dead workers, and restarts them under the
     * restart policy.
     *
     * Call this from the owner's loop. Complete standard-output lines go to the
     * callback with the slot index; standard error is drained and retained on
     * the workers. A worker found dead triggers {@see self::onExit()} exactly
     * once -- whether or not it will be restarted -- so an owner can reclaim
     * anything the dead worker left in flight. The slot is then replaced unless
     * the restart cap is exhausted or the backoff window has not elapsed.
     *
     * @param callable|null $callback Callback receiving (int $index, string $line).
     * @return void
     */
    public function supervise(?callable $callback = null): void
    {
        $this->pool()->drain($callback);

        $now = microtime(true);

        foreach ($this->pool()->processes() as $index => $worker) {
            if ($worker->isRunning()) {
                continue;
            }

            if (!($this->notified[$index] ?? false)) {
                $this->notified[$index] = true;

                if ($this->onExit !== null) {
                    $code = (int) ($worker->status()['exitcode'] ?? 0);
                    ($this->onExit)($index, $code);
                }
            }

            if (($this->reviveAfter[$index] ?? 0.0) > $now) {
                continue;
            }

            if ($this->restartCap >= 0 && ($this->restarts[$index] ?? 0) >= $this->restartCap) {
                continue;
            }

            $this->pool()->restart($index, $this->command, $this->cwd, $this->env);
            $this->notified[$index] = false;
            $this->restarts[$index] = ($this->restarts[$index] ?? 0) + 1;
            $this->reviveAfter[$index] = $now + $this->backoff;
        }
    }

    /**
     * Terminates every worker and reaps its process.
     *
     * @return array<int, int> Exit codes keyed by slot index.
     */
    public function stop(): array
    {
        return $this->pool()->stop();
    }

    /**
     * Whether any worker is still running.
     */
    public function running(): bool
    {
        return $this->pool()->running();
    }

    /**
     * Number of workers still running.
     */
    public function active(): int
    {
        return $this->pool()->active();
    }

    /**
     * The configured pool size.
     */
    public function count(): int
    {
        return $this->pool()->count();
    }

    /**
     * The managed worker processes in slot order.
     *
     * @return list<Process>
     */
    public function processes(): array
    {
        return $this->pool()->processes();
    }

    /**
     * One worker process by slot index.
     */
    public function process(int $index): Process
    {
        return $this->pool()->process($index);
    }

    /**
     * How many times a slot has been restarted so far.
     */
    public function restarts(int $index): int
    {
        return $this->restarts[$index] ?? 0;
    }

    /**
     * All restarts across every slot.
     */
    public function totalRestarts(): int
    {
        return array_sum($this->restarts);
    }

    /**
     * The lazily-built underlying pool.
     */
    private function pool(): ProcessPool
    {
        return $this->pool ??= new ProcessPool($this->size);
    }
}