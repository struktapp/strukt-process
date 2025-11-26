<?php

namespace Strukt;

use Strukt\Event;
use Strukt\Contract\AbstractProcess;

/**
 * @author Moderator <pitsolu@gmail.com>
 */
class Process extends AbstractProcess{

    private $callback;
    private $buffer;

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
     * @param callable $callback
     * 
     * @return void
     */
    public function wait(callable $callback):void{

        $evt = Event::create($callback);

        $this->buffer = [];
        while($this->isRunning()){

            $evt->apply($line = fgets($this->stdout))->exec();
            $this->buffer[] = $line;
        }
    }

    /**
     * Output from buffer
     * 
     * @return string
     */
    public function output():string{

        return implode("", array_filter($this->buffer, fn($o)=>!is_bool($o)));
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
     * @param array $commands
     * @param callable $callback
     * 
     * @return \ArrayIterator
     */
    public static function run(array $commands, ?callable $callback = null):\ArrayIterator{

        $descrspec = array(

            array('pipe', 'r'),
            array('pipe', 'w'),
            array('pipe', 'w')
        );

        $buffer = [];
        $processes = [];
        foreach($commands as $idx=>$cmd){
            
            $process = proc_open($cmd, $descrspec, $outpipes, null, null);
            $process = new self($process, ...$outpipes);
            $processes[] = $process;
            $process->wait($callback??function(string $out) use($idx, &$buffer){

                $buffer[$idx][] = $out;
            });
        }

        return new class($processes, $buffer) extends \ArrayIterator{

            private $processes;
            private $buffer;

            /**
             * @param array $processes
             * @param array $buffer
             */
            public function __construct(array $processes, array $buffer){

                parent::__construct($processes);

                $this->processes = $processes;
                $this->buffer = $buffer;
            }

            /**
             * @param int $idx
             * 
             * @return \Strukt\Process
             */
            public function resource(int $idx = 0):\Strukt\Process{

                return $this->processes[$idx];
            }

            /**
             * @param int $idx
             * 
             * @return string|null
             */
            public function outputs(int $idx = 0):string|null{

                return $this->buffer?implode("", $this->buffer[$idx]):null;
            }
        };
    }
}