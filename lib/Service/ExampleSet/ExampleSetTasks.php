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
class ExampleSetTasks {
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
		'completedAt',
		'completedBy',
		'kind',
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
			$this->stateRefusal(data: $data),
			$this->datesRefusal(data: $data),
		];
		foreach ($reasons as $why) {
			if ($why !== '') {
				return $why;
			}
		}

		return '';
	}//end refusal()

	/**
	 * Check the tasks of one fragment: each fills and passes refusal().
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  The context placeholders draw on.
	 * @param array<int, string>   $users    The account ids the set creates or adopts.
	 * @param ExampleSetValues     $values   Fills placeholders.
	 *
	 * @return array<int, string> Refusals.
	 *
	 * @spec openspec/changes/example-set-tasks/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects
	 */
	public function checkFragment(array $fragment, array $context, array $users, ExampleSetValues $values): array {
		$refusals = [];
		foreach (($fragment['tasks'] ?? []) as $entry) {
			$gaps = [];
			$data = (array) $values->fill(value: $entry['data'], context: $context, gaps: $gaps);
			$why  = $this->refusal(data: $data, users: $users);
			if ($gaps !== []) {
				$why = 'cannot resolve '.implode(', ', array_unique($gaps));
			}

			if ($why !== '') {
				$refusals[] = $fragment['file'].': task "'.$entry['key'].'": '.$why;
			}
		}

		return $refusals;
	}//end checkFragment()

	/**
	 * Write the tasks of one fragment that the record does not hold.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  The context placeholders draw on.
	 * @param array<string, mixed> $report   The report; gains `tasks` and `dropped`.
	 * @param array<string, mixed> $record   The record; gains the tasks written.
	 * @param ExampleSetValues     $values   Fills placeholders.
	 *
	 * @return bool Whether the record changed and is to be written.
	 *
	 * @spec openspec/changes/example-set-tasks/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects
	 */
	public function installFragment(array $fragment, array $context, array &$report, array &$record, ExampleSetValues $values): bool {
		$changed = false;
		foreach (($fragment['tasks'] ?? []) as $entry) {
			$name = $fragment['app'].'/'.$entry['key'];
			$report['tasks'][$name] = 'kept';
			if (isset($record['tasks'][$name]) === true) {
				continue;
			}

			$gaps = [];
			$data = (array) $values->fill(value: $entry['data'], context: $context, gaps: $gaps);
			$made = ['error' => $this->refusal(data: $data, users: array_keys($record['users'])), 'entry' => null];
			if ($gaps !== []) {
				$made['error'] = 'cannot resolve '.implode(', ', array_unique($gaps));
			}

			if ($made['error'] === '') {
				$made = $this->apply(app: $fragment['app'], key: $entry['key'], data: $data);
			}

			if ($made['entry'] === null) {
				$report['dropped'][]    = $fragment['file'].': task "'.$entry['key'].'": '.$made['error'];
				$report['tasks'][$name] = 'failed';
				continue;
			}

			$record['tasks'][$name] = $made['entry'];
			$report['tasks'][$name] = 'created';
			$changed = true;
		}//end foreach

		return $changed;
	}//end installFragment()

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
	public function apply(string $app, string $key, array $data): array {
		$fields = array_diff_key($data, array_flip(['completedAt', 'completedBy']));
		$fields['key']   = 'example-set:'.$app.'/'.$key;
		$fields['appId'] = $app;
		$actor = (string) ($data['completedBy'] ?? $data['assignee'] ?? '');
		if ($actor === '') {
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
	}//end apply()

	/**
	 * Whether a written task is no longer in the engine.
	 *
	 * @param array<string, mixed> $entry A record entry from apply().
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
	 * @param array<string, mixed> $entry A record entry from apply().
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
	 * An assignee or completer that is not one of the set's own accounts.
	 *
	 * @param array<string, mixed> $data  The filled task fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return string
	 */
	private function peopleRefusal(array $data, array $users): string {
		foreach (['assignee', 'completedBy'] as $field) {
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
	 * A due or completion date that is not a date.
	 *
	 * @param array<string, mixed> $data The filled task fields.
	 *
	 * @return string
	 */
	private function datesRefusal(array $data): string {
		foreach (['dueAt', 'completedAt'] as $field) {
			if (isset($data[$field]) === true && $this->moment(value: $data[$field]) === null) {
				return $field.' "'.(string) $data[$field].'" is not a date';
			}
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
