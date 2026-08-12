<?php

/**
 * SanitizerInterface
 *
 * Per-format Office document sanitiser strategy contract. Implementations
 * perform XML-level surgery on a ZIP container copy to strip PII-bearing
 * non-text structures.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Sanitizer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File\Sanitizer;

use OCA\OpenRegister\Exception\SanitizationException;
use OCA\OpenRegister\Service\File\SanitizationReport;

/**
 * Strategy contract for a single Office document format.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Sanitizer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 */
interface SanitizerInterface {
	/**
	 * Whether this strategy supports the given MIME type.
	 *
	 * @param string $mimeType The file MIME type.
	 *
	 * @return bool True when this strategy can sanitise the format.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function supports(string $mimeType): bool;

	/**
	 * Sanitise the document at $sourcePath, writing the result to $destPath.
	 *
	 * Source and destination MAY be the same path (in-place on a temp copy).
	 *
	 * @param string $sourcePath Path to the source document.
	 * @param string $destPath Path to write the sanitised output.
	 *
	 * @throws SanitizationException On encryption / corrupt-zip / internal failure.
	 *
	 * @return SanitizationReport Per-category sanitisation counts.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function sanitize(string $sourcePath, string $destPath): SanitizationReport;
}//end interface
