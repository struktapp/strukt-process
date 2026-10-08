<?php

declare(strict_types=1);

namespace Strukt\Process\Cli\Command;

use Strukt\Console\Runtime\CommandInterface;
use Strukt\ManagedSupervisor;
use Strukt\Process\Cli\Command;

/**
 * @command kill
 * @description Terminates one worker slot; the daemon restarts it under the policy.
 * @usage kill NAME SLOT [options]
 *
 * @argument name
 * @description Name of the supervisor.
 *
 * @argument slot
 * @type int
 * @description Zero-based worker slot to terminate.
 *
 * @option json
 * @flag
 * @description Render the result as JSON.
 *
 * @option dir
 * @description Runtime directory override (defaults to ~/.strukt/supervisor).
 */
class KillCommand extends Command implements CommandInterface
{
    public function execute(array $arguments, array $options): int
    {
        $slot = (int) $arguments['slot'];
        $killed = ManagedSupervisor::kill($arguments['name'], $slot, $options['dir'] ?: null);

        $this->respond(
            'kill',
            ['name' => $arguments['name'], 'slot' => $slot, 'killed' => $killed],
            (bool) $options['json'],
        );

        return $killed ? 0 : 1;
    }
}