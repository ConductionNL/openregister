<?php

/**
 * TaskCalendarDocument: reads which task a calendar document projects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
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

namespace OCA\OpenRegister\Service\Task;

/**
 * The read of a projected VTODO that callers outside the Service component need.
 *
 * TaskCalendarProjector owns the VTODO format; this class answers the one
 * question the calendar write-back listener asks of a document, so that the
 * listener need not name the projector.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md
 */
final class TaskCalendarDocument implements TaskCalendarDocumentInterface {
	/**
	 * The task a calendar document projects, or null when it projects none.
	 *
	 * @param string $calendarData The calendar document.
	 *
	 * @return string|null The task uuid.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) TaskCalendarProjector::taskUuidOf is the
	 * stateless VTODO reader this class stands in front of.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md
	 */
	public function taskUuidOf(string $calendarData): ?string {
		return TaskCalendarProjector::taskUuidOf(calendarData: $calendarData);
	}//end taskUuidOf()
}//end class
