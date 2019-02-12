# Process
[![Build Status](https://travis-ci.org/strukt/process.svg?branch=master)](https://travis-ci.org/strukt/process)


PHP Process Execution

## Installation

```sh
composer require strukt/process dev-master
```

## Demo

```php
#!/usr/bin/env php
<?php

use Strukt\Process\Process;

require 'vendor/autoload.php';

$password = "p@55w0rd";

//$p = Process::run("ls");
$p = Process::run("read password ; echo \$password", function(){

	//wait 5 seconds before continuing
	sleep(5);
});

$p->write($password);
$p->closeInput();

//$error = $p->error();
$output = $p->read();
```
