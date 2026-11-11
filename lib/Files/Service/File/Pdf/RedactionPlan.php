<?php

/**
 * RedactionPlan
 *
 * What one PDF redaction run replaces and under which rules: the substitution map, the strict-validation policy and the structure-preservation option.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File\Pdf;

/**
 * The plan of one PDF redaction run, read by {@see PdfTextReplacer::replaceInPdf()}
 * and {@see PdfTextReplacer::validateOutput()}.
 *
 * @spec openspec/specs/pdf-anonymisation/spec.md
 */
final class RedactionPlan {
	/**
	 * Constructor.
	 *
	 * @param array<string, string> $substitutions     Map: entity-text => placeholder
	 *                                                 (e.g. ['Jan Jansen' => '[PERSON: 7]']).
	 * @param bool                  $strict            When true (entity anonymisation), an output
	 *                                                 that cannot be re-read is refused; when false
	 *                                                 (ad-hoc replace) it is returned unchecked.
	 * @param bool|null             $preserveStructure Tri-state structure-preservation option
	 *                                                 (REQ-ORTPR-004): null = auto, true = attempt
	 *                                                 even on ambiguous detection, false = skip but
	 *                                                 still measure.
	 */
	public function __construct(
		public readonly array $substitutions,
		public readonly bool $strict,
		public readonly ?bool $preserveStructure = null,
	) {

	}//end __construct()
}//end class
