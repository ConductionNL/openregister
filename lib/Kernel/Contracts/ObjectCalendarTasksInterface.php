<?php

/**
 * ObjectCalendarTasksInterface: creates, reads, updates and deletes the calendar tasks linked to an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Creates, reads, updates and deletes the calendar tasks linked to an object. Implemented by Service\TaskService.
 */
interface ObjectCalendarTasksInterface {

	/**
	 * Create a new CalDAV task linked to an OpenRegister object.
	 *
	 * Builds a VCALENDAR/VTODO string with X-OPENREGISTER-* properties and
	 * an RFC 9253 LINK property, then stores it in the user's calendar.
	 *
	 * @param int $registerId The register ID to link
	 * @param int $schemaId The schema ID to link
	 * @param string $objectUuid The object UUID to link
	 * @param string $objectTitle The object title for the LINK label
	 * @param array $data Task data: summary, description, priority, due, status
	 *
	 * A payload that carries an engine task identity is REFUSED: a projected
	 * VTODO is created only by the projection, so a VTODO carrying
	 * `X-OPENREGISTER-TASK` always corresponds to a task the engine
	 * authorized into existence.
	 *
	 * @return array|null The created task in JSON-friendly format, or null if the calendar data was not a VTODO
	 *
	 * @throws \Exception If no user is logged in or no calendar found
	 * @throws \OCA\OpenRegister\Exception\TaskAccessDeniedException If the payload attempts to set an engine task identity
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-tasks-on-objects-via-caldav-vtodo
	 */
	public function createTask(
		int $registerId,
		int $schemaId,
		string $objectUuid,
		string $objectTitle,
		array $data,
	): ?array;

	/**
	 * Get all tasks linked to a specific OpenRegister object.
	 *
	 * Loads all VTODOs from the user's calendars and filters by
	 * X-OPENREGISTER-OBJECT matching the given object UUID.
	 *
	 * @param string $objectUuid The UUID of the OpenRegister object
	 *
	 * @return array Array of task arrays in JSON-friendly format
	 *
	 * @throws \Exception If no user is logged in or no calendar found
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function getTasksForObject(string $objectUuid): array;

	/**
	 * Update an existing CalDAV task.
	 *
	 * Loads the existing VTODO, applies changes, and saves it back.
	 *
	 * For a PROJECTED VTODO (one carrying `X-OPENREGISTER-TASK`) the VTODO
	 * is not the store: the update is handed to the write-back gate as a
	 * REQUEST against the engine task, authorized like any other caller, and
	 * what is stored afterwards is the engine's own rendering. Without a
	 * gate the update is refused, never applied unchecked.
	 *
	 * @param string $calendarId The calendar ID containing the task
	 * @param string $taskUri The URI of the task to update
	 * @param array $data Fields to update: summary, description, priority, due, status
	 *
	 * @return array|null The updated task in JSON-friendly format, or null if calendar data was not a VTODO
	 *
	 * @throws \Exception If the task is not found or update fails
	 * @throws \OCA\OpenRegister\Exception\TaskAccessDeniedException When a projected VTODO's change is refused
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-10
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-task-compatibility-with-nextcloud-tasks-app
	 */
	public function updateTask(string $calendarId, string $taskUri, array $data): ?array;

	/**
	 * Delete a CalDAV task.
	 *
	 * Deleting a PROJECTED VTODO deletes the calendar entry and nothing else:
	 * the engine task keeps its state, and the projection is restored on the
	 * next reconciliation, because a task is not cancelled by removing the
	 * reminder of it. Nothing here reaches the engine.
	 *
	 * @param string $calendarId The calendar ID containing the task
	 * @param string $taskUri The URI of the task to delete
	 *
	 * @return void
	 *
	 * @throws \Exception If the task is not found or deletion fails
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-10
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-tasks-on-objects-via-caldav-vtodo
	 */
	public function deleteTask(string $calendarId, string $taskUri): void;
}//end interface
