<?php

use Strukt\Process;
use Strukt\Pprocess;
use Strukt\Contract\PromptableProcessInterface;

helper("ps");

if(helper_add("switchChannels")){

	/**
	 * @param boolean $switch
	 * 
	 * @return void
	 */
	function switchChannels(bool $switch = true):void{

		Process::switchChannels($switch);
	}
}

if(helper_add("process")){

	/**
	 * @param string|array $commands
	 * @param callable $callback
	 * 
	 * @return \ArrayIterator
	 */
	function process(string|array $commands, ?callable $callback = null):\ArrayIterator{

		$command_ls = [];
		if(is_array($commands))
			$command_ls = $commands;
		
		if(is_string($commands))
			$command_ls[] = $commands;

		return Process::run($command_ls, $callback);
	}
}

if(helper_add("pprocess")){

	/**
	 * @param string $command
	 * 
	 * @return \Strukt\Contract\PromptableProcessInterface
	 */
	function pprocess(string $command):PromptableProcessInterface{

		return Pprocess::run($command);
	}
}