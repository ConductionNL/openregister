<?php

/**
 * OpenRegister Resolved Selection
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\BulkJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\BulkJob;

use OCA\OpenRegister\Db\BulkJob;

/**
 * A selection after resolution: which of the two kinds it is and the uuids it stands for.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */
final readonly class ResolvedSelection {
	/**
	 * Constructor.
	 *
	 * @param BulkJobSelection   $selection The selection as the caller sent it.
	 * @param string             $kind      One of the BulkJob::SELECTION_* constants.
	 * @param array<int, string> $uuids     The uuids it resolved to.
	 */
	public function __construct(
		public BulkJobSelection $selection,
		public string $kind,
		public array $uuids,
	) {
	}//end __construct()

	/**
	 * The selection as the job stores it: the query for a query selection,
	 * the resolved uuids for an id list.
	 *
	 * @return array<string, mixed> The stored selection.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function stored(): array {
		if ($this->kind === BulkJob::SELECTION_QUERY) {
			return ['query' => ($this->selection->criteria['query'] ?? [])];
		}

		return ['ids' => $this->uuids];
	}//end stored()
}//end class
