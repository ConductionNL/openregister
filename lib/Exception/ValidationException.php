<?php

namespace OCA\OpenRegister\Exception;

use Exception;
use Opis\JsonSchema\Errors\ValidationError;
use Throwable;

class ValidationException extends Exception
{
	public function __construct(
		string $message,
		int $code = 0,
		?Throwable $previous = null,
		private ?ValidationError $errors = null)
	{
		parent::__construct($message, $code, $previous);
	}

	public function getErrors(): ValidationError
	{
		return $this->errors;
	}

}
