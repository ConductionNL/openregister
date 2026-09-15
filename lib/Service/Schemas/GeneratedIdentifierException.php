<?php

/**
 * OpenRegister GeneratedIdentifierException
 *
 * Raised when a property's `x-openregister-generated` block cannot produce a
 * unique value: an unknown placeholder, a format with no sequence in it, a
 * yearly reset that does not render the year, or a declaration on a property
 * that is not a string.
 *
 * It extends PropertyVocabularyException on purpose. Every schema-save path in
 * SchemasController already catches that one and answers 422 naming the
 * property, so a malformed generated identifier refuses the save the same way
 * a misspelled constraint key does, and no controller had to learn about this
 * annotation to do it.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

/**
 * A generated-identifier declaration the schema save refuses.
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
 */
class GeneratedIdentifierException extends PropertyVocabularyException {

	/**
	 * Build the exception from the refusal's sentence.
	 *
	 * The per-value error list the parent carries is filled with one entry, so
	 * a client reading `getErrors()` sees this refusal beside the vocabulary's
	 * own rather than an empty list it has to special-case.
	 *
	 * @param string $message The sentence naming what was refused.
	 * @param string $path The property path the refusal is about.
	 *
	 * @return void
	 */
	public function __construct(string $message, string $path = '') {
		parent::__construct(
			$message,
			[
				[
					'code' => 'generated-identifier-invalid',
					'key' => GeneratedIdentifierDeclaration::ANNOTATION,
					'path' => $path,
					'message' => $message,
				],
			]
		);

	}//end __construct()
}//end class
