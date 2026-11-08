<?php

/**
 * DestructionReviewReminderInterface: the pending-review tally the review reminder sends.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateTimeInterface;

/**
 * The destruction-list entries still waiting on a reviewer, counted per reviewer.
 *
 * Bound to DestructionReviewService in ContractBindings.
 */
interface DestructionReviewReminderInterface {
	/**
	 * The entries still waiting on somebody.
	 *
	 * @param array<string, mixed>   $listData     The destruction list's own data.
	 * @param string                 $listUuid     The uuid of the list, carried onto each entry.
	 * @param string|null            $reviewer     Only this reviewer's entries, or every assigned entry when null.
	 * @param DateTimeInterface|null $waitingSince Only entries assigned at or before this moment.
	 *
	 * @return array<int, array<string, mixed>> The pending entries, each naming its list.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function pendingEntries(array $listData, string $listUuid, ?string $reviewer = null, ?DateTimeInterface $waitingSince = null): array;

	/**
	 * Pending entries counted per reviewer.
	 *
	 * @param array<int, array<string, mixed>> $entries Pending entries, from pendingEntries().
	 *
	 * @return array<string, int> Reviewer user id mapped to their pending count.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function countByReviewer(array $entries): array;
}//end interface
