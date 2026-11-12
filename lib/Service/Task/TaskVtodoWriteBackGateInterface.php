<?php

/**
 * TaskVtodoWriteBackGateInterface: what callers outside the Service component ask of TaskVtodoWriteBackGate.
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

use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The one write path from a projected VTODO back to its task, as the DAV
 * write-back plugin and the write-back listener use it. Only the methods those
 * callers call.
 */
interface TaskVtodoWriteBackGateInterface {

	/**
	 * Whether a calendar document carries a task identity.
	 *
	 * @param string $calendarData The VCALENDAR document.
	 *
	 * @return bool True when it carries a task identity.
	 */
	public function isProjected(string $calendarData): bool;

	/**
	 * Apply an incoming VTODO write to its task.
	 *
	 * @param string      $calendarData The incoming VCALENDAR document.
	 * @param string|null $actor        The acting identity, or null when unknown (denied).
	 *
	 * @return string|null The document to store, or null to leave the write alone.
	 *
	 * @throws TaskAccessDeniedException When the actor may not perform the requested verb.
	 * @throws TaskConflictException When the status names no legal transition.
	 * @throws TaskValidationException When the verb refuses its arguments.
	 * @throws DoesNotExistException When the identity names no task.
	 */
	public function handleWrite(string $calendarData, ?string $actor): ?string;
}//end interface
