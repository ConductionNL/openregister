<?php

/**
 * OpenRegister CalculationDeclarationException
 *
 * Raised when a calculation forwarded by a property form cannot be accepted:
 * an operator the catalogue does not hold, a property nothing declares, or a
 * cycle between two computed properties. Carries the per-error codes so the
 * controller can answer 422 and name the node that refused the save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

use Exception;

/**
 * A property-level calculation declaration the schema save refuses.
 *
 * ADR-005: a declaration that cannot be evaluated refuses the save and names
 * the property. It never stores an annotation that will silently write nothing.
 */
final class CalculationDeclarationException extends Exception {

	/**
	 * Build the exception from the validator's error rows.
	 *
	 * @param array<int, array{code: string, message: string}> $errors The blocking validation errors.
	 *
	 * @return void
	 */
	public function __construct(private readonly array $errors) {
		$parts = array_map(
			static fn (array $error): string => '[' . $error['code'] . '] ' . $error['message'],
			$errors
		);

		parent::__construct('Invalid calculation declaration: ' . implode(' ', $parts));
	}//end __construct()

	/**
	 * The individual errors, so a client can tell which rule refused.
	 *
	 * @return array<int, array{code: string, message: string}> The blocking validation errors.
	 */
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()
}//end class
