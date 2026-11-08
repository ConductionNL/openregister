<?php

/**
 * OpenRegister Example Set Tasks
 *
 * The tasks an example set puts on its objects, such as the open and the
 * finished tasks on a demo case. A task lives in OpenRegister's task engine,
 * not in a register, so the set writes it through the task service's trusted
 * path (it may be born completed) and records its uuid. Remove deletes the
 * tasks the record holds. A task may name the run of a set's flow that waits
 * on it, the moment its assignee claimed it, and the moment it was created.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects
 * @spec openspec/changes/example-set-task-waiting-run-and-claim/specs/example-sets/spec.md#requirement-a-fragments-task-may-name-the-run-it-waits-in-and-carry-its-claim
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragments-task-may-carry-the-moment-it-was-created
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
use OCA\OpenRegister\Service\Task\TaskState;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks, writes, proves and deletes the tasks of an example set.
 *
 * The task state vocabulary is a static, published table (TaskState), read the way the task builder reads it.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) TaskState is the engine's static state vocabulary.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects
 */
class ExampleSetTasks extends ExampleSetEngineRows {
	/**
	 * The task fields a fragment may set. Everything else a task carries comes
	 * from the engine's own defaults.
	 */
	public const FIELDS = [
		'title',
		'description',
		'objectUuid',
		'state',
		'assignee',
		'candidateGroups',
		'priority',
		'dueAt',
		'expiresAt',
		'completedAt',
		'completedBy',
		'kind',
		'performerType',
		'uploadRules',
		'checklist',
		'taskKey',
		'organisation',
		'requester',
		'runUuid',
		'nodeId',
		'claimedBy',
		'claimedAt',
		'createdAt',
	];


	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the task service and mapper on first use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The fragment and record part tasks live in.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects
	 */
	public function part(): string {
		return 'tasks';
	}//end part()

	/**
	 * The word for one task, in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects
	 */
	public function noun(): string {
		return 'task';
	}//end noun()

	/**
	 * Why a filled task cannot be written, or ''.
	 *
	 * @param array<string, mixed> $data  The filled task fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects
	 */
	public function refusal(array $data, array $users): string {
		return $this->firstOf(
			reasons: [
				$this->fieldsRefusal(data: $data),
				$this->peopleRefusal(data: $data, users: $users),
				ExampleSetExternalTask::refusal(data: $data),
				$this->stateRefusal(data: $data),
				ExampleSetTaskDates::refusal(data: $data),
				ExampleSetTaskChecklist::refusal(data: $data),
			]
		);
	}//end refusal()

	/**
	 * Why a filled task cannot be written, or '': refusal(), then the run it names and its claim.
	 *
	 * @param array<string, mixed> $data    The filled task fields.
	 * @param array<int, string>   $users   The account ids the set creates or adopts.
	 * @param array<string, mixed> $context The fill context, with the fragment's flows.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-task-waiting-run-and-claim/specs/example-sets/spec.md#requirement-a-fragments-task-may-name-the-run-it-waits-in-and-carry-its-claim
	 */
	protected function refusalIn(array $data, array $users, array $context): string {
		return $this->firstOf(
			reasons: [
				$this->refusal(data: $data, users: $users),
				(new ExampleSetFlowRuns(container: $this->container))->taskRefusal(data: $data, context: $context),
				(new ExampleSetTaskClaim(container: $this->container))->refusal(
					data: $data,
					moment: ExampleSetTaskDates::moment(value: ($data['claimedAt'] ?? null))
				),
			]
		);
	}//end refusalIn()

	/**
	 * The first reason that is not '', or ''.
	 *
	 * @param array<int, string> $reasons The reasons, in the order they are asked.
	 *
	 * @return string
	 */
	private function firstOf(array $reasons): string {
		foreach ($reasons as $why) {
			if ($why !== '') {
				return $why;
			}
		}

		return '';
	}//end firstOf()


	/**
	 * Write a task through the engine's trusted path.
	 *
	 * @param string               $app  The app that ships the fragment.
	 * @param string               $key  The task's key in the fragment.
	 * @param array<string, mixed> $data The filled task fields.
	 *
	 * @return array{error: string, entry: array{uuid: string}|null} The record entry, or why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects
	 */
	public function write(string $app, string $key, array $data): array {
		$fields = $this->fields(app: $app, key: $key, data: $data);

		// The task key is unique in the engine: a task that already holds it (the record lost track of it) is
		// adopted, not written a second time.
		$holder = $this->holderOf(key: (string) $fields['key']);
		if ($holder !== null) {
			return ['error' => '', 'entry' => ['uuid' => $holder]];
		}

		$actor = $this->actor(app: $app, data: $data);
		try {
			$task = $this->import(fields: $fields, actor: $actor, data: $data);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['uuid' => (string) $task->getUuid()]];
	}//end write()

	/**
	 * The fields the engine takes: the declared ones but those the set handles itself, keyed by the declared task
	 * key or the row's own, with the app and an external task's upload rules.
	 *
	 * @param string               $app  The app that ships the fragment.
	 * @param string               $key  The task's key in the fragment.
	 * @param array<string, mixed> $data The filled task fields.
	 *
	 * @return array<string, mixed>
	 */
	private function fields(string $app, string $key, array $data): array {
		$fields = array_diff_key($data, array_flip(['completedAt', 'completedBy', 'uploadRules', 'taskKey', 'claimedBy', 'claimedAt', 'createdAt']));
		// An app that reads its tasks by engine key, such as a tenant's onboarding steps, names that key.
		$fields['key']   = (string) ($data['taskKey'] ?? 'example-set:'.$app.'/'.$key);
		$fields['appId'] = $app;

		return $fields + ExampleSetExternalTask::metadata(data: $data);
	}//end fields()

	/**
	 * Who raises the task: who finished it, else its assignee; the app that ships the set when there is neither, or
	 * when the task is a party's (a party is not an account).
	 *
	 * @param string               $app  The app that ships the fragment.
	 * @param array<string, mixed> $data The filled task fields.
	 *
	 * @return string
	 */
	private function actor(string $app, array $data): string {
		$actor = (string) ($data['completedBy'] ?? $data['assignee'] ?? '');
		if ($actor === '' || ExampleSetExternalTask::isExternal(data: $data) === true) {
			return $app;
		}

		return $actor;
	}//end actor()

	/**
	 * Import the task through the engine's trusted path, mark it completed when it is finished, and let it wait in
	 * its run with its claim.
	 *
	 * @param array<string, mixed> $fields The fields the engine takes.
	 * @param string               $actor  Who raises the task.
	 * @param array<string, mixed> $data   The filled task fields.
	 *
	 * @return Task
	 */
	private function import(array $fields, string $actor, array $data): Task {
		$task = $this->service()->import(data: $fields, actor: $actor, createdAt: ExampleSetTaskDates::moment(value: ($data['createdAt'] ?? null)));
		$this->complete(task: $task, data: $data);
		$this->waitAndClaim(task: $task, data: $data);

		return $task;
	}//end import()

	/**
	 * Mark a finished task completed when and by whom the row says.
	 *
	 * @param Task                 $task The imported task.
	 * @param array<string, mixed> $data The filled task fields.
	 *
	 * @return void
	 */
	private function complete(Task $task, array $data): void {
		if ($task->getIsTerminal() === true && isset($data['completedAt']) === true) {
			$task->setCompletedAt(ExampleSetTaskDates::moment(value: $data['completedAt']));
			$task->setCompletedBy((string) ($data['completedBy'] ?? $data['assignee'] ?? ''));
			$this->mapper()->update($task);
		}
	}//end complete()

	/**
	 * Put a new task in the slot of the run that waits on it, and append its claim to its audit.
	 *
	 * @param Task                 $task The written task.
	 * @param array<string, mixed> $data The filled task fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-task-waiting-run-and-claim/specs/example-sets/spec.md#requirement-a-fragments-task-may-name-the-run-it-waits-in-and-carry-its-claim
	 */
	private function waitAndClaim(Task $task, array $data): void {
		(new ExampleSetFlowRuns(container: $this->container))->fill(
			runUuid: (string) ($data['runUuid'] ?? ''),
			nodeId: (string) ($data['nodeId'] ?? ''),
			taskUuid: (string) $task->getUuid()
		);
		(new ExampleSetTaskClaim(container: $this->container))->write(
			task: $task,
			data: $data,
			moment: ExampleSetTaskDates::moment(value: ($data['claimedAt'] ?? null))
		);
	}//end waitAndClaim()

	/**
	 * Whether a written task is no longer in the engine.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects
	 */
	public function lost(array $entry): bool {
		try {
			$this->mapper()->findByUuid((string) $entry['uuid']);
		} catch (Throwable) {
			return true;
		}

		return false;
	}//end lost()

	/**
	 * Delete a task the set wrote.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return string `removed` (also when it was already gone) or `failed`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-the-tasks-a-set-wrote
	 */
	public function revert(array $entry): string {
		try {
			$task = $this->mapper()->findByUuid((string) $entry['uuid']);
		} catch (Throwable) {
			return 'removed';
		}

		try {
			$this->mapper()->delete($task);
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end revert()

	/**
	 * A field outside FIELDS, or a missing title or object.
	 *
	 * @param array<string, mixed> $data The filled task fields.
	 *
	 * @return string
	 */
	private function fieldsRefusal(array $data): string {
		$unknown = array_diff(array_keys($data), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not a task field a set may write';
		}

		return $this->neededRefusal(data: $data);
	}//end fieldsRefusal()

	/**
	 * Why a task lacks its title or the object it is on, or ''.
	 *
	 * @param array<string, mixed> $data The filled task fields.
	 *
	 * @return string
	 */
	private function neededRefusal(array $data): string {
		if (is_string($data['title'] ?? null) === false || trim($data['title']) === '') {
			return 'a task needs a title';
		}

		if (is_string($data['objectUuid'] ?? null) === false || trim($data['objectUuid']) === '') {
			return 'a task needs the object it is on (objectUuid)';
		}

		return '';
	}//end neededRefusal()

	/**
	 * An assignee, completer or requester that is not one of the set's own accounts.
	 *
	 * @param array<string, mixed> $data  The filled task fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-task-its-requester-and-expiry
	 */
	private function peopleRefusal(array $data, array $users): string {
		$fields = ['assignee', 'completedBy', 'requester', 'claimedBy'];
		if (ExampleSetExternalTask::isExternal(data: $data) === true) {
			// A party is checked by externalRefusal(), never against the set's accounts.
			$fields = ['completedBy', 'requester', 'claimedBy'];
		}

		foreach ($fields as $field) {
			$uid = (string) ($data[$field] ?? '');
			if ($uid !== '' && in_array($uid, $users, true) === false) {
				return $field.' "'.$uid.'" is not an account the set creates or adopts';
			}
		}

		return '';
	}//end peopleRefusal()

	/**
	 * A state the task engine does not map.
	 *
	 * @param array<string, mixed> $data The filled task fields.
	 *
	 * @return string
	 */
	private function stateRefusal(array $data): string {
		try {
			TaskState::normalise(value: (string) ($data['state'] ?? Task::STATE_AVAILABLE));
		} catch (Throwable $e) {
			return $e->getMessage();
		}

		return '';
	}//end stateRefusal()

	/**
	 * The uuid of the task that holds a key, null when none does or it cannot be read.
	 *
	 * @param string $key The task key.
	 *
	 * @return string|null
	 */
	private function holderOf(string $key): ?string {
		try {
			$held = $this->mapper()->findByKey($key);
		} catch (Throwable) {
			return null;
		}

		if ($held === []) {
			return null;
		}

		return (string) $held[0]->getUuid();
	}//end holderOf()

	/**
	 * The task service.
	 *
	 * @return TaskServiceInterface
	 */
	private function service(): TaskServiceInterface {
		return $this->container->get(TaskServiceInterface::class);
	}//end service()

	/**
	 * The task mapper.
	 *
	 * @return TaskMapper
	 */
	private function mapper(): TaskMapper {
		return $this->container->get(TaskMapper::class);
	}//end mapper()
}//end class
