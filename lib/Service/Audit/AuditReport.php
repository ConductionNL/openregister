<?php

/**
 * OpenRegister audit report
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
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

namespace OCA\OpenRegister\Service\Audit;

use DateTimeInterface;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailCriteria;
use OCA\OpenRegister\Db\AuditTrailPageQueryInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Throwable;

/**
 * Who created, read, changed or deleted which objects, and when, over a period.
 *
 * Reads the trail through the audit page query (the same filters and period the
 * audit page uses), newest first, and adds a summary: how many entries each action
 * has, and the objects with the most entries. The summary is counted from the very
 * rows the report lists, so the two can never disagree. Callers gate this to
 * administrators; object names are therefore read without RBAC or tenancy.
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-audit-report-generation
 */
final class AuditReport {
	/**
	 * The most entries one report lists; beyond it the report says it was cut.
	 *
	 * @var int
	 */
	public const MAX_ENTRIES = 50000;

	/**
	 * How many objects the "most active" list names.
	 *
	 * @var int
	 */
	public const MOST_ACTIVE_LIMIT = 10;

	/**
	 * The most distinct objects whose name is looked up; the rest keep an empty name.
	 *
	 * @var int
	 */
	public const MAX_NAME_LOOKUPS = 500;

	/**
	 * The column headings of an entry row, in order.
	 *
	 * @var array<int, string>
	 */
	public const COLUMNS = ['timestamp', 'action', 'objectUuid', 'objectName', 'userId', 'ipAddress', 'changes'];

	/**
	 * Constructor.
	 *
	 * @param AuditTrailPageQueryInterface $trail   The filtered, newest-first trail.
	 * @param ObjectOperationsInterface    $objects Reads an object's name.
	 */
	public function __construct(
		private readonly AuditTrailPageQueryInterface $trail,
		private readonly ObjectOperationsInterface $objects,
	) {
	}//end __construct()

	/**
	 * Build the report for a register, an actor and a period, each optional.
	 *
	 * @param int|null    $registerId The register id, or null for every register.
	 * @param string|null $userId     The actor, or null for everybody.
	 * @param string|null $from       The first day or moment, inclusive.
	 * @param string|null $to         The last day (whole day) or moment, inclusive.
	 *
	 * @return array<string, mixed> `entries` (rows keyed by COLUMNS), `summary` (`total`, `actions` with
	 *         the entries per action, `mostActiveObjects` with objectUuid, objectName and count) and
	 *         `truncated` (more entries matched than MAX_ENTRIES).
	 *
	 * @throws \InvalidArgumentException When `from` or `to` is not a date.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-audit-report-generation
	 */
	public function build(?int $registerId, ?string $userId, ?string $from, ?string $to): array {
		$register = null;
		if ($registerId !== null) {
			$register = (string)$registerId;
		}

		$filters = array_filter(
			[
				'register' => $register,
				'actor'    => $userId,
				'from'     => $from,
				'to'       => $to,
			],
			static fn (?string $value): bool => $value !== null && trim($value) !== ''
		);

		$collected = $this->trail->collect(criteria: new AuditTrailCriteria(filters: $filters), ceiling: self::MAX_ENTRIES);
		$entries = $this->newestFirst(entries: $collected['results']);
		$names = $this->objectNames(entries: $entries);

		$rows = [];
		foreach ($entries as $entry) {
			$rows[] = $this->row(entry: $entry, names: $names);
		}

		return [
			'entries'   => $rows,
			'summary'   => $this->summary(rows: $rows),
			'truncated' => $collected['truncated'],
		];
	}//end build()

	/**
	 * The entries sorted by their moment, newest first; equal moments keep the newest id first.
	 *
	 * @param array<int, AuditTrail> $entries The entries.
	 *
	 * @return array<int, AuditTrail>
	 */
	private function newestFirst(array $entries): array {
		usort(
			$entries,
			static function (AuditTrail $left, AuditTrail $right): int {
				$byMoment = (self::moment(entry: $right) <=> self::moment(entry: $left));
				if ($byMoment !== 0) {
					return $byMoment;
				}

				return ((int)$right->getId() <=> (int)$left->getId());
			}
		);

		return $entries;
	}//end newestFirst()

	/**
	 * An entry's moment as a sortable number, 0 without one.
	 *
	 * @param AuditTrail $entry The entry.
	 *
	 * @return int The Unix time.
	 */
	private static function moment(AuditTrail $entry): int {
		$created = $entry->getCreated();
		if ($created instanceof DateTimeInterface) {
			return $created->getTimestamp();
		}

		return 0;
	}//end moment()

	/**
	 * The names of the objects the entries name, the most active first, up to MAX_NAME_LOOKUPS.
	 *
	 * @param array<int, AuditTrail> $entries The entries.
	 *
	 * @return array<string, string> Name by object uuid; a gone object has an empty name.
	 */
	private function objectNames(array $entries): array {
		$counts = [];
		foreach ($entries as $entry) {
			$uuid = (string)$entry->getObjectUuid();
			if ($uuid !== '') {
				$counts[$uuid] = (($counts[$uuid] ?? 0) + 1);
			}
		}

		$counts = self::byCountThenKey(counts: $counts);
		$names = [];
		foreach (array_slice(array_keys($counts), 0, self::MAX_NAME_LOOKUPS) as $uuid) {
			$names[(string)$uuid] = $this->objectName(uuid: (string)$uuid);
		}

		return $names;
	}//end objectNames()

	/**
	 * One object's name; a deleted or unreadable object has none.
	 *
	 * @param string $uuid The object uuid.
	 *
	 * @return string The name, '' when there is none.
	 */
	private function objectName(string $uuid): string {
		try {
			$object = $this->objects->find(
				id: $uuid,
				options: new ObjectFindOptions(render: false, audit: false),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return '';
		}

		if ($object === null) {
			return '';
		}

		return (string)$object->getName();
	}//end objectName()

	/**
	 * One entry as a report row, keyed by COLUMNS.
	 *
	 * @param AuditTrail            $entry The entry.
	 * @param array<string, string> $names Object names by uuid.
	 *
	 * @return array<string, string>
	 */
	private function row(AuditTrail $entry, array $names): array {
		$created = $entry->getCreated();
		$uuid = (string)$entry->getObjectUuid();

		$timestamp = '';
		if ($created instanceof DateTimeInterface) {
			$timestamp = $created->format(DATE_ATOM);
		}

		return [
			'timestamp'  => $timestamp,
			'action'     => (string)$entry->getAction(),
			'objectUuid' => $uuid,
			'objectName' => ($names[$uuid] ?? ''),
			'userId'     => (string)$entry->getUser(),
			'ipAddress'  => (string)$entry->getIpAddress(),
			'changes'    => $this->changesSummary(changed: $entry->getChanged()),
		];
	}//end row()

	/**
	 * The fields an entry changed, by name only: the report says what changed, never the values.
	 *
	 * @param array<mixed>|null $changed The entry's change record.
	 *
	 * @return string The changed field names, comma separated.
	 */
	private function changesSummary(?array $changed): string {
		if ($changed === null || $changed === []) {
			return '';
		}

		$fields = array_filter(array_keys($changed), static fn ($key): bool => is_string($key) === true);

		return implode(', ', $fields);
	}//end changesSummary()

	/**
	 * The summary of the rows: their total, the entries per action and the most active objects.
	 *
	 * @param array<int, array<string, string>> $rows The report rows.
	 *
	 * @return array{total: int, actions: array<string, int>, mostActiveObjects: array<int, array{objectUuid: string, objectName: string, count: int}>}
	 */
	private function summary(array $rows): array {
		$actions = [];
		$objects = [];
		$named = [];
		foreach ($rows as $row) {
			$actions[$row['action']] = (($actions[$row['action']] ?? 0) + 1);
			if ($row['objectUuid'] !== '') {
				$objects[$row['objectUuid']] = (($objects[$row['objectUuid']] ?? 0) + 1);
				$named[$row['objectUuid']] = $row['objectName'];
			}
		}

		$actions = self::byCountThenKey(counts: $actions);
		$objects = self::byCountThenKey(counts: $objects);

		$mostActive = [];
		foreach (array_slice($objects, 0, self::MOST_ACTIVE_LIMIT, true) as $uuid => $count) {
			$mostActive[] = ['objectUuid' => (string)$uuid, 'objectName' => $named[$uuid], 'count' => $count];
		}

		return ['total' => count($rows), 'actions' => $actions, 'mostActiveObjects' => $mostActive];
	}//end summary()

	/**
	 * Counts ordered by count, highest first, and equal counts by key, so a report reads the same every time.
	 *
	 * @param array<string|int, int> $counts Counts by key.
	 *
	 * @return array<string, int>
	 */
	private static function byCountThenKey(array $counts): array {
		uksort(
			$counts,
			static function (string|int $left, string|int $right) use ($counts): int {
				$byCount = ($counts[$right] <=> $counts[$left]);
				if ($byCount !== 0) {
					return $byCount;
				}

				return strcmp((string)$left, (string)$right);
			}
		);

		return $counts;
	}//end byCountThenKey()
}//end class
