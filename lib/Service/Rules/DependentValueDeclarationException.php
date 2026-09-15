<?php

/**
 * OpenRegister DependentValueDeclarationException
 *
 * A dependent value table the schema save refuses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use Exception;

/**
 * A table the save refuses, carrying the rows that refused it.
 *
 * ADR-005: a declaration that constrains nothing refuses the save and names
 * the property. It never stores a table an author believes is enforcing
 * something.
 *
 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
 */
final class DependentValueDeclarationException extends Exception {

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

		parent::__construct(message: 'Invalid dependent value table: ' . implode(separator: ' ', array: $parts));
	}//end __construct()

	/**
	 * The individual errors, so a client can tell which rule refused.
	 *
	 * @return array<int, array{code: string, message: string}> The blocking validation errors.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()
}//end class
