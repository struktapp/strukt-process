<?php

if(!function_exists("process")){

	function switchChannels(bool $switch = true){

		Strukt\Process::switchChannels($switch);
	}

	function process(string|array $commands, \Closure $callback = null){

		$command_ls = [];
		if(is_array($commands))
			$command_ls = $commands;
		
		if(is_string($commands))
			$command_ls[] = $commands;

		return Strukt\Process::run($command_ls, $callback);
	}
}