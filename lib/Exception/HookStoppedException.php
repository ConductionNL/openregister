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

/**
 * Exception thrown when a schema hook stops event propagation
 *
 * Contains the validation errors returned by the hook that rejected the operation.
 */
class HookStoppedException extends Exception {

	/**
	 * Constructor for HookStoppedException
	 *
	 * The exception code is always 0.
	 *
	 * @param string               $message Error message
	 * @param array<string, mixed> $errors Hook validation errors
	 *
	 * @return void
	 */
	public function __construct(
		string $message='Operation blocked by schema hook',
		private readonly array $errors=[],
	) {
		parent::__construct(message: $message, code: 0);
	}//end __construct()

	/**
	 * Get the hook validation errors
	 *
	 * @return array<string, mixed>
	 */
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()
}//end class
