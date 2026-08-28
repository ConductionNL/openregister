<?php

/**
 * OpenRegister HookExecutor Service
 *
 * Orchestrates schema hook execution for object lifecycle events.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 *
 * @spec openspec/specs/schema-hooks/spec.md#requirement-hook-configuration-on-schema
 * @spec openspec/specs/schema-hooks/spec.md#requirement-hook-lifecycle-events
 * @spec openspec/specs/schema-hooks/spec.md#requirement-sync-hook-response-format
 * @spec openspec/specs/schema-hooks/spec.md#requirement-hook-execution-order
 * @spec openspec/specs/schema-hooks/spec.md#requirement-failure-mode-behavior
 * @spec openspec/specs/schema-hooks/spec.md#requirement-filter-condition-for-conditional-hook-execution
 * @spec openspec/specs/schema-hooks/spec.md#requirement-async-hook-execution-fire-and-forget
 * @spec openspec/specs/schema-hooks/spec.md#requirement-hook-retry-via-background-job
 * @spec openspec/specs/schema-hooks/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\WorkflowExecutionMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Webhook\CloudEventFormatter;
use OCA\OpenRegister\WorkflowEngine\WorkflowResult;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use Psr\Log\LoggerInterface;

/**
 * HookExecutor orchestrates schema hook execution for object lifecycle events.
 *
 * Responsibilities:
 * 1. Load enabled hooks from a Schema for the current event type
 * 2. Sort hooks by order (ascending)
 * 3. Build CloudEvents payloads
 * 4. Execute sync workflows via engine adapters
 * 5. Process responses (approved/rejected/modified)
 * 6. Apply failure modes (reject/allow/flag/queue)
 * 7. Log all hook executions
 * 8. Persist execution history to WorkflowExecution entities
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Needs engine registry, formatter, schema mapper, execution mapper, job list, and logger
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Lifecycle: filter, sync/async dispatch, result processing, 4 failure modes, logging, retry
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)    Each method is a cohesive pipeline stage; splitting would scatter execution context
 */
class HookExecutor {
	/**
	 * Constructor for HookExecutor.
	 *
	 * @param WorkflowEngineRegistry $engineRegistry Engine registry for resolving adapters
	 * @param CloudEventFormatter $cloudEventFormatter CloudEvent payload builder
	 * @param SchemaMapper $schemaMapper Schema mapper for loading schemas
	 * @param IJobList $jobList Background job list for queue mode
	 * @param WorkflowExecutionMapper $executionMapper Execution history persistence
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly WorkflowEngineRegistry $engineRegistry,
		private readonly CloudEventFormatter $cloudEventFormatter,
		private readonly SchemaMapper $schemaMapper,
		private readonly IJobList $jobList,
		private readonly WorkflowExecutionMapper $executionMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Execute hooks for a given event and schema.
	 *
	 * Loads hooks matching the event type from the schema, sorts them by order,
	 * and executes each one. For sync hooks, processes responses and may stop
	 * propagation or merge modified data into the event.
	 *
	 * @param Event $event The lifecycle event (ObjectCreatingEvent, etc.)
	 * @param Schema $schema The schema containing hook configurations
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-hooks/spec.md#requirement-hook-configuration-on-schema
	 * @spec openspec/specs/schema-hooks/spec.md
	 */
	public function executeHooks(Event $event, Schema $schema): void {
		$eventType = $this->resolveEventType(event: $event);
		if ($eventType === null) {
			return;
		}

		$hooks = $this->loadHooks(schema: $schema, eventType: $eventType);
		if (empty($hooks) === true) {
			return;
		}

		$object = $this->getObjectFromEvent(event: $event);
		if ($object === null) {
			return;
		}

		foreach ($hooks as $hook) {
			if ($this->isEventStopped(event: $event) === true) {
				break;
			}

			$this->executeSingleHook(
				hook: $hook,
				event: $event,
				object: $object,
				schema: $schema,
				eventType: $eventType
			);
		}
	}//end executeHooks()

	/**
	 * Map an event class to its hook event type string.
	 *
	 * @param Event $event The lifecycle event
	 *
	 * @return string|null The event type string (e.g. 'creating') or null
	 *
	 * @spec openspec/specs/schema-hooks/spec.md#requirement-hook-configuration-on-schema
	 */
	private function resolveEventType(Event $event): ?string {
		return match (true) {
			$event instanceof ObjectCreatingEvent => 'creating',
			$event instanceof ObjectUpdatingEvent => 'updating',
			$event instanceof ObjectDeletingEvent => 'deleting',
			$event instanceof ObjectCreatedEvent => 'created',
			$event instanceof ObjectUpdatedEvent => 'updated',
			$event instanceof ObjectDeletedEvent => 'deleted',
			default => null,
		};
	}//end resolveEventType()

	/**
	 * Load enabled hooks from the schema for a specific event type, sorted by order.
	 *
	 * @param Schema $schema The schema entity
	 * @param string $eventType The event type to filter by
	 *
	 * @return array<int, array<string, mixed>> Sorted array of hook configurations
	 *
	 * @spec openspec/specs/schema-hooks/spec.md#requirement-hook-configuration-on-schema
	 */
	private function loadHooks(Schema $schema, string $eventType): array {
		$allHooks = ($schema->getHooks() ?? []);
		$filtered = [];

		foreach ($allHooks as $hook) {
			if (($hook['enabled'] ?? true) === false) {
				continue;
			}

			if (($hook['event'] ?? '') !== $eventType) {
				continue;
			}

			$filtered[] = $hook;
		}

		usort(
			$filtered,
			static function (array $hookA, array $hookB): int {
				return ($hookA['order'] ?? 0) <=> ($hookB['order'] ?? 0);
			}
		);

		return $filtered;
	}//end loadHooks()

	/**
	 * Extract the ObjectEntity from the event.
	 *
	 * @param Event $event The lifecycle event
	 *
	 * @return ObjectEntity|null The object entity or null
	 *
	 * @spec openspec/specs/schema-hooks/spec.md#requirement-hook-configuration-on-schema
	 */
	private function getObjectFromEvent(Event $event): ?ObjectEntity {
		if ($event instanceof ObjectCreatingEvent) {
			return $event->getObject();
		}

		if ($event instanceof ObjectUpdatingEvent) {
			return $event->getNewObject();
		}

		if ($event instanceof ObjectDeletingEvent) {
			return $event->getObject();
		}

		if ($event instanceof ObjectCreatedEvent) {
			return $event->getObject();
		}

		if ($event instanceof ObjectUpdatedEvent) {
			return $event->getNewObject();
		}

		if ($event instanceof ObjectDeletedEvent) {
			return $event->getObject();
		}

		return null;
	}//end getObjectFromEvent()

	/**
	 * Check if the event has had its propagation stopped.
	 *
	 * @param Event $event The lifecycle event
	 *
	 * @return bool True if propagation is stopped
	 *
	 * @spec openspec/specs/schema-hooks/spec.md#requirement-hook-execution-order
	 */
	private function isEventStopped(Event $event): bool {
		if ($event instanceof ObjectCreatingEvent) {
			return $event->isPropagationStopped();
		}

		if ($event instanceof ObjectUpdatingEvent) {
			return $event->isPropagationStopped();
		}

		if ($event instanceof ObjectDeletingEvent) {
			return $event->isPropagationStopped();
		}

		return false;
	}//end isEventStopped()

	/**
	 * Evaluate a hook's filterCondition against the object data.
	 *
	 * Supports simple dot-notation equality checks like {"field": "value"}.
	 * If no filterCondition is set, returns true (hook should execute).
	 *
	 * @param array<string, mixed> $hook Hook configuration
	 * @param ObjectEntity $object The object entity
	 *
	 * @return bool True if the hook should execute
	 *
	 * @spec openspec/specs/schema-hooks/spec.md#requirement-filter-condition-for-conditional-hook-execution
	 */
	private function evaluateFilterCondition(array $hook, ObjectEntity $object): bool {
		$condition = ($hook['filterCondition'] ?? null);
		if ($condition === null || (is_array($condition) === true && empty($condition) === true)) {
			return true;
		}

		if (is_array($condition) === false) {
			return true;
		}

		$objectData = ($object->getObject() ?? []);

		// Simple key-value matching: all conditions must match.
		foreach ($condition as $field => $expected) {
			$actual = ($objectData[$field] ?? null);
			if ($actual !== $expected) {
				return false;
			}
		}

		return true;
	}//end evaluateFilterCondition()

	/**
	 * Execute a single hook against the event and object.
	 *
	 * @param array<string, mixed> $hook Hook configuration
	 * @param Event $event The lifecycle event
	 * @param ObjectEntity $object The object entity
	 * @param Schema $schema The schema entity
	 * @param string $eventType The event type string
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Dispatch: filter, engine, async/sync, result, exception - each a required lifecycle branch
	 *
	 * @spec openspec/specs/schema-hooks/spec.md#requirement-hook-configuration-on-schema
	 */
	private function executeSingleHook(
		array $hook,
		Event $event,
		ObjectEntity $object,
		Schema $schema,
		string $eventType,
	): void {
		$hookId = ($hook['id'] ?? 'unknown');
		$engineType = ($hook['engine'] ?? '');
		$workflowId = ($hook['workflowId'] ?? '');
		$mode = ($hook['mode'] ?? 'sync');
		$timeout = ($hook['timeout'] ?? 30);

		// Skip hook if filterCondition is set and does not match the object data.
		if ($this->evaluateFilterCondition(hook: $hook, object: $object) === false) {
			$this->logger->debug(
				message: "[HookExecutor] Hook '$hookId' skipped: filterCondition not met",
				context: ['hookId' => $hookId, 'objectId' => $object->getUuid()]
			);
			return;
		}

		$startTime = hrtime(true);

		$payload = $this->buildCloudEventPayload(
			object: $object,
			schema: $schema,
			eventType: $eventType,
			hookId: $hookId,
			mode: $mode
		);

		try {
			$engines = $this->engineRegistry->getEnginesByType(engineType: $engineType);
			if (empty($engines) === true) {
				$this->applyFailureMode(
					failureMode: ($hook['onEngineDown'] ?? 'allow'),
					event: $event,
					object: $object,
					hook: $hook,
					error: "No engine found for type '$engineType'"
				);
				$this->logHookExecution(
					hook: $hook,
					eventType: $eventType,
					object: $object,
					startTime: $startTime,
					success: false,
					error: "No engine found for type '$engineType'"
				);
				return;
			}

			$engine = $engines[0];
			$adapter = $this->engineRegistry->resolveAdapter(engine: $engine);

			if ($mode === 'async') {
				$this->executeAsyncHook(
					adapter: $adapter,
					workflowId: $workflowId,
					payload: $payload,
					hook: $hook,
					eventType: $eventType,
					object: $object,
					startTime: $startTime
				);
				return;
			}

			$result = $adapter->executeWorkflow(
				workflowId: $workflowId,
				data: $payload,
				timeout: $timeout
			);

			$this->processWorkflowResult(
				result: $result,
				event: $event,
				object: $object,
				hook: $hook,
				eventType: $eventType,
				startTime: $startTime
			);
		} catch (Exception $e) {
			$failureMode = $this->determineFailureMode(exception: $e, hook: $hook);
			$this->applyFailureMode(
				failureMode: $failureMode,
				event: $event,
				object: $object,
				hook: $hook,
				error: $e->getMessage()
			);
			$this->logHookExecution(
				hook: $hook,
				eventType: $eventType,
				object: $object,
				startTime: $startTime,
				success: false,
				error: $e->getMessage(),
				payload: $payload
			);
		}//end try
	}//end executeSingleHook()

	/**
	 * Build a CloudEvents payload for a hook execution.
	 *
	 * @param ObjectEntity $object The object entity
	 * @param Schema $schema The schema entity
	 * @param string $eventType The event type (creating, updating, deleting)
	 * @param string $hookId The hook configuration ID
	 * @param string $mode Hook mode (sync or async)
	 *
	 * @return array<string, mixed> CloudEvent-formatted payload
	 *
	 * @spec openspec/specs/schema-hooks/spec.md#requirement-hook-lifecycle-events
	 */
	private function buildCloudEventPayload(
		ObjectEntity $object,
		Schema $schema,
		string $eventType,
		string $hookId,
		string $mode,
	): array {
		$objectData = $object->getObject();
		$source = '/apps/openregister/registers/' . $object->getRegister() . '/schemas/' . $schema->getId();

		$payload = $this->cloudEventFormatter->formatAsCloudEvent(
			eventType: 'nl.openregister.object.' . $eventType,
			payload: [
				'object' => $objectData,
				'schema' => ($schema->getSlug() ?? $schema->getTitle()),
				'register' => $object->getRegister(),
				'action' => $eventType,
				'hookMode' => $mode,
			],
			source: $source,
			subject: 'object:' . ($object->getUuid() ?? (string)$object->getId())
		);

		$payload['openregister']['hookId'] = $hookId;
		$payload['openregister']['expectResponse'] = ($mode === 'sync');

		return $payload;
	}//end buildCloudEventPayload()

	/**
	 * Execute an async hook (fire-and-forget).
	 *
	 * @param \OCA\OpenRegister\WorkflowEngine\WorkflowEngineInterface $adapter Engine adapter
	 * @param string $workflowId Workflow ID
	 * @param array<string, mixed> $payload CloudEvent payload
	 * @param array<string, mixed> $hook Hook configuration
	 * @param string $eventType Event type
	 * @param ObjectEntity $object Object entity
	 * @param int|float $startTime Start time from hrtime
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-hooks/spec.md#requirement-async-hook-execution-fire-and-forget
	 */
	private function executeAsyncHook(
		$adapter,
		string $workflowId,
		array $payload,
		array $hook,
		string $eventType,
		ObjectEntity $object,
		$startTime,
	): void {
		try {
			$adapter->executeWorkflow(
				workflowId: $workflowId,
				data: $payload,
				timeout: ($hook['timeout'] ?? 30)
			);
			$this->logHookExecution(
				hook: $hook,
				eventType: $eventType,
				object: $object,
				startTime: $startTime,
				success: true,
				deliveryStatus: 'delivered'
			);
		} catch (Exception $e) {
			$this->logHookExecution(
				hook: $hook,
				eventType: $eventType,
				object: $object,
				startTime: $startTime,
				success: false,
				error: $e->getMessage(),
				deliveryStatus: 'failed'
			);
		}//end try
	}//end executeAsyncHook()

	/**
	 * Process a sync workflow result and apply it to the event.
	 *
	 * @param WorkflowResult $result The workflow execution result
	 * @param Event $event The lifecycle event
	 * @param ObjectEntity $object The object entity
	 * @param array<string, mixed> $hook Hook configuration
	 * @param string $eventType Event type
	 * @param int|float $startTime Start time from hrtime
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-hooks/spec.md#requirement-sync-hook-response-format
	 */
	private function processWorkflowResult(
		WorkflowResult $result,
		Event $event,
		ObjectEntity $object,
		array $hook,
		string $eventType,
		$startTime,
	): void {
		if ($result->isApproved() === true) {
			$this->logHookExecution(
				hook: $hook,
				eventType: $eventType,
				object: $object,
				startTime: $startTime,
				success: true,
				responseStatus: 'approved'
			);
			return;
		}

		if ($result->isModified() === true) {
			$modifiedData = $result->getData();
			if ($modifiedData !== null) {
				$this->setModifiedDataOnEvent(event: $event, data: $modifiedData);
			}

			$this->logHookExecution(
				hook: $hook,
				eventType: $eventType,
				object: $object,
				startTime: $startTime,
				success: true,
				responseStatus: 'modified'
			);
			return;
		}

		if ($result->isRejected() === true) {
			$this->applyFailureMode(
				failureMode: ($hook['onFailure'] ?? 'reject'),
				event: $event,
				object: $object,
				hook: $hook,
				error: 'Workflow rejected the operation',
				errors: $result->getErrors()
			);
			$this->logHookExecution(
				hook: $hook,
				eventType: $eventType,
				object: $object,
				startTime: $startTime,
				success: false,
				responseStatus: 'rejected',
				error: 'Workflow rejected the operation'
			);
			return;
		}

		// Error status.
		$this->applyFailureMode(
			failureMode: ($hook['onFailure'] ?? 'reject'),
			event: $event,
			object: $object,
			hook: $hook,
			error: 'Workflow returned error status',
			errors: $result->getErrors()
		);
		$this->logHookExecution(
			hook: $hook,
			eventType: $eventType,
			object: $object,
			startTime: $startTime,
			success: false,
			responseStatus: 'error',
			error: 'Workflow returned error status'
		);
	}//end processWorkflowResult()

	/**
	 * Set modified data on the event.
	 *
	 * @param Event $event The lifecycle event
	 * @param array<string, mixed> $data Modified data to merge
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-hooks/spec.md#requirement-sync-hook-response-format
	 */
	private function setModifiedDataOnEvent(Event $event, array $data): void {
		if ($event instanceof ObjectCreatingEvent) {
			$event->setModifiedData(data: $data);
		} elseif ($event instanceof ObjectUpdatingEvent) {
			$event->setModifiedData(data: $data);
		} elseif ($event instanceof ObjectDeletingEvent) {
			$event->setModifiedData(data: $data);
		}
	}//end setModifiedDataOnEvent()

	/**
	 * Determine the appropriate failure mode based on the exception type.
	 *
	 * @param Exception $exception The caught exception
	 * @param array<string, mixed> $hook Hook configuration
	 *
	 * @return string The failure mode to apply
	 *
	 * @spec openspec/specs/schema-hooks/spec.md#requirement-failure-mode-behavior
	 */
	private function determineFailureMode(Exception $exception, array $hook): string {
		$message = strtolower($exception->getMessage());

		// Connection/timeout errors use engine-down or timeout mode.
		if (str_contains($message, 'timeout') === true
			|| str_contains($message, 'timed out') === true
		) {
			return ($hook['onTimeout'] ?? 'reject');
		}

		if (str_contains($message, 'connection') === true
			|| str_contains($message, 'unreachable') === true
			|| str_contains($message, 'refused') === true
		) {
			return ($hook['onEngineDown'] ?? 'allow');
		}

		return ($hook['onFailure'] ?? 'reject');
	}//end determineFailureMode()

	/**
	 * Apply a failure mode to the event.
	 *
	 * @param string $failureMode Failure mode (reject/allow/flag/queue)
	 * @param Event $event The lifecycle event
	 * @param ObjectEntity $object The object entity
	 * @param array<string, mixed> $hook Hook configuration
	 * @param string $error Error message
	 * @param array $errors Detailed validation errors
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Failure modes reject/allow/flag/queue plus unknown fallback - each case has distinct side effects
	 *
	 * @spec openspec/specs/schema-hooks/spec.md#requirement-failure-mode-behavior
	 */
	private function applyFailureMode(
		string $failureMode,
		Event $event,
		ObjectEntity $object,
		array $hook,
		string $error,
		array $errors = [],
	): void {
		$hookId = ($hook['id'] ?? 'unknown');

		switch ($failureMode) {
			case 'reject':
				$this->stopEvent(event: $event, errors: $errors, fallbackError: $error);
				$this->logger->error(
					message: "[HookExecutor] Hook '$hookId' rejected: $error",
					context: ['hookId' => $hookId, 'objectId' => $object->getUuid()]
				);
				break;

			case 'allow':
				$this->logger->warning(
					message: "[HookExecutor] Hook '$hookId' failed (allow mode): $error",
					context: ['hookId' => $hookId, 'objectId' => $object->getUuid()]
				);
				break;

			case 'flag':
				$this->setValidationMetadata(
					object: $object,
					status: 'failed',
					errors: $errors,
					fallbackError: $error
				);
				$this->logger->warning(
					message: "[HookExecutor] Hook '$hookId' failed (flag mode): $error",
					context: ['hookId' => $hookId, 'objectId' => $object->getUuid()]
				);
				break;

			case 'queue':
				$this->setValidationMetadata(object: $object, status: 'pending');
				$this->scheduleRetryJob(object: $object, hook: $hook);
				$this->logger->warning(
					message: "[HookExecutor] Hook '$hookId' queued for retry: $error",
					context: ['hookId' => $hookId, 'objectId' => $object->getUuid()]
				);
				break;

			default:
				$this->stopEvent(event: $event, errors: $errors, fallbackError: $error);
				$this->logger->error(
					message: "[HookExecutor] Unknown failure mode '$failureMode' for hook '$hookId', defaulting to reject",
					context: ['hookId' => $hookId]
				);
		}//end switch
	}//end applyFailureMode()

	/**
	 * Stop event propagation and set errors.
	 *
	 * @param Event $event The lifecycle event
	 * @param array<int, array{field?: string, message: string, code?: string}> $errors Validation errors
	 * @param string $fallbackError Fallback error message
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-hooks/spec.md#requirement-failure-mode-behavior
	 */
	private function stopEvent(Event $event, array $errors, string $fallbackError): void {
		$eventErrors = $errors;
		if (empty($eventErrors) === true) {
			$eventErrors = [['message' => $fallbackError]];
		}

		if ($event instanceof ObjectCreatingEvent) {
			$event->stopPropagation();
			$event->setErrors(errors: $eventErrors);
		} elseif ($event instanceof ObjectUpdatingEvent) {
			$event->stopPropagation();
			$event->setErrors(errors: $eventErrors);
		} elseif ($event instanceof ObjectDeletingEvent) {
			$event->stopPropagation();
			$event->setErrors(errors: $eventErrors);
		}
	}//end stopEvent()

	/**
	 * Set validation metadata on the object entity.
	 *
	 * @param ObjectEntity $object The object entity
	 * @param string $status Validation status
	 * @param array $errors Validation errors
	 * @param string|null $fallbackError Fallback error message
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-hooks/spec.md#requirement-failure-mode-behavior
	 */
	private function setValidationMetadata(
		ObjectEntity $object,
		string $status,
		array $errors = [],
		?string $fallbackError = null,
	): void {
		$objectData = ($object->getObject() ?? []);

		$objectData['_validationStatus'] = $status;

		if (empty($errors) === false) {
			$objectData['_validationErrors'] = $errors;
		} elseif ($fallbackError !== null) {
			$objectData['_validationErrors'] = [['message' => $fallbackError]];
		}

		$object->setObject($objectData);
	}//end setValidationMetadata()

	/**
	 * Schedule a background retry job for a hook.
	 *
	 * @param ObjectEntity $object The object entity
	 * @param array<string, mixed> $hook Hook configuration
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-hooks/spec.md#requirement-hook-retry-via-background-job
	 */
	private function scheduleRetryJob(ObjectEntity $object, array $hook): void {
		$this->jobList->add(
			job: \OCA\OpenRegister\BackgroundJob\HookRetryJob::class,
			argument: [
				'objectId' => $object->getId(),
				'schemaId' => $object->getSchema(),
				'hook' => $hook,
			]
		);
	}//end scheduleRetryJob()

	/**
	 * Log a hook execution and persist it to the WorkflowExecution entity.
	 *
	 * @param array<string, mixed> $hook Hook configuration
	 * @param string $eventType Event type
	 * @param ObjectEntity $object Object entity
	 * @param int|float $startTime Start time from hrtime(true)
	 * @param bool $success Whether execution succeeded
	 * @param string|null $error Error message if failed
	 * @param string|null $responseStatus Response status from workflow
	 * @param string|null $deliveryStatus Delivery status for async hooks
	 * @param array|null $payload Request payload (logged on failure)
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) hook, timing, event type, object, and 4 optional fields; extracted from logHookExecution
	 *
	 * @spec openspec/specs/schema-hooks/spec.md#requirement-hook-execution-order
	 */
	private function logHookExecution(
		array $hook,
		string $eventType,
		ObjectEntity $object,
		$startTime,
		bool $success,
		?string $error = null,
		?string $responseStatus = null,
		?string $deliveryStatus = null,
		?array $payload = null,
	): void {
		$durationMs = (int)((hrtime(true) - $startTime) / 1_000_000);
		$hookId = ($hook['id'] ?? 'unknown');
		$engineName = ($hook['engine'] ?? 'unknown');
		$workflowId = ($hook['workflowId'] ?? 'unknown');
		$mode = ($hook['mode'] ?? 'sync');
		$objectUuid = ($object->getUuid() ?? (string)$object->getId());

		$context = [
			'hookId' => $hookId,
			'eventType' => $eventType,
			'objectUuid' => $objectUuid,
			'engine' => $engineName,
			'workflowId' => $workflowId,
			'durationMs' => $durationMs,
		];

		if ($responseStatus !== null) {
			$context['responseStatus'] = $responseStatus;
		}

		if ($deliveryStatus !== null) {
			$context['deliveryStatus'] = $deliveryStatus;
		}

		$defaultStatus = 'error';
		if ($success === true) {
			$defaultStatus = 'approved';
		}

		$persistedStatus = $responseStatus ?? $deliveryStatus ?? $defaultStatus;

		$this->persistExecutionHistory(
			hook: $hook,
			eventType: $eventType,
			object: $object,
			objectUuid: $objectUuid,
			engineName: $engineName,
			workflowId: $workflowId,
			mode: $mode,
			persistedStatus: $persistedStatus,
			durationMs: $durationMs,
			error: $error,
			payload: $payload,
			context: $context
		);

		$this->logExecutionOutcome(
			success: $success,
			hookId: $hookId,
			eventType: $eventType,
			objectUuid: $objectUuid,
			durationMs: $durationMs,
			error: $error,
			payload: $payload,
			context: $context
		);
	}//end logHookExecution()

	/**
	 * Persist a hook execution record to the WorkflowExecution entity.
	 *
	 * @param array<string,mixed> $hook Hook configuration.
	 * @param string $eventType Event type.
	 * @param ObjectEntity $object The object the hook ran against.
	 * @param string $objectUuid Resolved object UUID string.
	 * @param string $engineName Engine name.
	 * @param string $workflowId Workflow id.
	 * @param string $mode Execution mode (sync/async).
	 * @param string $persistedStatus Status to persist.
	 * @param int $durationMs Execution duration in ms.
	 * @param string|null $error Error message, if any.
	 * @param array|null $payload Request payload.
	 * @param array<string,mixed> $context Log context array.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Extracted from logHookExecution() to reduce
	 *   NPath complexity; all params are distinct scalar/array values, no grouping reduces clarity.
	 */
	private function persistExecutionHistory(
		array $hook,
		string $eventType,
		ObjectEntity $object,
		string $objectUuid,
		string $engineName,
		string $workflowId,
		string $mode,
		string $persistedStatus,
		int $durationMs,
		?string $error,
		?array $payload,
		array $context,
	): void {
		$hookId = ($hook['id'] ?? 'unknown');

		$encodedErrors = null;
		if ($error !== null) {
			$encodedErrors = json_encode([['message' => $error]]);
		}

		$encodedPayload = null;
		if ($payload !== null) {
			$encodedPayload = json_encode($payload);
		}

		try {
			$this->executionMapper->createFromArray(
				[
					'hookId' => $hookId,
					'eventType' => $eventType,
					'objectUuid' => $objectUuid,
					'schemaId' => $object->getSchema(),
					'registerId' => $object->getRegister(),
					'engine' => $engineName,
					'workflowId' => $workflowId,
					'mode' => $mode,
					'status' => $persistedStatus,
					'durationMs' => $durationMs,
					'errors' => $encodedErrors,
					'metadata' => json_encode($context),
					'payload' => $encodedPayload,
					'executedAt' => new DateTime(),
				]
			);
		} catch (Exception $e) {
			// Persistence failure MUST NOT fail the original hook execution.
			$this->logger->warning(
				message: '[HookExecutor] Failed to persist execution history',
				context: ['hookId' => $hookId, 'error' => $e->getMessage()]
			);
		}//end try

	}//end persistExecutionHistory()

	/**
	 * Emit an info or error log line for a completed hook execution.
	 *
	 * @param bool $success Whether execution succeeded.
	 * @param string $hookId Hook id.
	 * @param string $eventType Event type.
	 * @param string $objectUuid Object UUID string.
	 * @param int $durationMs Execution duration in ms.
	 * @param string|null $error Error message, if any.
	 * @param array|null $payload Request payload (appended to error context).
	 * @param array<string,mixed> $context Base log context.
	 *
	 * @return void
	 */
	private function logExecutionOutcome(
		bool $success,
		string $hookId,
		string $eventType,
		string $objectUuid,
		int $durationMs,
		?string $error,
		?array $payload,
		array $context,
	): void {
		if ($success === true) {
			$this->logger->info(
				message: "[HookExecutor] Hook '$hookId' ok ($eventType on '$objectUuid', {$durationMs}ms)",
				context: $context
			);
			return;
		}

		$context['error'] = $error;

		if ($payload !== null) {
			$context['payload'] = $payload;
		}

		$this->logger->error(
			message: "[HookExecutor] Hook '$hookId' failed for $eventType on object '$objectUuid': $error ({$durationMs}ms)",
			context: $context
		);
	}//end logExecutionOutcome()
}//end class
