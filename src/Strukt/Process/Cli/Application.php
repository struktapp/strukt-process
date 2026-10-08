<?php

declare(strict_types=1);

namespace Strukt\Process\Cli;

use Strukt\Console\Runtime\Application as ConsoleApplication;

/**
 * Command line front end for the process supervisor.
 *
 * Dispatches to the individual commands through the Strukt console runtime.
 * Every command is a {@see CommandInterface} class under the Command directory,
 * described by a PHPDoc block that drives argument and option parsing,
 * `--help`, command listing, and generated documentation.
 */
final class Application
{
    /**
     * @param string|null $commandDirectory Directory with the command classes.
     */
    public function __construct(private ?string $commandDirectory = null)
    {
    }

    /**
     * Runs a single command.
     *
     * @param list<string> $argv Raw argument vector.
     * @return int Process exit code.
     */
    public function run(array $argv): int
    {
        return (new ConsoleApplication($this->commandDirectory ?? __DIR__ . '/Command'))->run($argv);
    }
}