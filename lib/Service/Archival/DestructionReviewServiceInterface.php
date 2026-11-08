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
	 * @param array<string, mixed> $listData    The destruction list's own data.
	 * @param string               $entryUuid   The uuid of the object the entry is about.
	 * @param string               $answer      One of self::ANSWERS.
	 * @param string               $reviewer    The user id answering; must be the assigned reviewer.
	 * @param string               $reason      Why. Required for every answer, not only a retention.
	 * @param string|null          $newDate     The new archiefactiedatum, required by a retention.
	 * @param string|null          $transferRef The transfer list this entry was handed to, if any.
	 * @param DateTimeInterface|null $decidedAt The moment of the decision; now when omitted.
	 *
	 * @return array<string, mixed> The list data, with the entry decided and the history appended.
	 *
	 * @throws InvalidArgumentException When the answer, the entry, the reviewer or the reason is wrong.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) One decision, and every fact it records.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function recordAnswer(
		array $listData, string $entryUuid, string $answer, string $reviewer,
		string $reason, ?string $newDate = null, ?string $transferRef = null, ?DateTimeInterface $decidedAt = null,
	): array;

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
}//end interface
