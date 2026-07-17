<?php

/**
 * NcOfficeConverterInterface
 *
 * Contract for the Nextcloud Office (Collabora Online / Code) PDF → ODT and
 * ODT → PDF conversion bridge used by the dormant Path B PDF anonymisation
 * fallback ({@see PdfOdtFallbackOrchestrator}).
 *
 * The implementation lives outside this app (NC Office is an external
 * collaborator); this interface is the seam Path B hooks into. Until the
 * feature flag flips on, no production implementation is wired —
 * {@see NullNcOfficeConverter} is the default no-op.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Pdf\Fallback
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/pdf-anonymisation-odt-fallback/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File\Pdf\Fallback;

/**
 * Bidirectional PDF ↔ ODT conversion contract for the Path B fallback.
 *
 * Implementations MAY raise `\RuntimeException` when the converter is
 * unreachable or returns a non-2xx status. Path B treats any conversion
 * failure as a Path-B-side failure (`validation_failed_after_fallback`).
 *
 * Per ADR-005 implementations MUST NOT log document content; only file
 * sizes, MIME types, and structural error details.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Pdf\Fallback
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/pdf-anonymisation-odt-fallback/spec.md
 */
interface NcOfficeConverterInterface
{
    /**
     * Whether the NC Office bridge is reachable + ready for conversion.
     *
     * Path B short-circuits when this returns false (treats as
     * `validation_failed_after_fallback` without attempting a round-trip).
     *
     * @return bool True when NC Office is installed and reachable.
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
