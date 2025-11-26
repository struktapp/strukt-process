<?php

namespace Strukt\Contract;

/**
 * @author Moderator <pitsolu@gmail.com>
 */
interface ProcessInterface{

	public function getStatus();
	public function read():string|bool;
	public function terminate():void;
	public function close():int;
	public function closeInput():bool;
	public function closeOutput():bool;
	public function closeError():bool;
	public function closePipes():void;
	public function closeAll():void;
	public function isRunning():int|bool;
	public function __destruct();
}