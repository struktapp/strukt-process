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

    /**
     * @param resource $process
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     */
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

    /**
     * @param boolean $switch
     */
    public static function switchChannels(bool $switch = true):void{

        static::$switch = $switch;
    }

    /**
     * @return array
     */
    public function getStatus():array{

        if(!is_resource($this->process))
            throw new \Exception("Process seems not to have been executed yet!");
            
        return proc_get_status($this->process);
    }

    /**
     * @param string $str
     * 
     * @return integer|bool
     */
    public function write(string $str):int|bool{

        if (!$this->stdin)
            throw new \Exception('STDIN has been closed!');

        return fwrite($this->stdin, $str . PHP_EOL);
    }

    /**
     * @param \Closure $callback
     * 
     * @return void
     */
    public function wait(\Closure $callback):void{

        $evt = Event::create($callback);

        while($this->isRunning())
            $evt->apply(fgets($this->stdout))->exec();

        $evt->apply(null)->exec();
    }
    
    /**
     * @return string|bool
     */
    public function read():string|bool{

        if (!$this->stdout)
            throw new \Exception('STDOUT has been closed!');
    
        return stream_get_contents($this->stdout);
    }

    /**
     * @return string|bool
     */
    public function readline():string|bool{

        if (!$this->stdout)
            throw new \Exception('STDOUT has been closed!');
        
        return fgets($this->stdout);
    }

    /**
     * @return string|bool
     */
    public function error():string|bool{

        if (!$this->stderr)
            throw new \Exception('STDERR has been closed!');

        return stream_get_contents($this->stderr);
    }

    /**
     * @param array $commands
     * @param \Closure $callback
     */
    public static function run(array $commands, ?\Closure $callback = null):\ArrayIterator{

        $descrspec = array(

            array('pipe', 'r'),
            array('pipe', 'w'),
            array('pipe', 'w')
        );

        foreach($commands as $cmd){
            
            $process = proc_open($cmd, $descrspec, $outpipes, null, null);
            $process = new self($process, ...$outpipes);
            $psls[] = $process;
            $process->wait($callback??fn($o)=>$o);
        }

        return new \ArrayIterator($psls);
    }

    /**
     * @return int|bool
     */
    public function isRunning():int|bool{

        $status = $this->getStatus();

        return $status['running'];
    }

    /**
     * @return void
     */
    public function terminate():void{

        $isTerminated = proc_terminate($this->process);

        if(!$isTerminated)
            throw new \Exception("Termination failed!");
    }

    /**
     * @return integer
     */
    public function close():int{

        return proc_close($this->process);
    }

    /**
     * @return boolean
     */
    public function closeInput():bool{

        $isClosed = true;
        if(is_resource($this->stdin))
            $isClosed = fclose($this->stdin);

        return $isClosed;
    }

    /**
     * @return boolean
     */
    public function closeOutput():bool{

        $isClosed = true;
        if(is_resource($this->stdout))
            $isClosed = fclose($this->stdout);

        return $isClosed;
    }

    /**
     * @return boolean
     */
    public function closeError():bool{

        $isClosed = true;
        if(is_resource($this->stderr))
            $isClosed = fclose($this->stderr);

        return $isClosed;
    }

    /**
     * @return void
     */
    public function closePipes():void{

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