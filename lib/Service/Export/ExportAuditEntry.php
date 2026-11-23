<?php

/**
 * ExportAuditEntry — one export fact for the hash-chained audit trail.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
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

namespace OCA\OpenRegister\Service\Export;

/**
 * One completed or refused export as the ledger records it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ExportAuditEntry {

	/**
	 * Describe the entry.
	 *
	 * @param string               $outcome The outcome, completed or refused.
	 * @param array<string, mixed> $summary The structured summary.
	 * @param ExportScope          $scope   Register, schema and principal.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly array $summary,
		public readonly ExportScope $scope,
	) {
	}//end __construct()
}//end class
