<?php

/**
 * What an archivist chose when approving a destruction list.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * Approve the whole list, or approve it with some records taken off and why.
 *
 * Built by {@see DestructionServiceInterface::approvalChoice()}, so callers
 * outside this component never name it.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class ApprovalChoice {
	/**
	 * Hold the choice.
	 *
	 * @param string                $action           The approval action: 'approve_all' or 'approve_partial'.
	 * @param array<int, string>    $excludedIds      UUIDs of objects to exclude (for partial approval).
	 * @param array<string, string> $exclusionReasons Reasons per excluded object UUID.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function __construct(
		public readonly string $action = 'approve_all',
		public readonly array $excludedIds = [],
		public readonly array $exclusionReasons = [],
	) {
	}//end __construct()

	/**
	 * Whether this approval takes records off the list.
	 *
	 * @return bool True for a partial approval that names at least one record.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function excludesRecords(): bool {
		return ($this->action === 'approve_partial' && empty($this->excludedIds) === false);
	}//end excludesRecords()
}//end class
