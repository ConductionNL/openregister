<?php

/**
 * SerialisedPdf
 *
 * The serialised output of a SAPP replace run together with the statistics SAPP returned for it.
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
 * Output bytes of a replace run plus SAPP's replacement statistics, the
 * input of the residual check in {@see PdfTextReplacer::validateOutput()}.
 *
 * @spec openspec/specs/pdf-anonymisation/spec.md
 */
final class SerialisedPdf {
	/**
	 * Constructor.
	 *
	 * @param string               $bytes        Serialised output PDF bytes.
	 * @param array<string, mixed> $replaceStats Stats returned by SAPP's replace API
	 *                                           (kept in the diagnostic surface for ops review).
	 */
	public function __construct(
		public readonly string $bytes,
		public readonly array $replaceStats = [],
	) {

	}//end __construct()
}//end class
