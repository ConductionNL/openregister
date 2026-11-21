<?php

/**
 * OpenRegister metadata-masking sanitiser strategy
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File\Sanitizer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-a-value-inside-a-metadata-field-is-masked-and-unmaskable-fields-are-named-req-rrs-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File\Sanitizer;

use OCA\OpenRegister\Exception\SanitizationException;
use OCA\OpenRegister\Files\Service\File\SanitizationReport;

/**
 * A sanitiser that can mask descriptive metadata with an anonymisation
 * run's substitution map instead of replacing it by the sentinel.
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-a-value-inside-a-metadata-field-is-masked-and-unmaskable-fields-are-named-req-rrs-004
 */
interface MetadataMaskingSanitizerInterface extends SanitizerInterface {
	/**
	 * Sanitise as {@see SanitizerInterface::sanitize()}, masking title,
	 * subject, keywords, description and text custom properties with the
	 * mask, stripping identity fields, and removing and naming the fields
	 * that cannot be masked.
	 *
	 * @param string       $sourcePath Path to the source document.
	 * @param string       $destPath   Path to write the sanitised output.
	 * @param MetadataMask $mask       The run's substitution map; collects the unmaskable fields.
	 *
	 * @throws SanitizationException On encryption / corrupt-zip / internal failure.
	 *
	 * @return SanitizationReport Per-category counts, with `unmaskableFields` for a masked run.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-a-value-inside-a-metadata-field-is-masked-and-unmaskable-fields-are-named-req-rrs-004
	 */
	public function sanitizeMasked(string $sourcePath, string $destPath, MetadataMask $mask): SanitizationReport;
}//end interface
