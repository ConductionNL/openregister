<?php

/**
 * OpenRegister ActionRetryJob
 *
 * Background job for retrying failed action executions.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-32
 * @spec openspec/changes/retrofit-actions-2026-05-01/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use Exception;
use OCA\OpenRegister\Db\ActionLog;
use OCA\OpenRegister\Db\ActionLogMapper;
use OCA\OpenRegister\Db\ActionMapper;
use OCA\OpenRegister\Service\ActionExecutor;
use OCA\OpenRegister\Service\ActionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\EventDispatcher\Event;
use Psr\Log\LoggerInterface;

/**
 * Queued job for retrying failed action executions with backoff
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ActionRetryJob extends QueuedJob
{
    /**
     * Constructor
     *
     * @param ITimeFactory    $time            Time factory
     * @param ActionMapper    $actionMapper    Action mapper
     * @param ActionExecutor  $actionExecutor  Action executor
     * @param ActionLogMapper $actionLogMapper Action log mapper
     * @param ActionService   $actionService   Action service
     * @param IJobList        $jobList         Job list for re-queuing
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        ITimeFactory $time,
        private readonly ActionMapper $actionMapper,
        private readonly ActionExecutor $actionExecutor,
        private readonly ActionLogMapper $actionLogMapper,
        private readonly ActionService $actionService,
        private readonly IJobList $jobList,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);
    }//end __construct()

    /**
     * Run the retry job
     *
     * @param mixed $arguments Job arguments containing action_id, payload, attempt, max_retries, retry_policy
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/changes/retrofit-actions-2026-05-01/tasks.md#task-4
     */
    protected function run($arguments): void
    {
        $actionId    = $arguments['action_id'] ?? 0;
        $payload     = $arguments['payload'] ?? [];
        $attempt     = $arguments['attempt'] ?? 2;
        $maxRetries  = $arguments['max_retries'] ?? 3;
        $retryPolicy = $arguments['retry_policy'] ?? 'exponential';

        try {
            $action = $this->actionMapper->find(id: $actionId);
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ActionRetryJob] Action not found for retry',
                context: ['actionId' => $actionId, 'error' => $e->getMessage()]
            );
            return;
        }

        // Check if max retries exceeded.
        if ($attempt > $maxRetries) {
            $this->logger->warning(
                message: '[ActionRetryJob] Max retries exceeded, abandoning action',
                context: [
                    'actionId'   => $actionId,
                    'actionName' => $action->getName(),
                    'attempt'    => $attempt,
                    'maxRetries' => $maxRetries,
                ]
            );

            // Create final log entry with abandoned status.
            $log = new ActionLog();
            $log->setActionId($action->getId());
            $log->setActionUuid($action->getUuid());
            $log->setEventType('retry');
            $log->setEngine($action->getEngine());
            $log->setWorkflowId($action->getWorkflowId());
            $log->setStatus('abandoned');
            $log->setAttempt($attempt);
            $log->setErrorMessage('Max retries exceeded ('.$maxRetries.')');
            $log->setRequestPayload(json_encode($payload));

            $this->actionLogMapper->insert(entity: $log);
            $this->actionService->updateStatistics($actionId, 'abandoned');

            return;
        }//end if

        $this->logger->info(
            message: '[ActionRetryJob] Retrying action execution',
            context: [
                'actionId' => $actionId,
                'attempt'  => $attempt,
            ]
        );

        try {
            // Execute the action.
            $syntheticEvent = new Event();

            $this->actionExecutor->executeActions(
                actions: [$action],
                event: $syntheticEvent,
                payload: $payload,
                eventType: 'retry'
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ActionRetryJob] Retry failed, re-queuing',
                context: [
                    'actionId' => $actionId,
                    'attempt'  => $attempt,
                    'error'    => $e->getMessage(),
                ]
            );

            // Re-queue with incremented attempt.
            $this->jobList->add(
                    self::class,
                    [
                        'action_id'    => $actionId,
                        'payload'      => $payload,
                        'attempt'      => ($attempt + 1),
                        'max_retries'  => $maxRetries,
                        'retry_policy' => $retryPolicy,
                        'error'        => $e->getMessage(),
                    ]
                    );
        }//end try
    }//end run()

    /**
     * Calculate retry delay in seconds based on retry policy
     *
     * @param string $policy  Retry policy (exponential, linear, fixed)
     * @param int    $attempt Current attempt number
     *
     * @return int Delay in seconds
     *
     * @spec openspec/changes/retrofit-actions-2026-05-01/tasks.md#task-4
     */
    public static function calculateDelay(string $policy, int $attempt): int
    {
        return match ($policy) {
            'exponential' => (int) pow(2, $attempt) * 60,
            'linear'      => $attempt * 300,
            'fixed'       => 300,
            default       => 300,
        };
    }//end calculateDelay()
}//end class
