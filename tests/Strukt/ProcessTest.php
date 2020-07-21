<?php

use Strukt\Process;

class ProcessTest extends PHPUnit\Framework\TestCase{

	public function testProcessSuccess(){

		$p = Process::run("dir");

		$output = $p->read();

		$this->assertNotEmpty($output);
	}

	public function testProcessCallback(){

		$p = Process::run("dir", function(){

			sleep(2);
		});

		$output = $p->read();

		$this->assertNotEmpty($output);
	}

	public function testProcessFail(){

		$p = Process::run("expr 2 / 0");

		$error = $p->error();

		$this->assertEquals($error, "expr: division by zero\n");
	}

	public function isWindows(){

		return strtoupper(substr(PHP_OS, 0, 3)) == "WIN";
	}

	public function testInput(){

		// Stop here and mark this test as incomplete.
		if($this->isWindows())
        	$this->markTestSkipped("Test cannot be run on Windows!");

		$password = "p@55w0rd**9\n";

		$p = Process::run("read password ; echo \$password");

		$p->write($password);
		$p->closeInput();

		$output = $p->read();

		$this->assertEquals($output, $password);
	}
}