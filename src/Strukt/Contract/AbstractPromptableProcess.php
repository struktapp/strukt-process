<?php

namespace Strukt\Contract;

/**
 * @author Moderator <pitsolu@gmail.com>
 */
abstract class AbstractPromptableProcess extends AbstractProcess implements PromptableProcessInterface{

	/**
	 * @param string $input
	 * 
	 * @return int|bool
	 */
	public function write(string $input):int|bool{

		$output = fwrite($this->stdin, $input . PHP_EOL);

		return $output;
	}
}