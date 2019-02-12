<?php

namespace Strukt\Process;

use Strukt\Event\Event;

class Process{

    private $process;
    private $stdin;
    private $stdout;
    private $stderr;
    private $callback;

    public function __construct($process, $stdin, $stdout, $stderr){

        $this->process = $process;

        $this->stdin = $stdin;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
    }

    public function getStatus(){

        if(!is_resource($this->process))
            throw new \Exception("Process seems not to have been executed yet!");
            
        return proc_get_status($this->process);
    }

    public function write($str){

        if (!$this->stdin)
            throw new \Exception('STDIN has been closed!');

        return fwrite($this->stdin, $str);
    }

    public function wait(\Closure $callback){

        $evt = Event::newEvent($callback);

        while($this->isRunning())
            $evt->exec();
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

    public static function run($command, \Closure $callback = null){

        $descrspec = array(

            array('pipe', 'r'),
            array('pipe', 'w'),
            array('pipe', 'w')
        );

        $process = proc_open($command, $descrspec, $outpipes, null, null);

        $newProcess = new self($process, ...$outpipes);

        if(!is_null($callback))
            $newProcess->wait($callback);

        return $newProcess;
    }

    public function isRunning(){

        $status = $this->getStatus();

        return $status['running'];
    }

    public function terminate($signal = SIGTERM){

        $isTerminated = proc_terminate($this->process, $signal);

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