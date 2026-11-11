<?php

/**
 * OasServiceInterface: what callers outside the Service component ask of OasService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Service\Oas\OasValidationReport;

/**
 * Generates the OpenAPI description of one or all registers.
 */
interface OasServiceInterface {

	/**
	 * Returns the validation report from the most recent createOas() call.
	 *
	 * @return OasValidationReport The current report
	 */
	public function getLastValidationReport(): OasValidationReport;

	/**
	 * Create the OpenAPI specification for one register, or for all registers when none is given.
	 *
	 * @param string|null $registerId The register to describe, or null for all registers
	 * @param bool        $strict     Throw on a validation error instead of auto-correcting it
	 *
	 * @return array<string, mixed> The complete OpenAPI specification array
	 *
	 * @throws \Exception When the base OAS file cannot be read, or in strict mode on a validation error
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	public function createOas(?string $registerId = null, bool $strict = false): array;
}//end interface
