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
	 * Takes a FormCompletionRequest in main's position (`(request, actor)`), the answer form
	 * (`uuid:`, `answer:`, `data:`, `actor:`), or the separate arguments of the published contract,
	 * by name or by position (a uuid string in the first slot). A request wins over an answer, an
	 * answer over the outcome.
	 *
	 * @param FormCompletionRequest|string|null $request         The task uuid, answer and declared field values; a string here
	 *                                                           is the release's positional `$uuid`.
	 * @param string|null                       $actor           The completing identity; the release's positional `$outcome`
	 *                                                           when the first slot is a uuid.
	 * @param string|null                       $resultText      Free-text result.
	 * @param string|null                       $comment         Completion comment.
	 * @param array<string, mixed>              $data            The declared field values; empty for a form-less completion.
	 * @param string|null                       $positionalActor The release's positional `$actor`.
	 * @param TaskAnswer|null                   $answer          The completion outcome, result and comment.
	 * @param string|null                       $uuid            The task uuid.
	 * @param string|null                       $outcome         The completion outcome.
	 *
	 * @return Task The completed task.
	 *
	 * @throws TaskFormRefusedException When the payload violates the form.
	 * @throws TaskSubjectWriteRefusedException When the subject's own validation or lifecycle refuses the write.
	 * @throws TaskAccessDeniedException When the task verb or the object write is not authorized.
	 *
	 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
	 */
	public function complete(
		FormCompletionRequest|string|null $request=null,
		?string $actor=null,
		?string $resultText=null,
		?string $comment=null,
		array $data=[],
		?string $positionalActor=null,
		?TaskAnswer $answer=null,
		?string $uuid=null,
		?string $outcome=null,
	): Task;
}//end interface
