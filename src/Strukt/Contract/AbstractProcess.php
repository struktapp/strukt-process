<?php

namespace Strukt\Contract;

/**
 * @author Moderator <pitsolu@gmail.com>
 */
abstract class AbstractProcess implements ProcessInterface{

	protected $process;
    protected $stdin;
    protected $stdout;
    protected $stderr;

	/**
     * @return array
     */
    public function getStatus():array{

        if(!is_resource($this->process))
            throw new \Exception("Process seems not to have been executed yet!");
            
        return proc_get_status($this->process);
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
     * @return string|bool
     */
    public function read():string|bool{

        if (!$this->stdout)
            throw new \Exception('STDOUT has been closed!');
    
        return stream_get_contents($this->stdout);
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

    /**
     * @return void
     */
    public function closeAll():void{

    	$this->__destruct();
    }

    /**
     * @return int|bool
     */
    public function isRunning():int|bool{

        $status = $this->getStatus();

        return $status['running'];
    }

    public function __destruct(){

        $this->closePipes();
        if($this->isRunning())
            $this->terminate();
    }
}