<?php

/**
 * OpenRegister ActionExecutor Service
 *
 * Orchestrates action execution for lifecycle events.
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

use Exception;
use OCA\OpenRegister\Db\Action;
use OCA\OpenRegister\Db\ActionLog;
use OCA\OpenRegister\Db\ActionLogMapper;
use OCA\OpenRegister\Db\ActionMapper;
use OCA\OpenRegister\BackgroundJob\ActionRetryJob;
use OCA\OpenRegister\Service\Webhook\CloudEventFormatter;
use OCA\OpenRegister\WorkflowEngine\WorkflowResult;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use Psr\Log\LoggerInterface;

/**
 * ActionExecutor orchestrates action execution for events
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class ActionExecutor
{
    /**
     * Constructor
     *
     * @param WorkflowEngineRegistry $engineRegistry      Engine registry
     * @param CloudEventFormatter    $cloudEventFormatter CloudEvent formatter
     * @param ActionLogMapper        $actionLogMapper     Action log mapper
     * @param ActionService          $actionService       Action service for statistics
     * @param IJobList               $jobList             Job list for retry queue
     * @param LoggerInterface        $logger              Logger
     */
    public function __construct(
        private readonly WorkflowEngineRegistry $engineRegistry,
        private readonly CloudEventFormatter $cloudEventFormatter,
        private readonly ActionLogMapper $actionLogMapper,
        private readonly ActionService $actionService,
        private readonly IJobList $jobList,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Execute a list of matching actions for an event
     *
     * @param Action[] $actions   Sorted actions to execute
     * @param Event    $event     The triggering event
     * @param array    $payload   Event payload data
     * @param string   $eventType Event type string
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function executeActions(array $actions, Event $event, array $payload, string $eventType): void
    {
        foreach ($actions as $action) {
            // Check if propagation was stopped by a previous action or inline hook.
            if (method_exists($event, 'isPropagationStopped') === true && $event->isPropagationStopped() === true) {
                $this->logger->debug(
                    message: '[ActionExecutor] Propagation stopped, skipping remaining actions',
                    context: ['skippedAction' => $action->getName()]
                );
                break;
            }

            $this->executeSingleAction(action: $action, event: $event, payload: $payload, eventType: $eventType);
        }//end foreach
    }//end executeActions()

    /**
     * Execute a single action
     *
     * @param Action $action    The action to execute
     * @param Event  $event     The triggering event
     * @param array  $payload   Event payload data
     * @param string $eventType Event type string
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function executeSingleAction(Action $action, Event $event, array $payload, string $eventType): void
    {
        $startTime = microtime(true);
        $status    = 'success';
        $error     = null;
        $response  = null;

        try {
            // Build CloudEvents payload.
            $cloudEventPayload = $this->buildCloudEventPayload(action: $action, payload: $payload, eventType: $eventType);

            // Resolve engine adapter.
            $engine = $this->engineRegistry->getEngine((int) $action->getEngine());
            if ($engine === null) {
                throw new Exception("Engine '{$action->getEngine()}' not available");
            }

            // Execute workflow.
            if ($action->getMode() === 'async') {
                // Fire-and-forget: execute but don't process response for event modification.
                try {
                    $result   = $engine->execute(
                        $action->getWorkflowId(),
                        $cloudEventPayload,
                        $action->getTimeout()
                    );
                    $response = $result instanceof WorkflowResult ? $result->toArray() : (array) $result;
                } catch (Exception $e) {
                    $status = 'failure';
                    $error  = $e->getMessage();
                    $this->handleFailure(action: $action, payload: $cloudEventPayload, error: $error);
                }
            } else {
                // Sync mode: execute and process response.
                $result = $engine->execute(
                    $action->getWorkflowId(),
                    $cloudEventPayload,
                    $action->getTimeout()
                );

                if ($result instanceof WorkflowResult) {
                    $response = $result->toArray();
                    $this->processWorkflowResult(result: $result, action: $action, event: $event);
                } else {
                    $response = (array) $result;
                }
            }//end if
        } catch (Exception $e) {
            $status = 'failure';
            $error  = $e->getMessage();

            $this->logger->error(
                message: '[ActionExecutor] Action execution failed',
                context: [
                    'actionId'   => $action->getId(),
                    'actionName' => $action->getName(),
                    'error'      => $e->getMessage(),
                ]
            );

            $this->handleFailure(action: $action, payload: $payload, error: $error);
        }//end try

        // Calculate duration.
        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        // Create log entry.
        $this->createLogEntry(
            action: $action,
            eventType: $eventType,
            payload: $payload,
            response: $response,
            status: $status,
            durationMs: $durationMs,
            error: $error
        );

        // Update statistics.
        $this->actionService->updateStatistics($action->getId(), $status);
    }//end executeSingleAction()

    /**
     * Build CloudEvent payload for an action execution
     *
     * @param Action $action    The action being executed
     * @param array  $payload   Event payload data
     * @param string $eventType Event type string
     *
     * @return array The CloudEvent-formatted payload
     */
    public function buildCloudEventPayload(Action $action, array $payload, string $eventType): array
    {
        return [
            'specversion'     => '1.0',
            'type'            => 'nl.openregister.action.'.$eventType,
            'source'          => '/openregister/actions/'.$action->getUuid(),
            'id'              => \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
            'time'            => (new \DateTime())->format('c'),
            'datacontenttype' => 'application/json',
            'data'            => $payload,
            'action'          => [
                'id'         => $action->getId(),
                'uuid'       => $action->getUuid(),
                'name'       => $action->getName(),
                'engine'     => $action->getEngine(),
                'workflowId' => $action->getWorkflowId(),
                'mode'       => $action->getMode(),
            ],
        ];
    }//end buildCloudEventPayload()

    /**
     * Process a workflow result from sync execution
     *
     * @param WorkflowResult $result The workflow result
     * @param Action         $action The action that was executed
     * @param Event          $event  The original event
     *
     * @return void
     */
    private function processWorkflowResult(WorkflowResult $result, Action $action, Event $event): void
    {
        if ($result->isRejected() === true) {
            $this->logger->info(
                message: '[ActionExecutor] Action rejected operation',
                context: ['actionName' => $action->getName()]
            );

            // Stop propagation for pre-mutation events.
            if (method_exists($event, 'stopPropagation') === true) {
                $event->stopPropagation();
            }

            if (method_exists($event, 'setErrors') === true) {
                $event->setErrors($result->getErrors());
            }
        }

        if ($result->isModified() === true && method_exists($event, 'setModifiedData') === true) {
            $event->setModifiedData($result->getModifiedData());
        }
    }//end processWorkflowResult()

    /**
     * Handle action execution failure based on failure mode
     *
     * @param Action $action  The failed action
     * @param array  $payload The payload that was being sent
     * @param string $error   The error message
     *
     * @return void
     */
    private function handleFailure(Action $action, array $payload, string $error): void
    {
        $failureMode = $action->getOnFailure();

        if ($failureMode === 'queue' || $action->getOnEngineDown() === 'queue') {
            $this->jobList->add(
                    ActionRetryJob::class,
                    [
                        'action_id'    => $action->getId(),
                        'payload'      => $payload,
                        'attempt'      => 2,
                        'max_retries'  => $action->getMaxRetries(),
                        'retry_policy' => $action->getRetryPolicy(),
                        'error'        => $error,
                    ]
                    );

            $this->logger->info(
                message: '[ActionExecutor] Failed action queued for retry',
                context: ['actionId' => $action->getId(), 'actionName' => $action->getName()]
            );
        }
    }//end handleFailure()

    /**
     * Create an ActionLog entry for an execution
     *
     * @param Action      $action     The action that was executed
     * @param string      $eventType  Event type
     * @param array       $payload    Request payload
     * @param array|null  $response   Response payload
     * @param string      $status     Execution status
     * @param int         $durationMs Duration in milliseconds
     * @param string|null $error      Error message if failed
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Log entries require many fields
     */
    private function createLogEntry(
        Action $action,
        string $eventType,
        array $payload,
        ?array $response,
        string $status,
        int $durationMs,
        ?string $error
    ): void {
        try {
            $log = new ActionLog();
            $log->setActionId($action->getId());
            $log->setActionUuid($action->getUuid());
            $log->setEventType($eventType);
            $log->setObjectUuid($payload['data']['object']['uuid'] ?? $payload['objectUuid'] ?? null);
            $log->setSchemaId(isset($payload['data']['schema']) === true ? (int) $payload['data']['schema'] : null);
            $log->setRegisterId(isset($payload['data']['register']) === true ? (int) $payload['data']['register'] : null);
            $log->setEngine($action->getEngine());
            $log->setWorkflowId($action->getWorkflowId());
            $log->setStatus($status);
            $log->setDurationMs($durationMs);
            $log->setRequestPayload(json_encode($payload));
            $log->setResponsePayload($response !== null ? json_encode($response) : null);
            $log->setErrorMessage($error);

            $this->actionLogMapper->insert(entity: $log);
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ActionExecutor] Failed to create action log entry',
                context: ['error' => $e->getMessage()]
            );
        }//end try
    }//end createLogEntry()
}//end class
