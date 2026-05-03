<?php

/**
 * OpenRegister ActionService
 *
 * Business logic for Action entity management.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use OCA\OpenRegister\Db\Action;
use OCA\OpenRegister\Db\ActionMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ActionCreatedEvent;
use OCA\OpenRegister\Event\ActionDeletedEvent;
use OCA\OpenRegister\Event\ActionUpdatedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * ActionService provides business logic for Action CRUD and utilities
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ActionService
{
    /**
     * Hook event mapping for migration
     *
     * @var array<string, string>
     */
    private const HOOK_EVENT_MAP = [
        'creating' => 'ObjectCreatingEvent',
        'created'  => 'ObjectCreatedEvent',
        'updating' => 'ObjectUpdatingEvent',
        'updated'  => 'ObjectUpdatedEvent',
        'deleting' => 'ObjectDeletingEvent',
        'deleted'  => 'ObjectDeletedEvent',
    ];

    /**
     * Constructor
     *
     * @param ActionMapper     $actionMapper    Action mapper
     * @param SchemaMapper     $schemaMapper    Schema mapper
     * @param IEventDispatcher $eventDispatcher Event dispatcher
     * @param LoggerInterface  $logger          Logger
     */
    public function __construct(
        private readonly ActionMapper $actionMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly IEventDispatcher $eventDispatcher,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Create a new action
     *
     * Validates required fields, generates UUID, sets defaults, persists, and dispatches event.
     *
     * @param array $data Action data
     *
     * @return Action The created action
     *
     * @throws \InvalidArgumentException If required fields are missing
     */
    public function createAction(array $data): Action
    {
        // Validate required fields.
        if (empty($data['name']) === true) {
            throw new \InvalidArgumentException('Action name is required');
        }

        if (empty($data['eventType']) === true) {
            throw new \InvalidArgumentException('Action eventType is required');
        }

        if (empty($data['engine']) === true) {
            throw new \InvalidArgumentException('Action engine is required');
        }

        if (empty($data['workflowId']) === true) {
            throw new \InvalidArgumentException('Action workflowId is required');
        }

        // Remove ID to ensure new record.
        unset($data['id']);

        // Generate UUID if not provided.
        if (empty($data['uuid']) === true) {
            $data['uuid'] = Uuid::v4()->toRfc4122();
        }

        // Set defaults for optional fields.
        $data['status']         = $data['status'] ?? 'draft';
        $data['mode']           = $data['mode'] ?? 'sync';
        $data['executionOrder'] = $data['executionOrder'] ?? 0;
        $data['timeout']        = $data['timeout'] ?? 30;
        $data['onFailure']      = $data['onFailure'] ?? 'reject';
        $data['onTimeout']      = $data['onTimeout'] ?? 'reject';
        $data['onEngineDown']   = $data['onEngineDown'] ?? 'allow';
        $data['maxRetries']     = $data['maxRetries'] ?? 3;
        $data['retryPolicy']    = $data['retryPolicy'] ?? 'exponential';
        $data['enabled']        = $data['enabled'] ?? true;
        $data['version']        = $data['version'] ?? '1.0.0';

        $action = new Action();
        $action->hydrate($data);

        $action = $this->actionMapper->insert(entity: $action);

        $this->eventDispatcher->dispatchTyped(new ActionCreatedEvent(action: $action));

        $this->logger->info(
            message: '[ActionService] Action created',
            context: ['id' => $action->getId(), 'name' => $action->getName()]
        );

        return $action;
    }//end createAction()

    /**
     * Update an existing action
     *
     * @param int   $id   Action ID
     * @param array $data Partial update data
     *
     * @return Action The updated action
     */
    public function updateAction(int $id, array $data): Action
    {
        $action = $this->actionMapper->find(id: $id);

        // Remove fields that should not be user-overridable.
        unset($data['id'], $data['uuid'], $data['created']);

        $action->hydrate($data);
        $action->setUpdated(new DateTime());

        $action = $this->actionMapper->update(entity: $action);

        $this->eventDispatcher->dispatchTyped(new ActionUpdatedEvent(action: $action));

        return $action;
    }//end updateAction()

    /**
     * Soft-delete an action
     *
     * Sets deleted timestamp and changes status to archived.
     *
     * @param int $id Action ID
     *
     * @return Action The deleted action
     */
    public function deleteAction(int $id): Action
    {
        $action = $this->actionMapper->find(id: $id);

        $action->setDeleted(new DateTime());
        $action->setStatus('archived');
        $action->setUpdated(new DateTime());

        $action = $this->actionMapper->update(entity: $action);

        $this->eventDispatcher->dispatchTyped(new ActionDeletedEvent(action: $action));

        $this->logger->info(
            message: '[ActionService] Action soft-deleted',
            context: ['id' => $action->getId(), 'name' => $action->getName()]
        );

        return $action;
    }//end deleteAction()

    /**
     * Test an action with a dry-run simulation
     *
     * Validates matching and builds the payload without executing side effects.
     *
     * @param int   $id            Action ID
     * @param array $samplePayload Sample event payload
     *
     * @return array Test result with match info and payload
     */
    public function testAction(int $id, array $samplePayload): array
    {
        $action = $this->actionMapper->find(id: $id);

        $eventType    = $samplePayload['eventType'] ?? '';
        $schemaUuid   = $samplePayload['schemaUuid'] ?? null;
        $registerUuid = $samplePayload['registerUuid'] ?? null;

        // Check event type match.
        $eventMatch = $action->matchesEvent($eventType);

        // Check schema match.
        $schemaMatch = $action->matchesSchema($schemaUuid);

        // Check register match.
        $registerMatch = $action->matchesRegister($registerUuid);

        // Check filter condition match.
        $filterMatch   = true;
        $filterReasons = [];
        $conditions    = $action->getFilterConditionArray();
        if (empty($conditions) === false) {
            foreach ($conditions as $key => $expected) {
                $actual = $this->getNestedValue(data: $samplePayload, key: $key);
                if (is_array($expected) === true) {
                    if (in_array($actual, $expected) === false) {
                        $filterMatch     = false;
                        $expectedList    = implode(', ', $expected);
                        $filterReasons[] = sprintf(
                            "filter_condition mismatch: %s expected one of [%s], got '%s'",
                            $key,
                            $expectedList,
                            (string) $actual
                        );
                    }
                } else if ($actual !== $expected) {
                    $filterMatch     = false;
                    $filterReasons[] = "filter_condition mismatch: {$key} expected '{$expected}', got '{$actual}'";
                }
            }
        }

        $matched = $eventMatch && $schemaMatch && $registerMatch && $filterMatch;

        return [
            'matched'       => $matched,
            'action'        => $action->jsonSerialize(),
            'eventMatch'    => $eventMatch,
            'schemaMatch'   => $schemaMatch,
            'registerMatch' => $registerMatch,
            'filterMatch'   => $filterMatch,
            'filterReasons' => $filterReasons,
            'builtPayload'  => $matched === true ? $samplePayload : null,
        ];
    }//end testAction()

    /**
     * Migrate inline hooks from a schema to Action entities
     *
     * @param int $schemaId Schema ID
     *
     * @return array Migration report
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function migrateFromHooks(int $schemaId): array
    {
        $schema = $this->schemaMapper->find(id: $schemaId);
        $hooks  = $schema->getHooks() ?? [];

        $report = [
            'created' => [],
            'skipped' => [],
            'errors'  => [],
        ];

        if (empty($hooks) === true) {
            return $report;
        }

        $schemaUuid = $schema->getUuid() ?? (string) $schemaId;

        foreach ($hooks as $index => $hook) {
            try {
                $name      = $hook['id'] ?? "Hook {$index} for ".($schema->getName() ?? 'Unknown');
                $eventKey  = $hook['event'] ?? 'creating';
                $eventType = self::HOOK_EVENT_MAP[$eventKey] ?? $eventKey;

                // Check for duplicates.
                $existing = $this->actionMapper->findAll(
                    filters: ['status' => 'active']
                );

                $isDuplicate = false;
                foreach ($existing as $existingAction) {
                    if ($existingAction->getName() === $name
                        && $existingAction->matchesEvent($eventType) === true
                        && in_array($schemaUuid, $existingAction->getSchemasArray()) === true
                    ) {
                        $isDuplicate = true;
                        break;
                    }
                }

                if ($isDuplicate === true) {
                    $report['skipped'][] = ['name' => $name, 'reason' => 'duplicate'];
                    continue;
                }

                $action = $this->createAction(
                        data: [
                            'name'           => $name,
                            'eventType'      => $eventType,
                            'engine'         => $hook['engine'] ?? 'n8n',
                            'workflowId'     => $hook['workflowId'] ?? '',
                            'mode'           => $hook['mode'] ?? 'sync',
                            'executionOrder' => $hook['order'] ?? 0,
                            'timeout'        => $hook['timeout'] ?? 30,
                            'onFailure'      => $hook['onFailure'] ?? 'reject',
                            'schemas'        => [$schemaUuid],
                            'status'         => 'active',
                        ]
                        );

                $report['created'][] = $action->jsonSerialize();
            } catch (\Exception $e) {
                $report['errors'][] = [
                    'hook'  => $hook,
                    'error' => $e->getMessage(),
                ];
            }//end try
        }//end foreach

        return $report;
    }//end migrateFromHooks()

    /**
     * Update statistics for an action after execution
     *
     * @param int    $actionId Action ID
     * @param string $status   Execution status (success, failure, abandoned)
     *
     * @return void
     */
    public function updateStatistics(int $actionId, string $status): void
    {
        try {
            $action = $this->actionMapper->find(id: $actionId);

            $action->setExecutionCount($action->getExecutionCount() + 1);
            $action->setLastExecutedAt(new DateTime());

            if ($status === 'success') {
                $action->setSuccessCount($action->getSuccessCount() + 1);
            } else {
                $action->setFailureCount($action->getFailureCount() + 1);
            }

            $this->actionMapper->update(entity: $action);
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[ActionService] Failed to update action statistics',
                context: ['actionId' => $actionId, 'error' => $e->getMessage()]
            );
        }
    }//end updateStatistics()

    /**
     * Get a nested value from an array using dot notation
     *
     * @param array  $data Array to search
     * @param string $key  Dot-notation key
     *
     * @return mixed The value or null
     */
    private function getNestedValue(array $data, string $key): mixed
    {
        $keys = explode('.', $key);

        foreach ($keys as $segment) {
            if (is_array($data) === false || array_key_exists($segment, $data) === false) {
                return null;
            }

            $data = $data[$segment];
        }

        return $data;
    }//end getNestedValue()
}//end class
