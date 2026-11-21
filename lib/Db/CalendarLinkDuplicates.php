<?php

/**
 * Which calendar link rows are duplicates of an older row for the same triple.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-linking-is-idempotent-req-cal-inb-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * A link row is identified by (object uuid, event uid, calendar uri). Of the
 * rows sharing a triple the oldest is kept, because its linked-at is the true
 * link time; the others are surplus. Oldest is the earliest linked-at, and the
 * lowest id breaks a tie (or decides alone when linked-at is missing), so the
 * choice is deterministic whatever order the rows arrive in.
 *
 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-linking-is-idempotent-req-cal-inb-003
 */
final class CalendarLinkDuplicates {

	/**
	 * The kept row per triple: triple key => [linkedAt, id].
	 *
	 * @var array<string, array{0: string, 1: int}>
	 */
	private array $kept = [];

	/**
	 * Every row id seen.
	 *
	 * @var array<int, true>
	 */
	private array $seen = [];

	/**
	 * Record one row.
	 *
	 * @param array<string, mixed> $row A row with id, object_uuid, event_uid, calendar_uri and linked_at.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-linking-is-idempotent-req-cal-inb-003
	 */
	public function add(array $row): void {
		$id = (int) ($row['id'] ?? 0);
		$this->seen[$id] = true;

		$key = implode(
			"\0",
			[
				(string) ($row['object_uuid'] ?? ''),
				(string) ($row['event_uid'] ?? ''),
				(string) ($row['calendar_uri'] ?? ''),
			]
		);
		$candidate = [(string) ($row['linked_at'] ?? ''), $id];

		if (isset($this->kept[$key]) === false || self::isOlder(candidate: $candidate, kept: $this->kept[$key]) === true) {
			$this->kept[$key] = $candidate;
		}
	}//end add()

	/**
	 * The ids of every row that is not the kept row of its triple, ascending.
	 *
	 * @return array<int, int>
	 *
	 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-linking-is-idempotent-req-cal-inb-003
	 */
	public function surplusIds(): array {
		$keep = [];
		foreach ($this->kept as $kept) {
			$keep[$kept[1]] = true;
		}

		$surplus = array_keys(array_diff_key($this->seen, $keep));
		sort($surplus);

		return $surplus;
	}//end surplusIds()

	/**
	 * Whether the candidate is older than the row kept so far.
	 *
	 * A missing linked-at sorts after any real one: a row whose link time is
	 * unknown cannot claim to be the original.
	 *
	 * @param array{0: string, 1: int} $candidate The candidate's [linkedAt, id].
	 * @param array{0: string, 1: int} $kept      The kept row's [linkedAt, id].
	 *
	 * @return bool
	 */
	private static function isOlder(array $candidate, array $kept): bool {
		if ($candidate[0] !== $kept[0]) {
			if ($candidate[0] === '' || $kept[0] === '') {
				return $kept[0] === '';
			}

			return strcmp($candidate[0], $kept[0]) < 0;
		}

		return $candidate[1] < $kept[1];
	}//end isOlder()
}//end class
