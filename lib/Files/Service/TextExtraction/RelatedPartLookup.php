<?php

/**
 * RelatedPartLookup: which part of a package an owner part relates to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/text-extraction-document/spec.md#requirement-content-comes-back-in-sections-under-their-heading-req-docx-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

/**
 * The owner part's relationships, the relationship type sought and the conventional path to fall back on.
 *
 * @spec openspec/specs/text-extraction-document/spec.md#requirement-content-comes-back-in-sections-under-their-heading-req-docx-001
 */
final class RelatedPartLookup {

	/**
	 * Constructor.
	 *
	 * @param array<string, array{type: string, target: string, external: bool}> $relationships The owner's relationships.
	 * @param string                                                             $typeSuffix    The end of the relationship type, e.g. `/styles`.
	 * @param string                                                             $fallback      The conventional part path.
	 */
	public function __construct(
		public readonly array $relationships,
		public readonly string $typeSuffix,
		public readonly string $fallback,
	) {
	}//end __construct()
}//end class
