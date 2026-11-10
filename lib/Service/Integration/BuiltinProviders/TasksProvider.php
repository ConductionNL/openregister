<?php

/**
 * TasksProvider — wraps TaskService as an IntegrationProvider.
 *
 * Tasks ride NC Calendar's VTODO subsystem (link-table storage via
 * CalDAV). The provider is CRUD-capable; mutation methods delegate
 * to TaskService's existing APIs.
 *
 * Always available — CalDAV ships with NC core — so `requiredApp`
 * returns null and `isEnabled()` is hardcoded true.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\BuiltinProviders
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
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-14
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\BuiltinProviders;

use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Exception\NoVtodoCalendarException;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface;
use OCA\OpenRegister\Service\WriteCause;
use OCP\IL10N;
use RuntimeException;

/**
 * Tasks integration provider — delegates to TaskService.
 *
 * Task ids are CalDAV-shaped composites ("{calendarId}/{taskUri}");
 * we accept the composite as the `entityId` and split it lazily
 * inside the mutation methods so the provider's surface stays
 * uniform.
 */
class TasksProvider extends AbstractIntegrationProvider {
	/**
	 * Tasks service.
	 *
	 * @var ObjectCalendarTasksInterface
	 */
	private ObjectCalendarTasksInterface $taskService;

	/**
	 * Register lookup (slug/uuid/id → entity).
	 *
	 * @var RegisterMapperInterface
	 */
	private RegisterMapperInterface $registerMapper;

	/**
	 * Schema lookup (slug/uuid/id → entity).
	 *
	 * @var SchemaMapperInterface
	 */
	private SchemaMapperInterface $schemaMapper;

	/**
	 * Object lookup (for the LINK label title).
	 *
	 * @var ObjectOperationsInterface
	 */
	private ObjectOperationsInterface $objectService;

	/**
	 * Constructor.
	 *
	 * @param TasksProviderServices $services The task, register, schema and object services.
	 * @param IL10N                 $l10n     Localisation.
	 *
	 * @return void
	 */
	public function __construct(
		TasksProviderServices $services,
		private IL10N $l10n,
	) {
		$this->taskService = $services->taskService;
		$this->registerMapper = $services->registerMapper;
		$this->schemaMapper = $services->schemaMapper;
		$this->objectService = $services->objectService;
	}//end __construct()

	/**
	 * Stable provider id used in routes and configs.
	 *
	 * @return string Stable provider identifier.
	 */
	public function getId(): string {
		return 'tasks';
	}//end getId()

	/**
	 * Translated, human-readable provider label.
	 *
	 * @return string Translated, human-readable provider label.
	 */
	public function getLabel(): string {
		return $this->l10n->t('Tasks');
	}//end getLabel()

	/**
	 * MDI icon name for the provider.
	 *
	 * @return string MDI icon name for the provider.
	 */
	public function getIcon(): string {
		return 'CheckboxMarkedOutline';
	}//end getIcon()

	/**
	 * Group identifier for UI grouping (or null).
	 *
	 * @return string|null Group identifier for UI grouping.
	 */
	public function getGroup(): ?string {
		return 'core';
	}//end getGroup()

	/**
	 * Required NC app id (null = built-in).
	 *
	 * @return string|null Required app id (null = built-in).
	 */
	public function getRequiredApp(): ?string {
		return null;
	}//end getRequiredApp()

	/**
	 * Storage strategy hint for the registry.
	 *
	 * @return string Storage strategy hint for the registry.
	 */
	public function getStorageStrategy(): string {
		return 'link-table';
	}//end getStorageStrategy()

	/**
	 * True when the provider is available for use.
	 *
	 * @return bool True when the provider is available for use.
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * List VTODO tasks linked to the given OR object.
	 *
	 * @param string $register Register slug or numeric id.
	 * @param string $schema Schema slug or numeric id.
	 * @param string $objectId Owning object uuid.
	 * @param array<string,mixed> $filters Reserved.
	 *
	 * @return array<int,array<string,mixed>> Tasks rows.
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-14
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		return $this->tasksOf(objectId: $objectId);
	}//end list()

	/**
	 * The tasks linked to an object.
	 *
	 * @param string $objectId Owning object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function tasksOf(string $objectId): array {
		try {
			return $this->taskService->getTasksForObject(objectUuid: $objectId);
		} catch (NoVtodoCalendarException $e) {
			// User has no VTODO-capable calendar yet — that's a setup
			// state, not a crash. Empty list keeps the contract honest
			// (provider never returns 5xx on a missing-prereq) and lets
			// the UI fall back to the "no tasks yet" empty state.
			return [];
		}
	}//end tasksOf()

	/**
	 * Create a VTODO task linked to the given OR object.
	 *
	 * Accepts the per-provider `(register, schema, objectId)` triple
	 * (slugs OR ids) and a free-form payload. Resolves the register +
	 * schema entities so the task carries the int ids that
	 * `TaskService::createTask` expects, and looks up the object for the
	 * RFC 9253 LINK label. The user's default VTODO calendar is
	 * auto-discovered downstream — `payload.calendarId` is ignored.
	 *
	 * Payload keys honoured: `summary`, `description`, `priority`, `due`,
	 * `status`. Anything else is ignored.
	 *
	 * @param string $register Register slug or numeric id.
	 * @param string $schema Schema slug or numeric id.
	 * @param string $objectId Owning object uuid.
	 * @param array<string,mixed> $payload Task payload.
	 *
	 * @return array<string,mixed> Created task row.
	 *
	 * @throws \RuntimeException When the register/schema can't be resolved
	 *                           or the underlying VTODO write fails.
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-14
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	public function create(string $register, string $schema, string $objectId, array $payload): array {
		return $this->createTaskFor(ref: new ObjectRef(register: $register, schema: $schema, id: $objectId), payload: $payload);
	}//end create()

	/**
	 * Create a task in the user's calendar, linked to the object.
	 *
	 * @param ObjectRef           $ref     The owning object.
	 * @param array<string,mixed> $payload The task fields.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws RuntimeException When the task service answers nothing.
	 */
	private function createTaskFor(ObjectRef $ref, array $payload): array {
		$table = new RegisterSchema(register: $this->registerMapper->find(id: $ref->register), schema: $this->schemaMapper->find(id: $ref->schema));
		$task = $this->taskService->createTask(
			registerId: $table->registerId(),
			schemaId: $table->schemaId(),
			objectUuid: $ref->id,
			objectTitle: $this->objectTitle(table: $table, objectId: $ref->id),
			data: self::taskData(payload: $payload)
		);

		if ($task === null) {
			throw new RuntimeException('TaskService::createTask returned null — calendar invalid or auth failure.');
		}

		return $task;
	}//end createTaskFor()

	/**
	 * The object's name, for the task's link label; the uuid when the object cannot be found.
	 *
	 * Object lookup is best-effort — the LINK label is cosmetic. A missing
	 * object still allows the task to be created with a synthetic fallback
	 * title; the linked-entity scan finds it via the X-OPENREGISTER-OBJECT
	 * uuid regardless.
	 *
	 * @param RegisterSchema $table    The object's register and schema.
	 * @param string         $objectId The object uuid.
	 *
	 * @return string
	 */
	private function objectTitle(RegisterSchema $table, string $objectId): string {
		$objectEntity = WriteCause::asLookup(fn () => $this->objectService->find(
			id: $objectId,
			register: $table->register,
			schema: $table->schema
		));

		return $objectEntity?->getName() ?? $objectId;
	}//end objectTitle()

	/**
	 * The task fields the task service takes, with their defaults.
	 *
	 * @param array<string,mixed> $payload The caller's task fields.
	 *
	 * @return array<string,mixed>
	 */
	private static function taskData(array $payload): array {
		$data = [
			'summary' => (string)($payload['summary'] ?? ''),
			'description' => (string)($payload['description'] ?? ''),
			'priority' => (int)($payload['priority'] ?? 0),
			'status' => (string)($payload['status'] ?? 'NEEDS-ACTION'),
		];
		if (isset($payload['due']) === true) {
			$data['due'] = (string)$payload['due'];
		}

		return $data;
	}//end taskData()

	/**
	 * Update a VTODO task linked to the OR object.
	 *
	 * @param string $register Register slug or numeric id.
	 * @param string $schema Schema slug or numeric id.
	 * @param string $objectId Owning object uuid.
	 * @param string $entityId Composite calendar/task id.
	 * @param array<string,mixed> $payload Update payload.
	 *
	 * @return array<string,mixed> Updated task row.
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-14
	 */
	public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array {
		[$calendarId, $taskUri] = $this->ownedTask(objectId: $objectId, entityId: $entityId);

		return self::updatedOrFail(updated: $this->taskService->updateTask(calendarId: $calendarId, taskUri: $taskUri, data: $payload));
	}//end update()

	/**
	 * The task an update answered; an update that answers nothing is an error.
	 *
	 * @param array<string,mixed>|null $updated What the task service answered.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws RuntimeException When the task service answered nothing.
	 */
	private static function updatedOrFail(?array $updated): array {
		if ($updated === null) {
			throw new RuntimeException('TaskService::updateTask returned null — entity may not exist.');
		}

		return $updated;
	}//end updatedOrFail()

	/**
	 * Delete a VTODO task linked to the OR object.
	 *
	 * @param string $register Register slug or numeric id.
	 * @param string $schema Schema slug or numeric id.
	 * @param string $objectId Owning object uuid.
	 * @param string $entityId Composite calendar/task id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-14
	 */
	public function delete(string $register, string $schema, string $objectId, string $entityId): void {
		[$calendarId, $taskUri] = $this->ownedTask(objectId: $objectId, entityId: $entityId);
		$this->taskService->deleteTask(calendarId: $calendarId, taskUri: $taskUri);
	}//end delete()

	/**
	 * Split an entity id and check that the task belongs to the object.
	 *
	 * @param string $objectId Owning object uuid.
	 * @param string $entityId `{calendarId}/{taskUri}`.
	 *
	 * @return array{0: string, 1: string} The calendar id and the task uri.
	 *
	 * @throws NotImplementedException When the entity id is not in that shape.
	 * @throws \Exception             When the task does not belong to the object.
	 */
	private function ownedTask(string $objectId, string $entityId): array {
		[$calendarId, $taskUri] = $this->splitEntityId(entityId: $entityId);
		$this->taskService->requireTaskOf(objectUuid: $objectId, calendarId: $calendarId, taskUri: $taskUri);

		return [$calendarId, $taskUri];
	}//end ownedTask()

	/**
	 * Split a composite `{calendarId}/{taskUri}` entity id into its
	 * two components.
	 *
	 * @param string $entityId Composite id.
	 *
	 * @return array{0: string, 1: string}
	 *
	 * @throws NotImplementedException When the id can't be split — the
	 *                                 caller is expected to pass the
	 *                                 documented shape.
	 */
	private function splitEntityId(string $entityId): array {
		$parts = explode('/', $entityId, 2);
		if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
			throw new NotImplementedException(
				message: sprintf(
					'TasksProvider expects entityId in {calendarId}/{taskUri} shape, got "%s"',
					$entityId
				)
			);
		}

		return [$parts[0], $parts[1]];
	}//end splitEntityId()
}//end class
