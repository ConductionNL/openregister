<?php

/**
 * The lifecycle verbs a caller may run on a task now.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/record-tasks-tab/specs/flow-tasks/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Kernel\Contracts\TaskInterface;

/**
 * Builds a task row's `can` list (record-tasks-tab design D-4).
 *
 * A verb is listed only when the very check its endpoint runs,
 * {@see TaskAuthorizationService::assertMay()}, passes for the caller, and the
 * task's state allows it: a terminal task lists nothing, `claim` and `offer`
 * need no assignee, `unclaim` and `delegate` need one. The list is advice for
 * the screen, never a grant: every verb's endpoint still asks the same service
 * when it runs.
 *
 * The service has no boolean twin of `assertMay()` on purpose, so this asks it
 * and catches the refusal; a refusal of any other kind is not caught, so an
 * authorization error cannot quietly turn into a missing button.
 *
 * @spec openspec/changes/record-tasks-tab/specs/flow-tasks/spec.md
 */
class TaskVerbList {

	/**
	 * The verbs with a route, in the order a screen lists them.
	 *
	 * @var array<int, string>
	 */
	public const VERBS = [
		'claim',
		'unclaim',
		'assign',
		'reassign',
		'delegate',
		'offer',
		'resolve',
		'complete',
		'cancel',
	];

	/**
	 * Verbs that only make sense on a task nobody holds.
	 *
	 * @var array<int, string>
	 */
	private const UNASSIGNED_ONLY = ['claim', 'offer'];

	/**
	 * Verbs that only make sense on a task somebody holds.
	 *
	 * @var array<int, string>
	 */
	private const ASSIGNED_ONLY = ['unclaim', 'delegate'];

	/**
	 * Constructor.
	 *
	 * @param TaskAuthorizationService $authorization The one authority on every verb.
	 */
	public function __construct(
		private readonly TaskAuthorizationService $authorization,
	) {
	}//end __construct()

	/**
	 * The verbs the caller may run on the task now, in {@see self::VERBS} order.
	 *
	 * @param TaskInterface $task   The task.
	 * @param string|null   $caller The caller's uid; null or '' lists nothing.
	 *
	 * @return array<int, string> The verbs.
	 *
	 * @spec openspec/changes/record-tasks-tab/specs/flow-tasks/spec.md
	 */
	public function for(TaskInterface $task, ?string $caller): array {
		if ($caller === null || trim($caller) === '' || ($task instanceof Task) === false || $task->isInTerminalState() === true) {
			return [];
		}

		$assigned = (trim((string)$task->getAssignee()) !== '');
		$verbs    = [];
		foreach (self::VERBS as $verb) {
			if ($this->stateAllows(verb: $verb, assigned: $assigned) === true && $this->may(verb: $verb, task: $task, caller: $caller) === true) {
				$verbs[] = $verb;
			}
		}

		return $verbs;
	}//end for()

	/**
	 * Whether the task's assignment allows the verb at all.
	 *
	 * @param string $verb     The verb.
	 * @param bool   $assigned Whether somebody holds the task.
	 *
	 * @return bool
	 */
	private function stateAllows(string $verb, bool $assigned): bool {
		if (in_array($verb, self::UNASSIGNED_ONLY, true) === true) {
			return $assigned === false;
		}

		if (in_array($verb, self::ASSIGNED_ONLY, true) === true) {
			return $assigned === true;
		}

		return true;
	}//end stateAllows()

	/**
	 * Whether the authorization service lets the caller run the verb.
	 *
	 * @param string $verb   The verb.
	 * @param Task   $task   The task.
	 * @param string $caller The caller's uid.
	 *
	 * @return bool
	 */
	private function may(string $verb, Task $task, string $caller): bool {
		try {
			$this->authorization->assertMay(act: new TaskAct(verb: $verb, actor: $caller), task: $task);
		} catch (TaskAccessDeniedException) {
			return false;
		}

		return true;
	}//end may()
}//end class
