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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-14
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\BuiltinProviders;

use OCA\OpenRegister\Exception\NotImplementedException;
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

    public function getId(): string
    {
        return 'tasks';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Tasks');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'CheckboxMarkedOutline';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'core';
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        return null;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'link-table';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return true;
    }//end isEnabled()

    public function list(string $register, string $schema, string $objectId, array $filters = []): array
    {
        return $this->taskService->getTasksForObject($objectId);
    }//end list()

    public function create(string $register, string $schema, string $objectId, array $payload): array
    {
        $calendarId = (string) ($payload['calendarId'] ?? '');
        $summary    = (string) ($payload['summary'] ?? '');
        $description = (string) ($payload['description'] ?? '');
        $due         = isset($payload['due']) === true ? (string) $payload['due'] : null;
        $priority    = isset($payload['priority']) === true ? (int) $payload['priority'] : null;

        $task = $this->taskService->createTask(
            $calendarId,
            $summary,
            $description,
            $due,
            $priority,
            $objectId
        );

        if ($task === null) {
            throw new \RuntimeException('TaskService::createTask returned null — calendar invalid or auth failure.');
        }

        return $task;
    }//end create()

    public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array
    {
        [$calendarId, $taskUri] = $this->splitEntityId($entityId);
        $updated = $this->taskService->updateTask($calendarId, $taskUri, $payload);

        if ($updated === null) {
            throw new \RuntimeException('TaskService::updateTask returned null — entity may not exist.');
        }

        return $updated;
    }//end update()

    public function delete(string $register, string $schema, string $objectId, string $entityId): void
    {
        [$calendarId, $taskUri] = $this->splitEntityId($entityId);
        $this->taskService->deleteTask($calendarId, $taskUri);
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
                sprintf(
                    'TasksProvider expects entityId in {calendarId}/{taskUri} shape, got "%s"',
                    $entityId
                )
            );
        }

        return [$parts[0], $parts[1]];
    }//end splitEntityId()

}//end class
