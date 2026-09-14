<?php

/**
 * OpenRegister PropertyVocabularyException
 *
 * Raised when a schema names a property type, a constraint key or a string
 * format the published vocabulary does not hold. Carries the offending values
 * so the controller can answer 422 and name them, instead of letting a typo
 * become a property that constrains nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
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

namespace OCA\OpenRegister\Service\Schemas;

use Exception;

/**
 * A property declaration the schema save refuses against the vocabulary.
 *
 * It extends Exception rather than replacing it, so every caller that already
 * catches the validator's generic failure keeps working. A caller that wants
 * the 422 catches this one first.
 *
 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
 */
class PropertyVocabularyException extends Exception {

	/**
	 * Build the exception from the offending values.
	 *
	 * @param string $message The sentence naming what was refused.
	 * @param array<int, array{code: string, key: string, path: string, message: string}> $errors The per-value errors.
	 *
	 * @return void
	 */
	public function __construct(string $message, private readonly array $errors = []) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The individual refusals, so a client can tell which value refused.
	 *
	 * @return array<int, array{code: string, key: string, path: string, message: string}> The per-value errors.
	 */
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()
}//end class
