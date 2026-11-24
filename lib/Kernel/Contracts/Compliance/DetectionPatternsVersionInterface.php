<?php
/**
 * DetectionPatternsVersionInterface: what the built-in pattern backend is built from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Compliance
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Compliance;

/**
 * The version of the patterns the built-in detection backend recognises with.
 *
 * The anonymisation disclosure (Compliance) reports it; text extraction
 * (Files) owns the patterns and binds its answer in its own registrar.
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-every-detection-backend-reports-what-it-is-built-from-req-adi-001
 */
interface DetectionPatternsVersionInterface {

	/**
	 * The version of the patterns, raised whenever a pattern, a confidence or a false-positive guard changes.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-every-detection-backend-reports-what-it-is-built-from-req-adi-001
	 */
	public function patternsVersion(): string;

	/**
	 * The day the patterns last changed (ISO 8601 date).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-the-detector-says-when-it-last-changed-req-adi-004
	 */
	public function patternsChangedAt(): string;
}//end interface
