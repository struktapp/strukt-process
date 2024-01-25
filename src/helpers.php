<?php

helper("ps");

if(helper_add("switchChannels")){

	function switchChannels(bool $switch = true){

		Strukt\Process::switchChannels($switch);
	}
}

if(helper_add("process")){

	function process(string|array $commands, \Closure $callback = null){

		$command_ls = [];
		if(is_array($commands))
			$command_ls = $commands;
		
		if(is_string($commands))
			$command_ls[] = $commands;

		return Strukt\Process::run($command_ls, $callback);
	}
}