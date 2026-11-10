<?php

/**
 * OpenRegister OdtRoundTripRequest.
 *
 * One request for the Path B ODT round-trip of a PDF whose Path A
 * redaction failed validation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File\Pdf\Fallback
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File\Pdf\Fallback;

use OCA\OpenRegister\Exception\PdfAnonymisationException;

/**
 * The Path B round-trip of one PDF.
 *
 * @spec openspec/specs/pdf-anonymisation-odt-fallback/spec.md
 */
final class OdtRoundTripRequest {
	/**
	 * Constructor.
	 *
	 * @param string                    $pdfBytes      The original PDF bytes.
	 * @param array<string, string>     $substitutions Same substitutions as Path A.
	 * @param PdfAnonymisationException $cause         The Path A exception that triggered the fallback.
	 *
	 * @spec openspec/specs/pdf-anonymisation-odt-fallback/spec.md
	 */
	public function __construct(
		public readonly string $pdfBytes,
		public readonly array $substitutions,
		public readonly PdfAnonymisationException $cause,
	) {
	}//end __construct()
}//end class
