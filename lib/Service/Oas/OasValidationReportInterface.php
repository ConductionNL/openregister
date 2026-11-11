<?php

/**
 * OasValidationReportInterface: an OAS validation report as the strict-mode refusal carries it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

/**
 * OasValidationReport, typed for callers outside the Service component.
 *
 * OasValidationException carries the report and the OAS controller answers a
 * strict-mode refusal with its summary; that is the whole contract.
 */
interface OasValidationReportInterface {
	/**
	 * Compact summary of the validation: passed, the counts per severity and every issue.
	 *
	 * @return array<string, mixed> The summary.
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	public function toSummary(): array;
}//end interface
