<?php

/**
 * WorkingCalendarWriteGuardInterface: WorkingCalendarWriteGuard as the working-calendar validation and delete-guard listeners reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * WorkingCalendarWriteGuard, typed for callers outside the Service component.
 *
 * Its outside callers, the working-calendar validation and delete-guard listeners,
 * call isWorkingCalendar(), assertValid(), deletionBlockers() and blockedMessage(),
 * so those are the whole contract. Binding it in ContractBindings lets those callers resolve
 * WorkingCalendarWriteGuard without naming the class.
 */
interface WorkingCalendarWriteGuardInterface {
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
	public function isWorkingCalendar(ObjectEntity $object): bool;

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
	public function assertValid(array $definition, ?string $fallbackSlug = null): void;

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
	public function deletionBlockers(string $calendarSlug): array;

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
	public function blockedMessage(string $calendarSlug, array $blockers): string;
}//end interface
