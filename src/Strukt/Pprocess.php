<?php

namespace Strukt;

use Strukt\Contract\AbstractPromptableProcess as CompatiblePromptableProcess;

/**
 * Manages a process that accepts interactive input.
 */
class Pprocess extends CompatiblePromptableProcess
{
    /**
     * Creates a promptable process from proc_open resources.
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
        $this->stdout = $stdout;
        $this->stderr = $stderr;
    }

    /**
     * Starts an interactive shell command.
     *
     * @param string $command Command passed to proc_open().
     * @return static Promptable process.
     *
     * @throws \InvalidArgumentException When the command is empty.
     * @throws \RuntimeException When the process cannot be started.
     */
    public static function run(string $command): static
    {
        if (trim($command) === '') {
            throw new \InvalidArgumentException('A process command cannot be empty.');
        }

        $process = @proc_open(
            $command,
            self::descriptorSpec(),
            $pipes
        );

        if (!is_resource($process) || !is_array($pipes) || count($pipes) < 3) {
            throw new \RuntimeException('Unable to start the process.');
        }

        return new static($process, $pipes[0], $pipes[1], $pipes[2]);
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
