<?php

/**
 * OpenRegister InvalidTransitionInputException
 *
 * Exception thrown when the `data` payload of a lifecycle transition
 * does not satisfy the transition's declared `inputs` contract.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
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
 * Exception thrown when transition input data violates the declared `inputs` allowlist.
 *
 * Raised for keys that are not declared on the transition, and for declared
 * `required` inputs that are absent (or empty-string) from the payload. Maps
 * to HTTP 400 in the TransitionController: the request itself is malformed,
 * as opposed to a transition that is refused (422) or unauthorized (403).
 */
class InvalidTransitionInputException extends Exception {

	/**
	 * The offending field names (unknown keys, or missing required inputs).
	 *
	 * @var array<int, string>
	 */
	private readonly array $fields;

	/**
	 * Constructor for InvalidTransitionInputException
	 *
	 * @param string $message Error message naming the offending field(s)
	 * @param array<int, string> $fields Offending field names
	 * @param int $code Error code
	 * @param Throwable|null $previous Previous exception
	 *
	 * @return void
	 */
	public function __construct(
		string $message = 'Invalid transition input data',
		array $fields = [],
		int $code = 0,
		?Throwable $previous = null,
	) {
		$this->fields = $fields;
		parent::__construct(message: $message, code: $code, previous: $previous);
	}//end __construct()

	/**
	 * Get the offending field names
	 *
	 * @return array<int, string>
	 */
	public function getFields(): array {
		return $this->fields;
	}//end getFields()
}//end class
