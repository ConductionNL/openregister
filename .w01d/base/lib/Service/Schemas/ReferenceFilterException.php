<?php

/**
 * A reference filter the schema save refuses.
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
 * @spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

/**
 * A reference filter that names something neither schema declares.
 *
 * Extends the vocabulary exception for the reason
 * {@see GeneratedIdentifierException} does: every schema-save path already
 * answers that as a 422 naming the property, so no controller had to learn
 * about this annotation to refuse it well.
 *
 * @spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md
 */
class ReferenceFilterException extends PropertyVocabularyException {

	/**
	 * Build the exception from the refusal's sentence.
	 *
	 * @param string $message The sentence naming what was refused.
	 * @param string $path    The property path the refusal is about.
	 * @param string $code    Which of the refusals this is.
	 * @param string $key     The key the refusal is about.
	 *
	 * @return void
	 */
	public function __construct(
		string $message,
		string $path = '',
		string $code = 'reference-filter-invalid',
		string $key = 'x-openregister-reference-filter',
	) {
		parent::__construct(
			message: $message,
			errors: [
				[
					'code' => $code,
					'key' => $key,
					'path' => $path,
					'message' => $message,
				],
			]
		);

	}//end __construct()
}//end class
