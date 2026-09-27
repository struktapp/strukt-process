<?php

namespace Strukt;

/**
 * Contains the managed resources and captured output for command batches.
 *
 * @extends \ArrayIterator<int, Process>
 */
final class ProcessResult extends \ArrayIterator
{
    /** @var list<Process> Process resources in command order. */
    private array $processes;

    /** @var list<string> Captured standard output in command order. */
    private array $outputs;

    /** @var list<string> Captured standard error in command order. */
    private array $errors;

    /**
     * Creates a process result collection.
     *
     * @param list<Process> $processes Process resources.
     * @param list<string> $outputs Captured standard output.
     * @param list<string> $errors Captured standard error.
     */
    public function __construct(array $processes, array $outputs, array $errors)
    {
        $this->processes = array_values($processes);
        $this->outputs = array_values($outputs);
        $this->errors = array_values($errors);

        parent::__construct($this->processes);
    }

    /**
     * Returns a process resource by command index.
     *
     * @param int $idx Zero-based command index.
     * @return Process Managed process.
     *
     * @throws \OutOfBoundsException When the index is not present.
     */
    public function resource(int $idx = 0): Process
    {
        if (!array_key_exists($idx, $this->processes)) {
            throw new \OutOfBoundsException(sprintf('No process exists at index %d.', $idx));
        }

        return $this->processes[$idx];
    }

    /**
     * Returns captured standard output by command index.
     *
     * @param int $idx Zero-based command index.
     * @return string|null Captured output, or null when the index is absent.
     */
    public function outputs(int $idx = 0): ?string
    {
        return $this->outputs[$idx] ?? null;
    }

    /**
     * Returns captured standard error by command index.
     *
     * @param int $idx Zero-based command index.
     * @return string|null Captured error, or null when the index is absent.
     */
    public function errors(int $idx = 0): ?string
    {
        return $this->errors[$idx] ?? null;
    }
}
