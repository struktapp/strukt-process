<?php

declare(strict_types=1);

namespace Strukt\Process\Cli\Command;

use Strukt\Console\Runtime\CommandInterface;
use Strukt\ManagedSupervisor;
use Strukt\Process\Cli\Command;

/**
 * @command logs
 * @description Prints the combined worker log of a supervised pool.
 * @usage logs NAME [options]
 *
 * @argument name
 * @description Name of the supervisor.
 *
 * @option lines
 * @type int
 * @default 50
 * @description Last N lines to print.
 *
 * @option dir
 * @description Runtime directory override (defaults to ~/.strukt/supervisor).
 */
class LogsCommand extends Command implements CommandInterface
{
    public function execute(array $arguments, array $options): int
    {
        $log = ManagedSupervisor::tail($arguments['name'], (int) $options['lines'], $options['dir'] ?: null);

        if ($log === '') {
            echo "Strukt Supervisor — logs\n──────────────────────────\n";
            echo "no output yet\n";

            return 1;
        }

        echo $log;

        return 0;
    }
}