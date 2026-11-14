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
	 * Takes a FormCompletionRequest (as `$request`, or positionally in place of `$uuid`, then the
	 * actor), the answer form (`uuid:`, `answer:`, `data:`, `actor:`), or the separate arguments
	 * of the published contract.
	 *
	 * @param string|FormCompletionRequest|null $uuid       The task uuid, or the completion request.
	 * @param string|null                       $outcome    The completion outcome; the actor when `$uuid` is a request.
	 * @param string|null                       $resultText Free-text result.
	 * @param string|null                       $comment    Completion comment.
	 * @param array<string, mixed>              $data       The declared field values; empty for a form-less completion.
	 * @param string|null                       $actor      The completing identity.
	 * @param TaskAnswer|null                   $answer     The completion outcome, result and comment.
	 * @param FormCompletionRequest|null        $request    The task uuid, the completion outcome, result and comment, and the
	 *                                                      declared field values (empty for a form-less completion).
	 *
	 * @return Task The completed task.
	 *
	 * @throws TaskFormRefusedException When the payload violates the form.
	 * @throws TaskSubjectWriteRefusedException When the subject's own validation or lifecycle refuses the write.
	 * @throws TaskAccessDeniedException When the task verb or the object write is not authorized.
	 */
	public function complete(
		string|FormCompletionRequest|null $uuid=null,
		?string $outcome=null,
		?string $resultText=null,
		?string $comment=null,
		array $data=[],
		?string $actor=null,
		?TaskAnswer $answer=null,
		?FormCompletionRequest $request=null,
	): Task;
}//end interface
