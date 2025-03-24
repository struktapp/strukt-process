<?php

namespace Strukt;

use Strukt\Contract\AbstractPromptableProcess;

/**
 * @author Moderator <pitsolu@gmail.com>
 */
class ProcessIn extends AbstractPromptableProcess{

	/**
     * @param resource $process
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct($process, $stdin, $stdout, $stderr){

        $this->process = $process;

        $this->stdin = $stdin;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
	}

    /**
     * @param string $command
     * 
     * @return static 
     */
	public static function run(string $command):static{

		$descrspec = array(

            array('pipe', 'r'),
            array('pipe', 'w'),
            array('pipe', 'w')
        );

		$process = proc_open($command, $descrspec, $pipes);
		$prompt = new self($process, ...$pipes);

		return $prompt;
	}
}