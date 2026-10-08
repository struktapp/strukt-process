<?php

declare(strict_types=1);

namespace Strukt\Process\Cli\Command;

use Strukt\Console\Runtime\CommandInterface;
use Strukt\ManagedSupervisor;
use Strukt\Process\Cli\Command;

/**
 * @command start
 * @description Starts a supervised pool of worker processes as a background daemon.
 * @usage start NAME COMMAND [options]
 *
 * @argument name
 * @description Name used to identify the supervisor in later commands.
 *
 * @argument command
 * @description Command to run; quote it when it contains spaces.
 *
 * @option json
 * @flag
 * @description Render the result as JSON.
 *
 * @option size
 * @type int
 * @default 1
 * @description Number of worker processes to keep.
 *
 * @option restart-cap
 * @type int
 * @default -1
 * @description Max restarts per slot before giving up; -1 for unlimited.
 *
 * @option backoff
 * @type float
 * @default 0
 * @description Minimum seconds between restarts of the same slot.
 *
 * @option interval
 * @type int
 * @default 200
 * @description Milliseconds between supervision passes.
 *
 * @option cwd
 * @description Working directory for the workers.
 *
 * @option dir
 * @description Runtime directory override (defaults to ~/.strukt/supervisor).
 */
class StartCommand extends Command implements CommandInterface
{
    public function execute(array $arguments, array $options): int
    {
        $supervisor = new ManagedSupervisor(
            $arguments['name'],
            (int) $options['size'],
            (int) $options['restart-cap'],
            (float) $options['backoff'],
            (int) $options['interval'],
            $options['dir'] ?: null,
        );

        $pid = $supervisor->start($arguments['command'], $options['cwd'] ?: null);

        $this->respond(
            'start',
            ['name' => $arguments['name'], 'pid' => $pid, 'command' => $arguments['command']],
            (bool) $options['json'],
        );

        return 0;
    }
}