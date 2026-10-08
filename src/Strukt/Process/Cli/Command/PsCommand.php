<?php

declare(strict_types=1);

namespace Strukt\Process\Cli\Command;

use Strukt\Console\Runtime\CommandInterface;
use Strukt\ManagedSupervisor;
use Strukt\Process\Cli\Command;

/**
 * @command ps
 * @description Lists every supervised pool in the runtime directory.
 * @usage ps [options]
 *
 * @option json
 * @flag
 * @description Render the result as JSON.
 *
 * @option dir
 * @description Runtime directory override (defaults to ~/.strukt/supervisor).
 */
class PsCommand extends Command implements CommandInterface
{
    public function execute(array $arguments, array $options): int
    {
        $pools = ManagedSupervisor::ps($options['dir'] ?: null);

        $summary = [];
        foreach ($pools as $name => $state) {
            $summary[$name] = [
                'running' => $state['running'] ?? false,
                'active' => $state['active'] ?? 0,
                'size' => $state['size'] ?? 0,
                'total_restarts' => $state['total_restarts'] ?? 0,
            ];
        }

        $this->respond('ps', ['pool' => $summary], (bool) $options['json']);

        return $summary === [] ? 1 : 0;
    }
}