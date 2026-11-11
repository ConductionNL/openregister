<?php

/**
 * Reads which task a calendar document projects.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

/**
 * The read of a projected VTODO that callers outside the Service component need.
 *
 * Implemented by TaskCalendarDocument; the calendar write-back listener asks this
 * instead of naming the class.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md
 */
interface TaskCalendarDocumentInterface {

	/**
	 * The task a calendar document projects, or null when it projects none.
	 *
	 * @param string $calendarData The calendar document.
	 *
	 * @return string|null The task uuid.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md
	 */
	public function taskUuidOf(string $calendarData): ?string;
}//end interface
