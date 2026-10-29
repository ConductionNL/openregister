<?php

/**
 * OpenRegister Example Set Tasks
 *
 * The tasks an example set puts on its objects, such as the open and the
 * finished tasks on a demo case. A task lives in OpenRegister's task engine,
 * not in a register, so the set writes it through the task service's trusted
 * path (it may be born completed) and records its uuid. Remove deletes the
 * tasks the record holds.
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
 * @spec openspec/changes/example-set-tasks/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskService;
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
 * @spec openspec/changes/example-set-tasks/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects
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
	 * @spec openspec/changes/example-set-tasks/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects
	 */
	public function part(): string {
		return 'tasks';
	}//end part()

	/**
	 * The word for one task, in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-tasks/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects
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
	 * @spec openspec/changes/example-set-tasks/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects
	 */
	public function refusal(array $data, array $users): string {
		$reasons = [
			$this->fieldsRefusal(data: $data),
			$this->peopleRefusal(data: $data, users: $users),
			ExampleSetExternalTask::refusal(data: $data),
			$this->stateRefusal(data: $data),
			$this->datesRefusal(data: $data),
			ExampleSetTaskChecklist::refusal(data: $data),
		];
		foreach ($reasons as $why) {
			if ($why !== '') {
				return $why;
			}
		}

		return '';
	}//end refusal()

	/**
	 * Write a task through the engine's trusted path.
	 *
	 * @param string               $app  The app that ships the fragment.
	 * @param string               $key  The task's key in the fragment.
	 * @param array<string, mixed> $data The filled task fields.
	 *
	 * @return array{error: string, entry: array{uuid: string}|null} The record entry, or why the write failed.
	 *
	 * @spec openspec/changes/example-set-tasks/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects
	 */
	public function write(string $app, string $key, array $data): array {
		$fields = array_diff_key($data, array_flip(['completedAt', 'completedBy', 'uploadRules', 'taskKey']));
		// An app that reads its tasks by engine key, such as a tenant's onboarding steps, names that key.
		$fields['key'] = (string) ($data['taskKey'] ?? 'example-set:'.$app.'/'.$key);
		$fields['appId'] = $app;
		$fields += ExampleSetExternalTask::metadata(data: $data);

		// The task key is unique in the engine: a task that already holds it (the record lost track of it) is
		// adopted, not written a second time.
		$holder = $this->holderOf(key: (string) $fields['key']);
		if ($holder !== null) {
			return ['error' => '', 'entry' => ['uuid' => $holder]];
		}

		$actor = (string) ($data['completedBy'] ?? $data['assignee'] ?? '');
		if ($actor === '' || ExampleSetExternalTask::isExternal(data: $data) === true) {
			// A party is not an account: the app that ships the set raises its task.
			$actor = $app;
		}

		try {
			$task = $this->service()->import(data: $fields, actor: $actor);
			if ($task->getIsTerminal() === true && isset($data['completedAt']) === true) {
				$task->setCompletedAt($this->moment(value: $data['completedAt']));
				$task->setCompletedBy((string) ($data['completedBy'] ?? $data['assignee'] ?? ''));
				$this->mapper()->update($task);
			}
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['uuid' => (string) $task->getUuid()]];
	}//end write()

	/**
	 * Whether a written task is no longer in the engine.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-set-tasks/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects
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
	 * @spec openspec/changes/example-set-tasks/specs/example-sets/spec.md#requirement-remove-deletes-the-tasks-a-set-wrote
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

		if (is_string($data['title'] ?? null) === false || trim($data['title']) === '') {
			return 'a task needs a title';
		}

		if (is_string($data['objectUuid'] ?? null) === false || trim($data['objectUuid']) === '') {
			return 'a task needs the object it is on (objectUuid)';
		}

		return '';
	}//end fieldsRefusal()

	/**
	 * An assignee, completer or requester that is not one of the set's own accounts.
	 *
	 * @param array<string, mixed> $data  The filled task fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-task-requester-and-expiry/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-task-its-requester-and-expiry
	 */
	private function peopleRefusal(array $data, array $users): string {
		$fields = ['assignee', 'completedBy', 'requester'];
		if (ExampleSetExternalTask::isExternal(data: $data) === true) {
			// A party is checked by externalRefusal(), never against the set's accounts.
			$fields = ['completedBy', 'requester'];
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
	 * A due, expiry or completion date that is not a date, or an expiry before the due date.
	 *
	 * @param array<string, mixed> $data The filled task fields.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-task-requester-and-expiry/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-task-its-requester-and-expiry
	 */
	private function datesRefusal(array $data): string {
		foreach (['dueAt', 'expiresAt', 'completedAt'] as $field) {
			if (isset($data[$field]) === true && $this->moment(value: $data[$field]) === null) {
				return $field.' "'.(string) $data[$field].'" is not a date';
			}
		}

		if (isset($data['dueAt'], $data['expiresAt']) === true
			&& $this->moment(value: $data['expiresAt']) < $this->moment(value: $data['dueAt'])
		) {
			return 'expiresAt lies before dueAt: a task that dies before it is due is a configuration error';
		}

		return '';
	}//end datesRefusal()

	/**
	 * A date or date-time value as a moment, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTime|null
	 */
	private function moment(mixed $value): ?DateTime {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTime($value);
		} catch (Throwable) {
			return null;
		}
	}//end moment()

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
	 * @return TaskService
	 */
	private function service(): TaskService {
		return $this->container->get(TaskService::class);
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
