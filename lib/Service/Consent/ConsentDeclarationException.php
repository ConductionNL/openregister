<?php

/**
 * OpenRegister ConsentDeclarationException
 *
 * Raised when a schema's `x-openregister-consent` annotation cannot be
 * accepted: a declaration on a non-array property, or one missing the
 * mandatory `purpose` key. Carries the per-error codes so the controller
 * can answer 422 and name the property that refused the save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Consent
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/consent-evidence-envelope/specs/consent-evidence-envelope/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Consent;

use Exception;

/**
 * A property-level `x-openregister-consent` declaration the schema save refuses.
 *
 * Mirrors {@see \OCA\OpenRegister\Service\Calculation\CalculationDeclarationException}:
 * a declaration that cannot be honoured refuses the save and names the
 * property, rather than storing an annotation that silently never fills
 * evidence.
 */
final class ConsentDeclarationException extends Exception {

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

		parent::__construct(message: 'Invalid x-openregister-consent declaration: ' . implode(separator: ' ', array: $parts));
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
