<?php

/**
 * DetectorRecordInterface: the version record of the detection backend in effect.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * What an anonymisation run stores about the detector that ran, so the Files
 * area reads it without naming the Compliance backend service (ADR-013 Rule 1).
 * Implemented by Service\Anonymisation\DetectorRecord.
 */
interface DetectorRecordInterface {

	/**
	 * The version record of the detection backend in effect, as JSON, with the
	 * backend's name under `backend`; null when the effective backend has no record.
	 *
	 * @return string|null The JSON record, or null.
	 *
	 * @throws \Throwable When the backend state cannot be read.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-every-detection-backend-reports-what-it-is-built-from-req-adi-001
	 */
	public function detectorRecord(): ?string;
}//end interface
