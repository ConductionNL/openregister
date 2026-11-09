<?php

/**
 * DestructionReviewServiceInterface: what callers outside the component use of DestructionReviewService.
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
use InvalidArgumentException;

/**
 * The destruction review steps the archival endpoints call.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface DestructionReviewServiceInterface {

	/**
	 * Destroy the record. It has reached its disposal date and nothing holds it.
	 */
	public const ANSWER_DESTROY = 'destroy';

	/**
	 * Keep the record, on a new disposal date, for a recorded reason.
	 */
	public const ANSWER_RETAIN = 'retain';

	/**
	 * Hand the record to an e-Depot rather than destroy it.
	 */
	public const ANSWER_TRANSFER = 'transfer';

	/**
	 * The three answers a reviewer may give, and no others.
	 *
	 * @var string[]
	 */
	public const ANSWERS = [
		self::ANSWER_DESTROY,
		self::ANSWER_RETAIN,
		self::ANSWER_TRANSFER,
	];

	/**
	 * Make one person accountable for one entry.
	 *
	 * The moment of assignment is written down because the reminder pass needs
	 * it: "pending beyond the declared frequency" is a question about how long
	 * an entry has been waiting on somebody, and without an assignment time the
	 * only answer available is "since the list was made", which restarts nothing
	 * when an entry is handed to a second reviewer.
	 *
	 * @param array<string, mixed> $listData   The destruction list's own data.
	 * @param string               $entryUuid  The uuid of the object the entry is about.
	 * @param string|null          $reviewer   The reviewer's user id, or null to unassign.
	 * @param DateTimeInterface|null $assignedAt The moment of assignment; now when omitted.
	 *
	 * @return array<string, mixed> The list data, with the entry assigned.
	 *
	 * @throws InvalidArgumentException When the list has no such entry, or the entry is decided.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function assignReviewer(array $listData, string $entryUuid, ?string $reviewer, ?DateTimeInterface $assignedAt = null): array;

	/**
	 * The entries of a destruction list, as a list.
	 *
	 * @param array<string, mixed> $listData The destruction list's own data.
	 *
	 * @return array<int, array<string, mixed>> The entries, empty when there are none.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function entries(array $listData): array;

	/**
	 * One entry, by the uuid of the object it is about.
	 *
	 * @param array<string, mixed> $listData  The destruction list's own data.
	 * @param string               $entryUuid The uuid of the object the entry is about.
	 *
	 * @return array<string, mixed>|null The entry, or null when the list has no such entry.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function entry(array $listData, string $entryUuid): ?array;

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
	 * Record one reviewer's answer, in the one decision history.
	 *
	 * All three answers land in the same place. A transfer recorded on the
	 * transfer list and nowhere else would leave this list saying the entry was
	 * never decided, which is how a decision history becomes two.
	 *
	 * The moment of the decision is now, as the injected clock tells it.
	 *
	 * @param array<string, mixed> $listData    The destruction list's own data.
	 * @param string               $entryUuid   The uuid of the object the entry is about.
	 * @param ReviewerAnswer       $given       The answer (one of ANSWERS), its reason (required for every
	 *                                          answer), the new archiefactiedatum a retention requires, and
	 *                                          the reviewer, who must be the one assigned.
	 * @param string|null          $transferRef The transfer list this entry was handed to, if any.
	 *
	 * @return array<string, mixed> The list data, with the entry decided and the history appended.
	 *
	 * @throws InvalidArgumentException When the answer, the entry, the reviewer or the reason is wrong.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function recordAnswer(array $listData, string $entryUuid, ReviewerAnswer $given, ?string $transferRef = null): array;

	/**
	 * A reviewer's answer, as the reviewer gave it.
	 *
	 * @param string      $answer   One of the review answers.
	 * @param string      $reason   Why the reviewer answered this way.
	 * @param string|null $newDate  The new archiefactiedatum, for a retention.
	 * @param string|null $reviewer Who answered.
	 *
	 * @return ReviewerAnswer The answer, to carry out and then record.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function reviewerAnswer(string $answer, string $reason, ?string $newDate, ?string $reviewer): ReviewerAnswer;

	/**
	 * The entries on this list that nobody is accountable for.
	 *
	 * A list is readable whether or not its entries are assigned, and an
	 * unassigned entry is NAMED rather than counted: "3 of 15 unassigned" tells
	 * a records officer there is work to do and not which work.
	 *
	 * @param array<string, mixed> $listData The destruction list's own data.
	 *
	 * @return array<int, array{uuid: string, title: string}> The unassigned entries.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function unassignedEntries(array $listData): array;
	/**
	 * How many entries each reviewer is holding.
	 *
	 * @param array<int, array<string, mixed>> $entries Pending entries, from pendingEntries().
	 *
	 * @return array<string, int> Reviewer user id mapped to their pending count.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function countByReviewer(array $entries): array;
}//end interface
