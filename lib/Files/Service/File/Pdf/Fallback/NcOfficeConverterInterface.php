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
 * {@see \OCA\OpenRegister\Files\Service\File\Office\ConversionManagerConverter} is the binding.
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

namespace OCA\OpenRegister\Files\Service\File\Pdf\Fallback;

use OCA\OpenRegister\Kernel\Contracts\Files\OfficeDocumentConversionInterface;

/**
 * The PDF and ODT conversion of the Path B fallback.
 *
 * Path B treats any conversion failure as a Path-B-side failure
 * (`validation_failed_after_fallback`). The operations are declared on the
 * port this interface extends.
 *
 * @spec openspec/specs/pdf-anonymisation-odt-fallback/spec.md
 */
interface NcOfficeConverterInterface extends OfficeDocumentConversionInterface {
}//end interface
