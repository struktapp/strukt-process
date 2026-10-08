<?php

declare(strict_types=1);

namespace Strukt\Process\Cli\Command;

use Strukt\Console\Runtime\CommandInterface;
use Strukt\ManagedSupervisor;
use Strukt\Process\Cli\Command;

/**
 * @command status
 * @description Reports the live state of one supervised pool.
 * @usage status NAME [options]
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
class StatusCommand extends Command implements CommandInterface
{
    public function execute(array $arguments, array $options): int
    {
        $state = ManagedSupervisor::status($arguments['name'], $options['dir'] ?: null);

        if ($state === null) {
            $this->respond(
                'status',
                ['name' => $arguments['name'], 'running' => false],
                (bool) $options['json'],
            );

            return 1;
        }

        $this->respond('status', $state, (bool) $options['json']);

        return ($state['running'] ?? false) ? 0 : 1;
    }
}