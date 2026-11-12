<?php

/**
 * OpenRegister task leaf guard
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\TaskInterface;
use OCA\OpenRegister\Kernel\Contracts\TaskManagerInterface;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUserSession;
use Throwable;

/**
 * The one guard the task-anchored leaves (notes, calendar events) share.
 *
 * Both leaves answer an absent, an invisible and an undeterminable task with
 * the SAME 404 body, so the leaf cannot confirm that a guessed uuid names a
 * real task. Keeping that decision in one place is what keeps the two leaves
 * from ever answering differently.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class TaskLeafGuard {

	/**
	 * The body every refusal carries, whether the task is absent or unreadable.
	 *
	 * One constant rather than a literal per verb, because the two cases
	 * MUST stay indistinguishable on the wire: the day one verb answers
	 * 'Task not found' and another answers 'Forbidden', the difference
	 * between them is the existence oracle the leaves exist to deny.
	 *
	 * @var array<string, string>
	 */
	public const NOT_FOUND = ['error' => 'No such task'];

	/**
	 * Bundle the guard's collaborators.
	 *
	 * @param TaskManagerInterface     $tasks         Resolves a task by uuid.
	 * @param TaskAuthorizationService $authorization Read-visibility decisions.
	 * @param IUserSession             $userSession   Names the acting identity.
	 */
	public function __construct(
		public readonly TaskManagerInterface $tasks,
		public readonly TaskAuthorizationService $authorization,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Run the action on the readable task, or answer the shared 404.
	 *
	 * The guard runs BEFORE the action, so a refused caller never reaches
	 * the storage behind the leaf.
	 *
	 * @param string   $uuid   The task uuid.
	 * @param callable $action Receives the readable TaskInterface, returns the response.
	 *
	 * @return JSONResponse The action's response, or the 404.
	 */
	public function withReadableTask(string $uuid, callable $action): JSONResponse {
		$task = $this->readableTask(uuid: $uuid);
		if ($task === null) {
			return self::notFound();
		}

		return $action($task);
	}//end withReadableTask()

	/**
	 * The shared 404 response.
	 *
	 * @return JSONResponse The refusal.
	 */
	public static function notFound(): JSONResponse {
		return new JSONResponse(self::NOT_FOUND, Http::STATUS_NOT_FOUND);
	}//end notFound()

	/**
	 * The task this request may act on, or null.
	 *
	 * Fail-closed on every path: absent, invisible and undeterminable all
	 * return null. A `Throwable` from the mapper is caught rather than
	 * propagated because a lookup that could not answer must not be READ as
	 * a permission, and because letting it out would put the mapper's
	 * message (which for a database failure carries SQL and bound
	 * parameters) on the wire.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return TaskInterface|null The readable task, or null.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function readableTask(string $uuid): ?TaskInterface {
		try {
			$task = $this->tasks->get(uuid: $uuid);
		} catch (Throwable) {
			return null;
		}

		if ($this->authorization->mayRead(task: $task, uid: $this->uid()) === false) {
			return null;
		}

		return $task;
	}//end readableTask()

	/**
	 * The acting identity, or null without a session.
	 *
	 * @return string|null The uid.
	 */
	private function uid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end uid()
}//end class
