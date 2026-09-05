<?php

/**
 * Finds a user's first VTODO-capable calendar, for ANY user by uid.
 *
 * The older CalDAV leaf resolved the SESSION user's calendar; a projection
 * needs the ASSIGNEE's, because a task assigned by A to B belongs in B's
 * calendar (flow-task-inbox-projections, design D-5). Both callers share
 * this one lookup so the two cannot disagree on what "supports VTODO" means.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-calendar-selection-for-tasks
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\OpenRegister\Exception\NoVtodoCalendarException;

/**
 * Calendar selection by uid.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-calendar-selection-for-tasks
 */
class VtodoCalendarLocator {

	/**
	 * The CalDAV property naming a calendar's supported component set.
	 */
	private const COMPONENT_SET = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';

	/**
	 * Constructor.
	 *
	 * @param CalDavBackend $calDavBackend The calendar store.
	 */
	public function __construct(
		private readonly CalDavBackend $calDavBackend,
	) {

	}//end __construct()

	/**
	 * The user's first VTODO-supporting calendar.
	 *
	 * @param string $uid The calendar owner.
	 *
	 * @return array{id: int, uri: string} The calendar id and uri.
	 *
	 * @throws NoVtodoCalendarException When the user has no calendar accepting tasks.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-calendar-selection-for-tasks
	 */
	public function forUser(string $uid): array {
		$calendars = $this->calDavBackend->getCalendarsForUser('principals/users/' . $uid);

		foreach ($calendars as $calendar) {
			if ($this->supportsVtodo(components: ($calendar[self::COMPONENT_SET] ?? null)) === true) {
				return [
					'id' => (int)$calendar['id'],
					'uri' => (string)$calendar['uri'],
				];
			}
		}

		throw new NoVtodoCalendarException(userId: $uid);
	}//end forUser()

	/**
	 * Whether a supported-calendar-component-set value names VTODO.
	 *
	 * Handles the object, string and iterable shapes the backend has been
	 * observed to return.
	 *
	 * @param mixed $components The property value.
	 *
	 * @return bool True when VTODO is supported.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-calendar-selection-for-tasks
	 */
	public function supportsVtodo(mixed $components): bool {
		if (is_string($components) === true) {
			return stripos($components, 'VTODO') !== false;
		}

		if (is_object($components) === true && method_exists($components, 'getValue') === true) {
			$components = (array)$components->getValue();
		}

		if (is_iterable($components) === false) {
			return false;
		}

		return $this->namesVtodo(components: $components);
	}//end supportsVtodo()

	/**
	 * Whether an iterable of component names contains VTODO.
	 *
	 * @param iterable<mixed> $components The component names.
	 *
	 * @return bool True when one is VTODO, any case.
	 */
	private function namesVtodo(iterable $components): bool {
		foreach ($components as $component) {
			if (is_scalar($component) === true && strtoupper((string)$component) === 'VTODO') {
				return true;
			}
		}

		return false;
	}//end namesVtodo()
}//end class
