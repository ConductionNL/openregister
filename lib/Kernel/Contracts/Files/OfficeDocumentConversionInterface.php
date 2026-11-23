<?php

/**
 * OfficeDocumentConversionInterface: PDF and ODT conversion through the office server.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Files
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Files;

/**
 * Bidirectional PDF and ODT conversion.
 *
 * Implementations MAY raise `\RuntimeException` when the converter is unreachable or returns a
 * non-2xx status. Per ADR-005 implementations MUST NOT log document content; only file sizes,
 * MIME types, and structural error details.
 *
 * Files answers (ConversionManagerConverter, through NcOfficeConverterInterface).
 *
 * @spec openspec/specs/pdf-anonymisation-odt-fallback/spec.md
 */
interface OfficeDocumentConversionInterface {

	/**
	 * Whether the NC Office bridge is reachable + ready for conversion.
	 *
	 * Path B short-circuits when this returns false (treats as
	 * `validation_failed_after_fallback` without attempting a round-trip).
	 *
	 * @return bool True when NC Office is installed and reachable.
	 *
	 * @spec openspec/specs/pdf-anonymisation-odt-fallback/spec.md
	 */
	public function isAvailable(): bool;

	/**
	 * Convert PDF bytes to ODT bytes via NC Office.
	 *
	 * @param string $pdfBytes Raw input PDF bytes.
	 *
	 * @return string Raw output ODT bytes.
	 *
	 * @throws \RuntimeException When the conversion fails for any reason.
	 *
	 * @spec openspec/specs/pdf-anonymisation-odt-fallback/spec.md
	 */
	public function pdfToOdt(string $pdfBytes): string;

	/**
	 * Convert ODT bytes to PDF bytes via NC Office.
	 *
	 * @param string $odtBytes Raw input ODT bytes.
	 *
	 * @return string Raw output PDF bytes.
	 *
	 * @throws \RuntimeException When the conversion fails for any reason.
	 *
	 * @spec openspec/specs/pdf-anonymisation-odt-fallback/spec.md
	 */
	public function odtToPdf(string $odtBytes): string;
}//end interface
