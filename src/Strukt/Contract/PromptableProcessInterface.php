<?php

namespace Strukt\Contract;

/**
 * @author Moderator <pitsolu@gmail.com>
 */
interface PromptableProcessInterface{

	public function write(string $input):int|bool;
}