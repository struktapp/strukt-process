<?php

declare(strict_types=1);

namespace Strukt;

use RuntimeException;

/**
 * A long-running, observable worker supervisor.
 *
 * ManagedSupervisor composes a {@see ProcessSupervisor} and runs it as a
 * background daemon (pcntl_fork + posix_setsid) that survives the terminal
 * which launched it. Everything worth observing is persisted to a runtime
 * directory, so other processes can inspect and manage the pool without
 * joining its process:
 *
 * - `<dir>/<name>/supervise.pid`  pid of the daemon itself
 * - `<dir>/<name>/state.json`     snapshot of the pool (workers, pids, restarts)
 * - `<dir>/<name>/output.log`     combined worker output, prefixed per slot
 *
 * The runtime directory defaults to `~/.strukt/supervisor`, overridable with
 * the `STRUKT_SUPERVISOR_DIR` environment variable or the `$dir` argument.
 *
 * Start a supervisor with {@see self::start()}: the caller forks, the parent
 * waits for the pid file and returns the daemon pid, and the child detaches,
 * supervises forever, and writes its state every cycle. {self::onExit()} is
 * forwarded from the inner {@see ProcessSupervisor}, so the daemon also appends
 * a line to the combined log whenever a worker dies.
 *
 * The inspection surface is entirely static on purpose: `status()`, `ps()`,
 * `tail()`, `kill()` and `stop()` read the pid and state files and never need
 * to run inside the daemon, which keeps the rest of the system free of the
 * supervision loop.
 */
final class ManagedSupervisor
{
    private ?ProcessSupervisor $supervisor = null;

    /** @var string|list<string> Command resurrected workers rerun. */
    private string|array $command = [];

    private ?string $cwd = null;

    /** @var array<string, string>|null */
    private ?array $env = null;

    private bool $stopping = false;

    private float $startedAt = 0.0;

    /**
     * @param string $name Supervisor name (also its file and CLI handle).
     * @param int $size Number of worker processes to keep.
     * @param int $restartCap Maximum restarts per slot before giving up; -1 for unlimited.
     * @param float $backoff Minimum seconds between restarts of the same slot.
     * @param int $interval Milliseconds between supervision passes and state writes.
     * @param string|null $dir Runtime directory override.
     *
     * @throws RuntimeException When the name is unsafe for a file path.
     */
    public function __construct(
        private readonly string $name,
        private readonly int $size,
        private readonly int $restartCap = -1,
        private readonly float $backoff = 0.0,
        private readonly int $interval = 200,
        private readonly ?string $dir = null,
    ) {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', (string) $name)) {
            throw new RuntimeException(sprintf("Unsafe supervisor name '%s'. Use letters, digits, '.', '_' or '-'.", $name));
        }

        if ($this->size < 1) {
            throw new RuntimeException('A managed supervisor needs at least one worker.');
        }
    }

    /**
     * Forks a supervising daemon for the given command and returns its pid.
     *
     * The parent waits (up to a few seconds) for the pid file to appear so a
     * caller can rely on the supervisor being alive right after this returns.
     * The child detaches from the terminal, redirects its own standard streams
     * to `/dev/null`, registers SIGTERM/SIGINT handlers for a graceful stop,
     * then supervises forever.
     *
     * @param string|list<string> $command Command passed to proc_open().
     * @param string|null $cwd Optional working directory for the workers.
     * @param array<string, string>|null $env Optional environment overrides.
     * @return int Pid of the daemon.
     *
     * @throws RuntimeException When pcntl is unavailable or the fork fails.
     */
    public function start(string|array $command, ?string $cwd = null, ?array $env = null): int
    {
        if (!function_exists('pcntl_fork')) {
            throw new RuntimeException('Managed supervision requires the pcntl extension.');
        }

        if (self::running($this->name, $this->dir)) {
            throw new RuntimeException(sprintf("Supervisor '%s' is already running.", $this->name));
        }

        $this->command = $command;
        $this->cwd = $cwd;
        $this->env = $env;

        $pid = @pcntl_fork();
        if ($pid === -1 || $pid === false) {
            throw new RuntimeException(sprintf("Unable to fork supervisor '%s'.", $this->name));
        }

        if ($pid > 0) {
            $deadline = microtime(true) + 5.0;
            while (!is_file(self::pidFile($this->name, $this->dir)) && microtime(true) < $deadline) {
                usleep(50_000);
            }

            return $pid;
        }

        $this->daemonize();

        return 0;
    }

    /**
     * Sends SIGTERM to the daemon, which stops its workers gracefully and
     * removes its pid file.
     */
    public static function stop(string $name, ?string $dir = null): bool
    {
        if (!function_exists('posix_kill')) {
            return false;
        }

        $pid = self::pid($name, $dir);

        return $pid !== null && @posix_kill($pid, SIGTERM);
    }

    /**
     * Sends SIGTERM to one worker slot; the daemon respawns it under the
     * restart policy.
     */
    public static function kill(string $name, int $slot, ?string $dir = null): bool
    {
        if (!function_exists('posix_kill')) {
            return false;
        }

        $state = self::status($name, $dir);
        $pid = $state['workers'][$slot]['pid'] ?? null;

        return is_int($pid) && $pid > 0 && @posix_kill($pid, SIGTERM);
    }

    /**
     * Whether a supervisor with this name is currently running.
     */
    public static function running(string $name, ?string $dir = null): bool
    {
        if (!function_exists('posix_kill')) {
            return false;
        }

        return self::pid($name, $dir) !== null;
    }

    /**
     * The pid of a running supervisor, or null when it is not running / absent.
     */
    public static function pid(string $name, ?string $dir = null): ?int
    {
        $file = self::pidFile($name, $dir);
        if (!is_file($file)) {
            return null;
        }

        $pid = (int) (trim((string) file_get_contents($file)) ?: 0);

        if ($pid < 1 || !@posix_kill($pid, 0)) {
            return null;
        }

        return $pid;
    }

    /**
     * The persisted state snapshot of a supervisor, or null when it has no
     * state file yet. `running` is recomputed from the pid file.
     *
     * @return array<string, mixed>|null
     */
    public static function status(string $name, ?string $dir = null): ?array
    {
        $file = self::stateFile($name, $dir);
        if (!is_file($file)) {
            return null;
        }

        $state = json_decode((string) file_get_contents($file), true);
        if (!is_array($state)) {
            return null;
        }

        $state['running'] = self::running($name, $dir);

        return $state;
    }

    /**
     * Every supervised pool in the runtime directory, keyed by name.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function ps(?string $dir = null): array
    {
        $base = rtrim($dir ?? self::defaultDir(), '/');
        $all = [];

        if (!is_dir($base)) {
            return $all;
        }

        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            $name = basename($sub);
            $state = self::status($name, $base);
            if ($state === null) {
                continue;
            }
            $all[$name] = $state;
        }

        ksort($all);

        return $all;
    }

    /**
     * The combined worker log, tailed to the last N lines.
     */
    public static function tail(string $name, int $lines = 50, ?string $dir = null): string
    {
        $file = self::logFile($name, $dir);
        if (!is_file($file)) {
            return '';
        }

        $content = file($file, FILE_IGNORE_NEW_LINES) ?: [];

        return implode(PHP_EOL, array_slice($content, -$lines)) . PHP_EOL;
    }

    /**
     * The default runtime directory.
     */
    public static function defaultDir(): string
    {
        $env = getenv('STRUKT_SUPERVISOR_DIR');

        return $env !== false && $env !== ''
            ? rtrim($env, '/')
            : rtrim((getenv('HOME') ?: sys_get_temp_dir()), '/') . '/.strukt/supervisor';
    }

    /**
     * The pid file path for a supervisor.
     */
    public static function pidFile(string $name, ?string $dir = null): string
    {
        return self::dirFor($name, $dir) . '/supervise.pid';
    }

    /**
     * The state file path for a supervisor.
     */
    public static function stateFile(string $name, ?string $dir = null): string
    {
        return self::dirFor($name, $dir) . '/state.json';
    }

    /**
     * The combined log file path for a supervisor.
     */
    public static function logFile(string $name, ?string $dir = null): string
    {
        return self::dirFor($name, $dir) . '/output.log';
    }

    /**
     * The runtime directory for one supervisor.
     */
    private static function dirFor(string $name, ?string $dir = null): string
    {
        return rtrim($dir ?? self::defaultDir(), '/') . '/' . $name;
    }

    /**
     * Runs the daemon loop in the forked child.
     */
    private function daemonize(): void
    {
        $dir = self::dirFor($this->name, $this->dir);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            fwrite(STDERR, sprintf("Unable to create runtime directory '%s'.\n", $dir));
            exit(1);
        }

        if (function_exists('posix_setsid')) {
            @posix_setsid();
        }

        $this->detachStdio();
        $this->registerSignalHandlers();

        file_put_contents(self::pidFile($this->name, $this->dir), (string) getmypid());

        $this->supervisor = new ProcessSupervisor($this->size, $this->restartCap, $this->backoff);
        $this->supervisor->onExit(function (int $index, int $code): void {
            $this->writeLog(sprintf('[supervisor] worker %d exited with code %d', $index, $code));
        });

        $this->startedAt = microtime(true);
        $this->supervisor->start($this->command, $this->cwd, $this->env);
        $this->writeLog('[supervisor] started');

        while (!$this->stopping) {
            $this->supervisor->supervise(function (int $slot, string $line): void {
                $this->writeLog(sprintf('[slot %d] %s', $slot, $line));
            });

            $this->persist();

            usleep(max(10, $this->interval) * 1000);
        }

        $this->writeLog('[supervisor] stopped');
        $this->supervisor->stop();
        @unlink(self::pidFile($this->name, $this->dir));
        $this->persist();

        exit(0);
    }

    /**
     * Closes the inherited terminal streams and reopens them on /dev/null so a
     * detached daemon never holds the launching terminal open.
     */
    private function detachStdio(): void
    {
        if (defined('STDIN')) {
            @fclose(STDIN);
        }
        if (defined('STDOUT')) {
            @fclose(STDOUT);
        }
        if (defined('STDERR')) {
            @fclose(STDERR);
        }

        @fopen('/dev/null', 'r');
        @fopen('/dev/null', 'w');
        @fopen('/dev/null', 'a');
    }

    /**
     * Installs graceful stop handlers for SIGTERM and SIGINT.
     */
    private function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->stopping = true);
        pcntl_signal(SIGINT, fn () => $this->stopping = true);
    }

    /**
     * Writes the current pool snapshot to state.json.
     */
    private function persist(): void
    {
        $workers = [];

        foreach ($this->supervisor->processes() as $slot => $worker) {
            $pid = null;
            $exitCode = null;

            try {
                $status = $worker->status();
                $pid = isset($status['pid']) ? (int) $status['pid'] : null;
                if (!($status['running'] ?? false)) {
                    $exitCode = isset($status['exitcode']) ? (int) $status['exitcode'] : null;
                }
            } catch (\Throwable) {
                // The worker was reaped between the check and the read.
            }

            $workers[] = [
                'slot' => $slot,
                'pid' => $pid,
                'running' => $worker->isRunning(),
                'restarts' => $this->supervisor->restarts($slot),
                'exit_code' => $exitCode,
            ];
        }

        $state = [
            'name' => $this->name,
            'pid' => getmypid(),
            'command' => is_array($this->command) ? implode(' ', $this->command) : $this->command,
            'size' => $this->size,
            'restart_cap' => $this->restartCap,
            'backoff' => $this->backoff,
            'interval_ms' => $this->interval,
            'started_at' => $this->startedAt,
            'running' => !$this->stopping,
            'active' => $this->supervisor->active(),
            'total_restarts' => $this->supervisor->totalRestarts(),
            'log' => self::logFile($this->name, $this->dir),
            'workers' => $workers,
        ];

        $temp = self::stateFile($this->name, $this->dir) . '.tmp';
        file_put_contents($temp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        rename($temp, self::stateFile($this->name, $this->dir));
    }

    /**
     * Appends one line to the combined log.
     */
    private function writeLog(string $line): void
    {
        file_put_contents(self::logFile($this->name, $this->dir), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}