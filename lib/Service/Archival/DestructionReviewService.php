<?php

/**
 * The review of one entry on a destruction list, and who signed it off.
 *
 * 🔴 THIS EXISTS BECAUSE A DESTRUCTION LIST WAS ADDRESSED TO A GROUP AND
 * ANSWERED IN TWO WORDS. `RetentionController::approveDestructionList()` takes
 * one archivist's approval for the WHOLE list, and the only other answer is a
 * rejection of the whole list. Nothing on a list said which person was
 * accountable for which record, so nobody could be reminded, nothing appeared
 * on anybody's worklist, and an entry nobody had looked at was indistinguishable
 * from one everybody had.
 *
 * The reviewer's real question has three answers, not two: destroy it, keep it
 * for longer and say why, or hand it to an e-Depot. Recording the third one
 * somewhere else splits the decision history in two, which is exactly what an
 * auditor asking "who approved the destruction of this dossier" cannot use.
 *
 * NO IO. This class reads and rewrites the list's own data array, so every rule
 * in it is a unit test away. Loading and saving that array is
 * {@see DestructionListRepository}'s job, and the session identity is the
 * controller's.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Assignment, sign-off and the decision history of a destruction list.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Every branch in here is one
 *              rule of the sign-off, and each rule refuses with its own message:
 *              no such entry, nobody accountable, somebody else accountable,
 *              already answered, a fourth answer, no reason, a retention with no
 *              date. Collapsing them into fewer branches would lower the number
 *              and take away the one thing a refused reviewer needs, which is
 *              WHICH rule refused them.
 */
class DestructionReviewService {

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
	 * The entries of a destruction list, as a list.
	 *
	 * @param array<string, mixed> $listData The destruction list's own data.
	 *
	 * @return array<int, array<string, mixed>> The entries, empty when there are none.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function entries(array $listData): array {
		$entries = ($listData['objects'] ?? []);
		if (is_array($entries) === false) {
			return [];
		}

		return array_values(array_filter($entries, 'is_array'));
	}//end entries()

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
	public function entry(array $listData, string $entryUuid): ?array {
		foreach ($this->entries(listData: $listData) as $entry) {
			if (($entry['uuid'] ?? null) === $entryUuid) {
				return $entry;
			}
		}

		return null;
	}//end entry()

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
	public function unassignedEntries(array $listData): array {
		$unassigned = [];
		foreach ($this->entries(listData: $listData) as $entry) {
			if ($this->reviewerOf(entry: $entry) !== null) {
				continue;
			}

			$uuid = (string)($entry['uuid'] ?? '');
			$unassigned[] = [
				'uuid' => $uuid,
				'title' => (string)($entry['title'] ?? $uuid),
			];
		}

		return $unassigned;
	}//end unassignedEntries()

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
	public function assignReviewer(
		array $listData,
		string $entryUuid,
		?string $reviewer,
		?DateTimeInterface $assignedAt = null,
	): array {
		$index = $this->indexOf(listData: $listData, entryUuid: $entryUuid);
		$entry = $listData['objects'][$index];

		if (($entry['decision'] ?? null) !== null) {
			throw new InvalidArgumentException(
				sprintf(
					'Entry %s has already been answered with "%s" and cannot be reassigned',
					$entryUuid,
					(string)$entry['decision']
				)
			);
		}

		$moment = ($assignedAt ?? new DateTimeImmutable());

		if ($reviewer === null || trim($reviewer) === '') {
			unset($entry['reviewer'], $entry['assignedAt']);
			$listData['objects'][$index] = $entry;
			return $listData;
		}

		$entry['reviewer'] = $reviewer;
		$entry['assignedAt'] = $moment->format('c');
		$listData['objects'][$index] = $entry;

		return $listData;
	}//end assignReviewer()

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
		array $listData,
		string $entryUuid,
		string $answer,
		string $reviewer,
		string $reason,
		?string $newDate = null,
		?string $transferRef = null,
		?DateTimeInterface $decidedAt = null,
	): array {
		$this->guardAnswer(answer: $answer, reason: $reason, newDate: $newDate);

		$index = $this->indexOf(listData: $listData, entryUuid: $entryUuid);
		$entry = $listData['objects'][$index];

		$this->guardReviewer(entry: $entry, entryUuid: $entryUuid, reviewer: $reviewer);

		$moment = ($decidedAt ?? new DateTimeImmutable())->format('c');

		$decision = [
			'entry' => $entryUuid,
			'answer' => $answer,
			'reviewer' => $reviewer,
			'decidedAt' => $moment,
			'reason' => $reason,
		];

		if ($answer === self::ANSWER_RETAIN) {
			$decision['newArchiefactiedatum'] = $newDate;
		}

		if ($answer === self::ANSWER_TRANSFER && $transferRef !== null) {
			$decision['transferListUuid'] = $transferRef;
		}

		$entry['decision'] = $answer;
		$entry['decidedBy'] = $reviewer;
		$entry['decidedAt'] = $moment;
		$listData['objects'][$index] = $entry;

		$history = ($listData['decisions'] ?? []);
		if (is_array($history) === false) {
			$history = [];
		}

		$history[] = $decision;
		$listData['decisions'] = $history;

		return $listData;
	}//end recordAnswer()

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
	public function pendingEntries(
		array $listData,
		string $listUuid,
		?string $reviewer = null,
		?DateTimeInterface $waitingSince = null,
	): array {
		$pending = [];
		foreach ($this->entries(listData: $listData) as $entry) {
			$assigned = $this->reviewerOf(entry: $entry);
			if ($assigned === null || ($entry['decision'] ?? null) !== null) {
				continue;
			}

			if ($reviewer !== null && $assigned !== $reviewer) {
				continue;
			}

			if ($this->waitedLongEnough(entry: $entry, waitingSince: $waitingSince) === false) {
				continue;
			}

			$uuid = (string)($entry['uuid'] ?? '');
			$pending[] = [
				'list' => $listUuid,
				'listStatus' => ($listData['status'] ?? null),
				'uuid' => $uuid,
				'title' => (string)($entry['title'] ?? $uuid),
				'reviewer' => $assigned,
				'assignedAt' => ($entry['assignedAt'] ?? null),
				'archiefactiedatum' => ($entry['archiefactiedatum'] ?? null),
				'classification' => ($entry['classification'] ?? null),
			];
		}//end foreach

		return $pending;
	}//end pendingEntries()

	/**
	 * How many entries each reviewer is holding.
	 *
	 * @param array<int, array<string, mixed>> $entries Pending entries, from pendingEntries().
	 *
	 * @return array<string, int> Reviewer user id mapped to their pending count.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function countByReviewer(array $entries): array {
		$counts = [];
		foreach ($entries as $entry) {
			$reviewer = ($entry['reviewer'] ?? null);
			if (is_string($reviewer) === false || $reviewer === '') {
				continue;
			}

			$counts[$reviewer] = (($counts[$reviewer] ?? 0) + 1);
		}

		return $counts;
	}//end countByReviewer()

	/**
	 * The reviewer named on an entry, or null when nobody is.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return string|null The reviewer's user id.
	 */
	private function reviewerOf(array $entry): ?string {
		$reviewer = ($entry['reviewer'] ?? null);
		if (is_string($reviewer) === false || trim($reviewer) === '') {
			return null;
		}

		return $reviewer;
	}//end reviewerOf()

	/**
	 * Has this entry been waiting since before the cutoff?
	 *
	 * AN ENTRY WITH NO ASSIGNMENT TIME COUNTS AS WAITING. Lists written before
	 * assignment existed carry entries with a reviewer and no `assignedAt`, and
	 * treating those as freshly assigned would mean the reminder for the oldest
	 * work on the instance never fires.
	 *
	 * @param array<string, mixed>   $entry        The entry.
	 * @param DateTimeInterface|null $waitingSince The cutoff, or null for no cutoff.
	 *
	 * @return bool True when the entry counts as waiting.
	 */
	private function waitedLongEnough(array $entry, ?DateTimeInterface $waitingSince): bool {
		if ($waitingSince === null) {
			return true;
		}

		$assignedAt = ($entry['assignedAt'] ?? null);
		if (is_string($assignedAt) === false || $assignedAt === '') {
			return true;
		}

		$moment = date_create_immutable($assignedAt);
		if ($moment === false) {
			return true;
		}

		return ($moment->getTimestamp() <= $waitingSince->getTimestamp());
	}//end waitedLongEnough()

	/**
	 * Where this entry sits in the list's own array.
	 *
	 * @param array<string, mixed> $listData  The destruction list's own data.
	 * @param string               $entryUuid The uuid of the object the entry is about.
	 *
	 * @return int The index into $listData['objects'].
	 *
	 * @throws InvalidArgumentException When the list has no such entry.
	 */
	private function indexOf(array $listData, string $entryUuid): int {
		$entries = ($listData['objects'] ?? []);
		if (is_array($entries) === true) {
			foreach ($entries as $index => $entry) {
				if (is_array($entry) === true && ($entry['uuid'] ?? null) === $entryUuid) {
					return $index;
				}
			}
		}

		throw new InvalidArgumentException(
			sprintf('This destruction list has no entry for object %s', $entryUuid)
		);
	}//end indexOf()

	/**
	 * Refuse an answer that is not one of the three, or that says nothing.
	 *
	 * @param string      $answer  The answer given.
	 * @param string      $reason  The reason given.
	 * @param string|null $newDate The new archiefactiedatum, for a retention.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the answer, the reason or the date is missing.
	 */
	private function guardAnswer(string $answer, string $reason, ?string $newDate): void {
		if (in_array($answer, self::ANSWERS, true) === false) {
			throw new InvalidArgumentException(
				sprintf(
					'"%s" is not a review answer; the answers are %s',
					$answer,
					implode(', ', self::ANSWERS)
				)
			);
		}

		if (trim($reason) === '') {
			throw new InvalidArgumentException('A review answer records why it was given, so a reason is required');
		}

		if ($answer !== self::ANSWER_RETAIN) {
			return;
		}

		if ($newDate === null || trim($newDate) === '') {
			throw new InvalidArgumentException('Retaining a record moves its archiefactiedatum, so a new date is required');
		}

		if (date_create_immutable($newDate) === false) {
			throw new InvalidArgumentException(
				sprintf('"%s" is not a date the archiefactiedatum can be moved to', $newDate)
			);
		}
	}//end guardAnswer()

	/**
	 * Refuse an answer from anybody but the person accountable for the entry.
	 *
	 * 🔴 AN UNASSIGNED ENTRY REFUSES RATHER THAN ACCEPTING ANY ARCHIVIST. The
	 * point of a named reviewer is that the record says who decided; letting the
	 * first passer-by answer an entry nobody was made accountable for gives back
	 * exactly the group-addressed approval this change replaces.
	 *
	 * @param array<string, mixed> $entry     The entry.
	 * @param string               $entryUuid The uuid, for the message.
	 * @param string               $reviewer  The user id answering.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When nobody is accountable, somebody else is, or it is decided.
	 */
	private function guardReviewer(array $entry, string $entryUuid, string $reviewer): void {
		$assigned = $this->reviewerOf(entry: $entry);
		if ($assigned === null) {
			throw new InvalidArgumentException(
				sprintf('Entry %s has no reviewer; assign one before it can be answered', $entryUuid)
			);
		}

		if ($assigned !== $reviewer) {
			throw new InvalidArgumentException(
				sprintf('Entry %s is %s to answer, not %s', $entryUuid, $assigned, $reviewer)
			);
		}

		if (($entry['decision'] ?? null) !== null) {
			throw new InvalidArgumentException(
				sprintf(
					'Entry %s was already answered with "%s"; a review decision is recorded once',
					$entryUuid,
					(string)$entry['decision']
				)
			);
		}
	}//end guardReviewer()
}//end class
