<?php

/**
 * OpenRegister DeclarationLookup
 *
 * What a vocabulary read names to find its coded declaration: a saved
 * property (schema and property), or a scheme on its own.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

/**
 * The input of {@see VocabularyDeclarationResolver::resolveDeclaration()}.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */
final class DeclarationLookup {

	/**
	 * Constructor.
	 *
	 * @param string $schemaRef The schema id or slug, empty when none was given.
	 * @param string $property  The property name, empty when none was given.
	 * @param string $schemeUri The scheme uri for the unsaved path, empty when none was given.
	 */
	public function __construct(
		public readonly string $schemaRef,
		public readonly string $property,
		public readonly string $schemeUri,
	) {
	}//end __construct()
}//end class
