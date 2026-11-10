<?php

/**
 * A word-processing document part with the parts it reads beside it.
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

use DOMDocument;

/**
 * The parsed document part, its styles and numbering parts, and its relationships.
 *
 * @psalm-import-type Relationships from DocumentContentReader
 *
 * @spec openspec/specs/text-extraction-document/spec.md#requirement-content-comes-back-in-sections-under-their-heading-req-docx-001
 */
final class DocumentPart {
	/**
	 * Constructor.
	 *
	 * @param DOMDocument      $document      The parsed document part.
	 * @param DOMDocument|null $styles        The parsed styles part, or null.
	 * @param DOMDocument|null $numbering     The parsed numbering part, or null.
	 * @param Relationships    $relationships The document part's relationships.
	 *
	 * @spec openspec/specs/text-extraction-document/spec.md#requirement-content-comes-back-in-sections-under-their-heading-req-docx-001
	 */
	public function __construct(
		public readonly DOMDocument $document,
		public readonly ?DOMDocument $styles,
		public readonly ?DOMDocument $numbering,
		public readonly array $relationships,
	) {
	}//end __construct()
}//end class
