<?php

helper("ps");

if(helper_add("switchChannels")){

	/**
	 * @param boolean $switch
	 */
	function switchChannels(bool $switch = true):void{

		Strukt\Process::switchChannels($switch);
	}
}

if(helper_add("process")){

	/**
	 * @param string|array $commands
	 * @param \Closure $callback
	 * 
	 * @return \ArrayIterator
	 */
	function process(string|array $commands, \Closure $callback = null):\ArrayIterator{

		$command_ls = [];
		if(is_array($commands))
			$command_ls = $commands;
		
		if(is_string($commands))
			$command_ls[] = $commands;

		return Strukt\Process::run($command_ls, $callback);
	}
}