# Process
[![Build Status](https://travis-ci.org/strukt/process.svg?branch=master)](https://travis-ci.org/strukt/process)


PHP Process Execution

## Installation

```sh
composer require strukt/process v1.0.1
```

## Demo

```php
#!/usr/bin/env php
<?php

use Strukt\Process;

require 'vendor/autoload.php';

// $password = "p@55w0rd";

// $p = Process::run(["ls", "ls -al"]);
// $p = Process::run(["read password ; echo \$password"], function(){
$ps = Process::run(["ping 127.0.0.1"], function($streamOutput){

	echo $streamOutput;
	//wait 5 seconds before continuing
	// sleep(5);
});

// $p = $ps->current();

// $p->write($password);
// $p->closeInput();

// $error = $p->error();
// $output = $p->read();
```
