<?php

/**
 * One rendered projection on its way into one calendar.
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
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-projection-is-idempotent-and-does-not-feed-itself
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;

/**
 * The task, its target calendar, the rendered document and its content hash.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-projection-is-idempotent-and-does-not-feed-itself
 */
final class TaskCalendarWrite {
	/**
	 * Constructor.
	 *
	 * @param Task $task The task being projected.
	 * @param int $calendarId The assignee's VTODO-capable calendar.
	 * @param string $rendered The VCALENDAR document.
	 * @param string $hash The document's engine-owned content hash.
	 */
	public function __construct(
		public readonly Task $task,
		public readonly int $calendarId,
		public readonly string $rendered,
		public readonly string $hash,
	) {
	}//end __construct()
}//end class
