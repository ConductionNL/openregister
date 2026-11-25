<?php

/**
 * A timer's record type changed: the calendar it measures against may change with it.
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
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCA\OpenRegister\Db\FlowTimer;

/**
 * The calendar a timer takes from its new record type.
 *
 * Only a timer whose calendar was NAMED BY ITS RECORD TYPE follows the
 * object: a calendar written on the timer's own configuration, and the unit
 * and instance calendars, do not depend on the schema, so a move leaves
 * those timers alone. A timer that does follow switches to the target's
 * calendar; when the target names none it falls back to the unit or
 * instance calendar, exactly as a timer armed there would.
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
 */
final class TimerCalendarSwitch {

	/**
	 * Constructor.
	 *
	 * @param string|null $calendarSlug The target record type's calendar, or null when it names none.
	 * @param string      $recordType   The target schema.
	 */
	private function __construct(
		public readonly ?string $calendarSlug,
		public readonly string $recordType,
	) {
	}//end __construct()

	/**
	 * The switch this timer needs when its subject now has this record type, or null when it needs none.
	 *
	 * @param FlowTimer   $timer        The open timer.
	 * @param string|null $calendarSlug The calendar the target record type names, or null.
	 * @param string      $recordType   The target schema.
	 *
	 * @return self|null The switch; null when the timer's calendar was not named by its record type, or stays the same.
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public static function forTimer(FlowTimer $timer, ?string $calendarSlug, string $recordType): ?self {
		if (WorkingCalendarScope::ofTimer(timer: $timer)->namedBy !== WorkingCalendarScope::SCOPE_RECORD_TYPE) {
			return null;
		}

		$slug = $calendarSlug;
		if ($slug !== null && trim($slug) === '') {
			$slug = null;
		}

		if ($slug === $timer->getCalendarSlug()) {
			return null;
		}

		return new self(calendarSlug: $slug, recordType: $recordType);
	}//end forTimer()

	/**
	 * Point the timer at the new calendar and record which scope named it.
	 *
	 * With a calendar the scope stays `recordType`, naming the target; without
	 * one the record-type part of the scope is removed, so the timer resolves
	 * to its unit or the instance as if it had been armed on the target.
	 *
	 * @param FlowTimer $timer The timer to change; a new successor or a suspended timer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function applyTo(FlowTimer $timer): void {
		$timer->setCalendarSlug($this->calendarSlug);

		$metadata = ($timer->getMetadata() ?? []);
		$scope    = ($metadata[WorkingCalendarScope::METADATA_KEY] ?? []);
		if (is_array($scope) === false) {
			$scope = [];
		}

		unset($scope['scope'], $scope['recordType']);
		if ($this->calendarSlug !== null) {
			$scope['scope']      = WorkingCalendarScope::SCOPE_RECORD_TYPE;
			$scope['recordType'] = $this->recordType;
		}

		unset($metadata[WorkingCalendarScope::METADATA_KEY]);
		if ($scope !== []) {
			$metadata[WorkingCalendarScope::METADATA_KEY] = $scope;
		}

		$timer->setMetadata($metadata);
	}//end applyTo()
}//end class
