<?php

use Strukt\Process;
use Strukt\Pprocess;

function isWindows(){

	return strtoupper(substr(PHP_OS, 0, 3)) == "WIN";
};

test("process[success]", function(){

	$ps = Process::run(["dir"]);

	$output = $ps->outputs();

	expect($output)->not->toBeEmpty();
});

test("process[callback]", function(){

	$ps = Process::run(["dir"], function(){

		sleep(2);
	});

	$output = $ps->current()->output();

	expect($output)->not->toBeEmpty();
});

test("process[fail]", function(){

	$ps = Process::run(["expr 2 / 0"]);

	$error = $ps->resource()->error();

	expect($error)->toBe("expr: division by zero\n");
});

test("process[prompt]", function(){

	$password = "p@55w0rd**9\n";

	$ps = Pprocess::run("read password ; echo \$password");
	$ps->write($password);
	$ps->closeInput();

	$output = $ps->read();

	expect($output)->toBe($password);

});//->skip("Test cannot be run on Windows!");
