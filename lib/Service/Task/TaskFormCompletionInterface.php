<?php

/**
 * TaskFormCompletionInterface: what callers outside the Service component ask of TaskFormCompletion.
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

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskFormRefusedException;
use OCA\OpenRegister\Exception\TaskSubjectWriteRefusedException;

/**
 * Completing a task with its form payload, as the task controller uses it.
 * Only the method that caller calls.
 */
interface TaskFormCompletionInterface {

	/**
	 * Complete a task, validating and writing its form payload.
	 *
	 * @param string               $uuid       The task uuid.
	 * @param string               $outcome    The completion outcome.
	 * @param string|null          $resultText Free-text result.
	 * @param string|null          $comment    Completion comment.
	 * @param array<string, mixed> $data       The declared field values; empty for a form-less completion.
	 * @param string|null          $actor      The completing identity.
	 *
	 * @return Task The completed task.
	 *
	 * @throws TaskFormRefusedException When the payload violates the form.
	 * @throws TaskSubjectWriteRefusedException When the subject's own validation or lifecycle refuses the write.
	 * @throws TaskAccessDeniedException When the task verb or the object write is not authorized.
	 */
	public function complete(
		string $uuid,
		string $outcome,
		?string $resultText,
		?string $comment,
		array $data,
		?string $actor
	): Task;
}//end interface
