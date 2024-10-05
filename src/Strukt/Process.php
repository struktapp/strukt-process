<?php

namespace Strukt;

use Strukt\Event;

class Process{

    private $process;
    private $stdin;
    private $stdout;
    private $stderr;
    private $callback;

    private static $switch = false;

    public function __construct($process, $stdin, $stdout, $stderr){

        $this->process = $process;

        $this->stdin = $stdin;
        $this->stdout = $stdout;
        $this->stderr = $stderr;

        if(static::$switch){

            $this->stdout = $stderr;
            $this->stderr = $stdout;
        }
    }

    public static function switchChannels(bool $switch = true){

        static::$switch = $switch;
    }

    public function getStatus(){

        if(!is_resource($this->process))
            throw new \Exception("Process seems not to have been executed yet!");
            
        return proc_get_status($this->process);
    }

    public function write($str){

        if (!$this->stdin)
            throw new \Exception('STDIN has been closed!');

        return fwrite($this->stdin, $str . PHP_EOL);
    }

    public function wait(\Closure $callback){

        $evt = Event::create($callback);

        while($this->isRunning())
            $evt->apply(fgets($this->stdout))->exec();

        $evt->apply(null)->exec();
    }
    
    public function read(){

        if (!$this->stdout)
            throw new \Exception('STDOUT has been closed!');
    
        return stream_get_contents($this->stdout);
    }

    public function readline(){

        if (!$this->stdout)
            throw new \Exception('STDOUT has been closed!');
        
        return fgets($this->stdout);
    }

    public function error(){

        if (!$this->stderr)
            throw new \Exception('STDERR has been closed!');

        return stream_get_contents($this->stderr);
    }

    public static function run(array $commands, \Closure $callback = null){

        $descrspec = array(

            array('pipe', 'r'),
            array('pipe', 'w'),
            array('pipe', 'w')
        );

        $process = proc_open($cmd, $descrspec, $outpipes, null, null);
        $process = new self($process, ...$outpipes);
        $psls[] = $process;
        $process->wait($callback??fn($o)=>$o);

        return new \ArrayIterator($psls);
    }

    public function isRunning(){

        $status = $this->getStatus();

        return $status['running'];
    }

    public function terminate(){

        $isTerminated = proc_terminate($this->process);

        if(!$isTerminated)
            throw new \Exception("Termination failed!");
    }

    public function close(){

        return proc_close($this->process);
    }

    public function closeInput(){

        $isClosed = true;
        if(is_resource($this->stdin))
            $isClosed = fclose($this->stdin);

        return $isClosed;
    }

    public function closeOutput(){

        $isClosed = true;
        if(is_resource($this->stdout))
            $isClosed = fclose($this->stdout);

        return $isClosed;
    }

    public function closeError(){

        $isClosed = true;
        if(is_resource($this->stderr))
            $isClosed = fclose($this->stderr);

        return $isClosed;
    }

    public function closePipes(){

        $this->closeInput();
        $this->closeOutput();
        $this->closeError();
    }

    public function __destruct(){

        $this->closePipes();

        if($this->isRunning())
            $this->terminate();
    }
}