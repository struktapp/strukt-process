<?php

namespace Strukt;

use Strukt\Contract\PromptableProcessInterface;

/**
 * Adds standard-input writing to the common process implementation.
 */
abstract class AbstractPromptableProcess extends AbstractProcess implements PromptableProcessInterface
{
    /**
     * Writes input followed by the platform line ending.
     *
     * @param string $input Input without the line ending to append.
     * @return int Number of bytes written.
     *
     * @throws \RuntimeException When standard input is closed or writing fails.
     */
    public function write(string $input): int
    {
        if (!is_resource($this->stdin)) {
            throw new \RuntimeException('The process input pipe is closed.');
        }

        $written = @fwrite($this->stdin, $input . PHP_EOL);
        if ($written === false) {
            throw new \RuntimeException('Unable to write to the process input pipe.');
        }

        return $written;
    }
}
