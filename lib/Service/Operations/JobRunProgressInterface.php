<?php

/**
 * JobRunProgressInterface: a running job reports how far it is.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

/**
 * Write a running job's progress onto its open run row.
 *
 * Only the recorder that opened the row can write to it, so a job asks the
 * recorder it was given rather than looking its own row up: two runs of one
 * job class would otherwise report onto each other's row.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md#requirement-an-administrator-reindexes-a-selection-and-watches-it-run-req-sqf-007
 */
interface JobRunProgressInterface {

	/**
	 * Store the progress as the details of the job's open run row.
	 *
	 * @param string               $jobClass The job class whose run is open.
	 * @param array<string, mixed> $details  The progress, as the run row's details.
	 *
	 * @return bool True when the row was written; false when no run of the class is open or the row could not be written.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md#requirement-an-administrator-reindexes-a-selection-and-watches-it-run-req-sqf-007
	 */
	public function progress(string $jobClass, array $details): bool;
}//end interface
