<?php

/**
 * One validator, three doors.
 *
 * `WorkingCalendar::fromArray()` has always been the validating constructor,
 * but it only ran at RESOLVE time, which is arm time. A calendar written
 * through the objects API, the admin page or a configuration import was
 * therefore accepted at write time and refused hours later, on a timer that
 * had nothing to do with the edit. This guard runs the same validator at
 * write time so the three doors refuse identically, with the validator's own
 * message.
 *
 * It also answers which timers a calendar cannot be deleted out from under
 * (design D-4): an armed or suspended timer that names the calendar would
 * make the next sweep throw for every one of them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-every-write-of-a-working-calendar-is-validated-the-same-way
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCA\OpenRegister\Db\FlowTimerMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use Throwable;

/**
 * Recognises a working-calendar write and answers what blocks it.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) WorkingCalendar::fromArray() is the
 * value object's validating named constructor, which is the whole point here.
 */
class WorkingCalendarWriteGuard {

	/**
	 * How many blocking timers the refusal names.
	 *
	 * @var integer
	 */
	public const BLOCKER_SAMPLE = 10;

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper $schemas Resolves the schema an object belongs to.
	 * @param FlowTimerMapper $timers Counts the timers that name a calendar.
	 */
	public function __construct(
		private readonly SchemaMapper $schemas,
		private readonly FlowTimerMapper $timers,
	) {

	}//end __construct()

	/**
	 * Whether this object is a working calendar.
	 *
	 * The schema is resolved rather than sniffed from the payload: a case
	 * object that happens to carry a `rules` key is not a calendar, and a
	 * calendar saved with every property missing still is one — which is
	 * exactly the write this guard has to refuse.
	 *
	 * @param ObjectEntity $object The object being written.
	 *
	 * @return boolean True when the object's schema is `working-calendar`.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-every-write-of-a-working-calendar-is-validated-the-same-way
	 */
	public function isWorkingCalendar(ObjectEntity $object): bool {
		$reference = $object->getSchema();
		if ($reference === null || $reference === '') {
			return false;
		}

		try {
			$schema = $this->schemas->find($reference, _rbac: false, _multitenancy: false);
		} catch (Throwable $unresolved) {
			return false;
		}

		return $schema->getSlug() === FlowTimerDefinitionStore::SCHEMA_CALENDAR;
	}//end isWorkingCalendar()

	/**
	 * Run the validating constructor over a definition.
	 *
	 * The stored `slug` is handed in separately because a PATCH may omit it
	 * while the object keeps it; validating without it would refuse a write
	 * that is perfectly valid, for a field the caller never touched.
	 *
	 * @param array<string, mixed> $definition The `working-calendar` object data.
	 * @param string|null $fallbackSlug The stored slug, when the payload omits one.
	 *
	 * @return void
	 *
	 * @throws \OCA\OpenRegister\Exception\FlowTimerValidationException On a refused definition.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-every-write-of-a-working-calendar-is-validated-the-same-way
	 */
	public function assertValid(array $definition, ?string $fallbackSlug = null): void {
		if (trim((string)($definition['slug'] ?? '')) === '' && $fallbackSlug !== null) {
			$definition['slug'] = $fallbackSlug;
		}

		WorkingCalendar::fromArray(definition: $definition);
	}//end assertValid()

	/**
	 * The open timers that keep a calendar alive.
	 *
	 * @param string $calendarSlug The calendar's slug.
	 *
	 * @return array{count: int, timers: array<int, string>} The number of armed
	 *         or suspended timers and up to ten of their uuids.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-only-an-administrator-writes-a-calendar-and-a-referenced-one-cannot-be-deleted
	 */
	public function deletionBlockers(string $calendarSlug): array {
		$count = $this->timers->countOpenByCalendarSlug(calendarSlug: $calendarSlug);
		if ($count === 0) {
			return ['count' => 0, 'timers' => []];
		}

		$uuids = [];
		foreach ($this->timers->findOpenByCalendarSlug(calendarSlug: $calendarSlug, limit: self::BLOCKER_SAMPLE) as $timer) {
			$uuids[] = (string)$timer->getUuid();
		}

		return ['count' => $count, 'timers' => $uuids];
	}//end deletionBlockers()

	/**
	 * The refusal an administrator reads when a calendar is still in use.
	 *
	 * @param string $calendarSlug The calendar's slug.
	 * @param array{count: int, timers: array<int, string>} $blockers The blockers.
	 *
	 * @return string The message, naming the count and the sample.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-only-an-administrator-writes-a-calendar-and-a-referenced-one-cannot-be-deleted
	 */
	public function blockedMessage(string $calendarSlug, array $blockers): string {
		return sprintf(
			"Working calendar '%s' is measured against by %d open timer(s) and cannot be deleted: %s.",
			$calendarSlug,
			$blockers['count'],
			implode(', ', $blockers['timers'])
		);
	}//end blockedMessage()
}//end class
