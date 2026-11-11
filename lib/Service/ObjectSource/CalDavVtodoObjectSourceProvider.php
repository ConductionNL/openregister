<?php

/**
 * CalDavVtodoObjectSourceProvider — serves a schema's objects live from the
 * acting user's CalDAV VTODOs (read-only).
 *
 * The authoritative record is the VTODO; this provider projects each VTODO as a
 * virtual ObjectEntity (mapping SUMMARY→title, DESCRIPTION→description,
 * DUE→dueDate, STATUS→status, plus the X-OPENREGISTER-* link metadata) and never
 * writes back. Reuses the existing TaskService CalDAV plumbing, which is already
 * scoped to the logged-in user's calendars — so another user's tasks are simply
 * absent (denied == not-found, no enumeration oracle).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\UserTaskListInterface;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only object-source provider backed by CalDAV VTODOs.
 */
class CalDavVtodoObjectSourceProvider implements ObjectSourceProvider {

	use AnyAppInstalledCheck;
	use CountsThroughFindAll;

	/**
	 * Apps whose presence enables CalDAV VTODO reads. VTODOs live in the
	 * user's CalDAV calendars served by the CORE `dav` app — the
	 * `tasks`/`calendar` apps are only optional UIs, so `dav` comes first
	 * with tasks/calendar as positive signals too.
	 *
	 * @var list<string>
	 */
	private const ENABLING_APPS = ['dav', 'tasks', 'calendar'];

	/**
	 * The core object fields: field => [VTODO key, default when absent].
	 *
	 * @var array<string, array{0: string, 1: string|null}>
	 */
	private const CORE_FIELDS = [
		'title' => ['summary', ''],
		'description' => ['description', ''],
		'status' => ['status', ''],
		'dueDate' => ['due', null],
		'completed' => ['completed', null],
		'priority' => ['priority', null],
	];

	/**
	 * App availability checks.
	 *
	 * @var IAppManager
	 */
	private readonly IAppManager $appManager;

	/**
	 * Logger for read failures.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param UserTaskListInterface $taskService The CalDAV VTODO read/list service.
	 * @param OptionalAppBridge     $app         Whether the app is installed, its services, and the log of failed reads.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly UserTaskListInterface $taskService,
		OptionalAppBridge $app,
	) {
		$this->appManager = $app->appManager;
		$this->logger = $app->logger;
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The provider id.
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
	 */
	public function getId(): string {
		return 'caldav-vtodo';
	}//end getId()

	/**
	 * {@inheritDoc}
	 *
	 * @param SourcedTable $source The register, the sourced schema and its object-source `config` block.
	 * @param string $id The VTODO id (calendar-object uri) or uid.
	 *
	 * @return ObjectEntity|null The virtual object, or null when absent/denied.
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
	 */
	public function find(SourcedTable $source, string $id): ?ObjectEntity {
		$selection = VtodoSelection::fromConfig(binding: $source->binding(), config: $source->config);

		return $this->findTask(selection: $selection, id: $id);
	}//end find()

	/**
	 * Read the one in-scope task with this id.
	 *
	 * @param VtodoSelection $selection The binding and the scope that admits tasks.
	 * @param string         $id        The task UID / URI.
	 *
	 * @return ObjectEntity|null The task, or null when absent or out of scope.
	 */
	private function findTask(VtodoSelection $selection, string $id): ?ObjectEntity {
		foreach ($this->readTasks(query: ['limit' => 1000]) as $task) {
			if ($selection->scope->admits(task: $task) === true && $this->hasId(task: $task, id: $id) === true) {
				return $this->toObjectEntity(binding: $selection->binding, task: $task);
			}
		}

		return null;
	}//end findTask()

	/**
	 * {@inheritDoc}
	 *
	 * @param SourcedTable $source The register, the sourced schema and its object-source `config` block.
	 * @param array<string, mixed> $query Query (filters/limit/offset).
	 *
	 * @return ObjectEntity[] The matching virtual objects.
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
	 */
	public function findAll(SourcedTable $source, array $query = []): array {
		$selection = VtodoSelection::fromConfig(binding: $source->binding(), config: $source->config);

		return $this->listTasks(selection: $selection, query: $query);
	}//end findAll()

	/**
	 * Read the in-scope tasks of one page.
	 *
	 * @param VtodoSelection       $selection The binding and the scope that admits tasks.
	 * @param array<string, mixed> $query     Query (filters/limit/offset).
	 *
	 * @return ObjectEntity[] The matching tasks.
	 */
	private function listTasks(VtodoSelection $selection, array $query): array {
		$objects = [];
		foreach ($this->readTasks(query: $query) as $task) {
			if ($selection->scope->admits(task: $task) === true) {
				$objects[] = $this->toObjectEntity(binding: $selection->binding, task: $task);
			}
		}

		return $objects;
	}//end listTasks()

	/**
	 * Whether a VTODO answers to an id: its calendar-object uri or its uid.
	 *
	 * @param array<string, mixed> $task The VTODO task array.
	 * @param string               $id   The requested id.
	 *
	 * @return bool True when the task has that id.
	 */
	private function hasId(array $task, string $id): bool {
		return (string)($task['id'] ?? '') === $id || (string)($task['uid'] ?? '') === $id;
	}//end hasId()

	/**
	 * Read VTODO task arrays for the acting user, failing closed to an empty list.
	 *
	 * @param array<string, mixed> $query Query: `limit` (200), `offset` (0), `filters.status`.
	 *
	 * @return array<int, array<string, mixed>> The task arrays.
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
	 */
	private function readTasks(array $query): array {
		$status = $this->statusFilter(query: $query);
		$limit  = (int)($query['limit'] ?? 200);
		$offset = (int)($query['offset'] ?? 0);
		try {
			$result = $this->taskService->listUserTasks(status: $status, query: new ListQuery(limit: $limit, offset: $offset));
			return array_values($result['results']);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:caldav-vtodo] could not read VTODOs: ' . $e->getMessage());
			return [];
		}
	}//end readTasks()

	/**
	 * The query's status filter.
	 *
	 * @param array<string, mixed> $query The findAll() query.
	 *
	 * @return string|null The status, or null for every status.
	 */
	private function statusFilter(array $query): ?string {
		return ($query['filters']['status'] ?? null);
	}//end statusFilter()

	/**
	 * Map a VTODO task array onto a non-persisted virtual ObjectEntity.
	 *
	 * @param SourcedObjectBinding $binding The bound register and schema.
	 * @param array<string, mixed> $task    The TaskService VTODO array.
	 *
	 * @return ObjectEntity The virtual object (never saved).
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
	 */
	private function toObjectEntity(SourcedObjectBinding $binding, array $task): ObjectEntity {
		return $binding->toEntity(uuid: (string)($task['uid'] ?? $task['id'] ?? ''), data: $this->taskData(task: $task));
	}//end toObjectEntity()

	/**
	 * The object data of a VTODO: the core fields, then any non-core schema
	 * fields round-tripped via X-OPENREGISTER-DATA (e.g. assignee), so the
	 * projection is faithful to the bound schema.
	 *
	 * @param array<string, mixed> $task The TaskService VTODO array.
	 *
	 * @return array<string, mixed> The object data.
	 */
	private function taskData(array $task): array {
		$data = [];
		foreach (self::CORE_FIELDS as $field => [$key, $default]) {
			$data[$field] = ($task[$key] ?? $default);
		}

		if (empty($task['fields']) === false && is_array($task['fields']) === true) {
			$data = array_merge($data, $task['fields']);
		}

		return $data;
	}//end taskData()
}//end class
