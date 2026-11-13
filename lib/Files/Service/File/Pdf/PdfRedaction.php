<?php

/**
 * PdfRedaction
 *
 * The outcome of one PDF redaction run: the anonymised bytes, the entity
 * text that survived replacement, and the accessibility-structure report.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Pdf
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/pdf-anonymisation/spec.md
 * @spec openspec/specs/tag-preserving-redaction/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File\Pdf;

use OCA\OpenRegister\Kernel\Contracts\StructurePreservation;

/**
 * Result of {@see PdfTextReplacer::replaceInPdf()}.
 *
 * @spec openspec/specs/pdf-anonymisation/spec.md
 */
final class PdfRedaction {
	/**
	 * Constructor.
	 *
	 * @param string $bytes The anonymised PDF bytes.
	 * @param StructurePreservation $structurePreservation The `structurePreservation`
	 *                                                     result block (REQ-ORTPR-003).
	 * @param array<int, string> $residualEntities Entity text that remained after
	 *                                             replacement (empty when clean).
	 *
	 * @spec openspec/specs/pdf-anonymisation/spec.md
	 */
	public function __construct(
		public readonly string $bytes,
		public readonly StructurePreservation $structurePreservation,
		public readonly array $residualEntities = [],
	) {

	}//end __construct()
}//end class
