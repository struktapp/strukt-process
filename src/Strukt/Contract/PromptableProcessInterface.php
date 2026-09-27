<?php

namespace Strukt\Contract;

/**
 * Describes a process that accepts input through standard input.
 */
interface PromptableProcessInterface extends ProcessInterface
{
    /**
     * Writes a line to standard input.
     *
     * @param string $input Input without the line ending to append.
     * @return int Number of bytes written.
     *
     * @throws \RuntimeException When standard input is closed or writing fails.
     */
    public function write(string $input): int;
}
