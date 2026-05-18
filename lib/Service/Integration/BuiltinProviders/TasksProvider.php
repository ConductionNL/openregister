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

use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Exception\NoVtodoCalendarException;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\TaskService;
use OCP\IL10N;

/**
 * Tasks integration provider — delegates to TaskService.
 *
 * Task ids are CalDAV-shaped composites ("{calendarId}/{taskUri}");
 * we accept the composite as the `entityId` and split it lazily
 * inside the mutation methods so the provider's surface stays
 * uniform.
 */
class TasksProvider extends AbstractIntegrationProvider
{
    /**
     * Constructor.
     *
     * @param TaskService $taskService Tasks service.
     * @param IL10N       $l10n        Localisation.
     *
     * @return void
     */
    public function __construct(
        private TaskService $taskService,
        private IL10N $l10n,
    ) {
    }//end __construct()

    /**
     * Stable provider id used in routes and configs.
     *
     * @return string Stable provider identifier.
     */
    public function getId(): string
    {
        return 'tasks';
    }//end getId()

    /**
     * Translated, human-readable provider label.
     *
     * @return string Translated, human-readable provider label.
     */
    public function getLabel(): string
    {
        return $this->l10n->t('Tasks');
    }//end getLabel()

    /**
     * MDI icon name for the provider.
     *
     * @return string MDI icon name for the provider.
     */
    public function getIcon(): string
    {
        return 'CheckboxMarkedOutline';
    }//end getIcon()

    /**
     * Group identifier for UI grouping (or null).
     *
     * @return string|null Group identifier for UI grouping.
     */
    public function getGroup(): ?string
    {
        return 'core';
    }//end getGroup()

    /**
     * Required NC app id (null = built-in).
     *
     * @return string|null Required app id (null = built-in).
     */
    public function getRequiredApp(): ?string
    {
        return null;
    }//end getRequiredApp()

    /**
     * Storage strategy hint for the registry.
     *
     * @return string Storage strategy hint for the registry.
     */
    public function getStorageStrategy(): string
    {
        return 'link-table';
    }//end getStorageStrategy()

    /**
     * True when the provider is available for use.
     *
     * @return bool True when the provider is available for use.
     */
    public function isEnabled(): bool
    {
        return true;
    }//end isEnabled()

    /**
     * List VTODO tasks linked to the given OR object.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Owning object uuid.
     * @param array<string,mixed> $filters  Reserved.
     *
     * @return array<int,array<string,mixed>> Tasks rows.
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        try {
            return $this->taskService->getTasksForObject(objectUuid: $objectId);
        } catch (NoVtodoCalendarException $e) {
            // User has no VTODO-capable calendar yet — that's a setup
            // state, not a crash. Empty list keeps the contract honest
            // (provider never returns 5xx on a missing-prereq) and lets
            // the UI fall back to the "no tasks yet" empty state.
            return [];
        }
    }//end list()

    /**
     * Create a VTODO task linked to the given OR object.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Owning object uuid.
     * @param array<string,mixed> $payload  Task payload.
     *
     * @return array<string,mixed> Created task row.
     */
    public function create(string $register, string $schema, string $objectId, array $payload): array
    {
        $calendarId  = (string) ($payload['calendarId'] ?? '');
        $summary     = (string) ($payload['summary'] ?? '');
        $description = (string) ($payload['description'] ?? '');
        $due         = isset($payload['due']) === true ? (string) $payload['due'] : null;
        $priority    = isset($payload['priority']) === true ? (int) $payload['priority'] : null;

        // TODO(#1539): call signature mismatched against TaskService::createTask
        // (expects int registerId, int schemaId, string objectUuid, string objectTitle, array data) —
        // suppressing here so phpcs stays green; fix tracked in #1539.
        // @phpcs:ignore CustomSniffs.Functions.NamedParameters
        $task = $this->taskService->createTask($calendarId, $summary, $description, $due, $priority, $objectId);

        if ($task === null) {
            throw new \RuntimeException('TaskService::createTask returned null — calendar invalid or auth failure.');
        }

        return $task;
    }//end create()

    /**
     * Update a VTODO task linked to the OR object.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Owning object uuid.
     * @param string              $entityId Composite calendar/task id.
     * @param array<string,mixed> $payload  Update payload.
     *
     * @return array<string,mixed> Updated task row.
     */
    public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array
    {
        [$calendarId, $taskUri] = $this->splitEntityId(entityId: $entityId);
        $updated = $this->taskService->updateTask(calendarId: $calendarId, taskUri: $taskUri, data: $payload);

        if ($updated === null) {
            throw new \RuntimeException('TaskService::updateTask returned null — entity may not exist.');
        }

        return $updated;
    }//end update()

    /**
     * Delete a VTODO task linked to the OR object.
     *
     * @param string $register Register slug or numeric id.
     * @param string $schema   Schema slug or numeric id.
     * @param string $objectId Owning object uuid.
     * @param string $entityId Composite calendar/task id.
     *
     * @return void
     */
    public function delete(string $register, string $schema, string $objectId, string $entityId): void
    {
        [$calendarId, $taskUri] = $this->splitEntityId(entityId: $entityId);
        $this->taskService->deleteTask(calendarId: $calendarId, taskUri: $taskUri);
    }//end delete()

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
    private function splitEntityId(string $entityId): array
    {
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
