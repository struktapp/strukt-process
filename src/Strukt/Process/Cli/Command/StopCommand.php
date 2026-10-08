<?php

declare(strict_types=1);

namespace Strukt\Process\Cli\Command;

use Strukt\Console\Runtime\CommandInterface;
use Strukt\ManagedSupervisor;
use Strukt\Process\Cli\Command;

/**
 * @command stop
 * @description Stops a supervised pool; the daemon reaps its workers.
 * @usage stop NAME [options]
 *
 * @argument name
 * @description Name of the supervisor.
 *
 * @option json
 * @flag
 * @description Render the result as JSON.
 *
 * @option dir
 * @description Runtime directory override (defaults to ~/.strukt/supervisor).
 */
class StopCommand extends Command implements CommandInterface
{
    public function execute(array $arguments, array $options): int
    {
        $dir = $options['dir'] ?: null;

        $stopped = ManagedSupervisor::stop($arguments['name'], $dir);

        $this->respond(
            'stop',
            ['name' => $arguments['name'], 'stopped' => $stopped],
            (bool) $options['json'],
        );

        return $stopped ? 0 : 1;
    }
}