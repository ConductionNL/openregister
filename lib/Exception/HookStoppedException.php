<?php

/**
 * OpenRegister HookStoppedException
 *
 * Exception thrown when a schema hook stops event propagation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Exception;

use Exception;
use Throwable;

/**
 * Exception thrown when a schema hook stops event propagation
 *
 * Contains the validation errors returned by the hook that rejected the operation.
 */
class HookStoppedException extends Exception {

	/**
	 * Constructor for HookStoppedException
	 *
	 * @param string               $message  Error message
	 * @param array<string, mixed> $errors   Hook validation errors
	 * @param int                  $code     The exception code
	 * @param Throwable|null       $previous The exception that caused this one
	 *
	 * @return void
	 */
	public function __construct(
		string $message='Operation blocked by schema hook',
		private readonly array $errors=[],
		int $code=0,
		?Throwable $previous=null,
	) {
		parent::__construct(message: $message, code: $code, previous: $previous);
	}//end __construct()

	/**
	 * Get the hook validation errors
	 *
	 * @return array<string, mixed>
	 */
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()

	/**
	 * The refusal a cascade answers when a guard stops the delete of a dependent object.
	 *
	 * The caller addressed the root, not the dependent, so the answer keeps the
	 * guard's code and status and names neither the dependent nor who holds it.
	 *
	 * @param self $refusal The dependent's refusal.
	 *
	 * @return self The refusal for the root.
	 */
	public static function forDependent(self $refusal): self {
		$message = 'Cannot delete object: an object that depends on it cannot be deleted now.';
		$errors  = ['message' => $message];
		foreach (['code', 'status'] as $key) {
			if (array_key_exists($key, $refusal->getErrors()) === true) {
				$errors[$key] = $refusal->getErrors()[$key];
			}
		}

		return new self(message: $message, errors: $errors);
	}//end forDependent()
}//end class
