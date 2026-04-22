<?php

/**
 * OpenRegister Scheduled Workflow Job
 *
 * Background job for executing scheduled workflows on their configured intervals.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ScheduledWorkflow;
use OCA\OpenRegister\Db\ScheduledWorkflowMapper;
use OCA\OpenRegister\Db\WorkflowExecutionMapper;
use OCA\OpenRegister\Service\WorkflowEngineRegistry;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * TimedJob that evaluates and executes scheduled workflows.
 *
 * Runs every 60 seconds. For each enabled scheduled workflow, checks if the
 * configured interval has elapsed since lastRun, and if so, executes the
 * workflow via the engine adapter.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ScheduledWorkflowJob extends TimedJob
{
    /**
     * Constructor for ScheduledWorkflowJob.
     *
     * @param ITimeFactory            $time            Time factory
     * @param ScheduledWorkflowMapper $workflowMapper  Scheduled workflow mapper
     * @param WorkflowEngineRegistry  $engineRegistry  Engine registry
     * @param WorkflowExecutionMapper $executionMapper Execution history mapper
     * @param LoggerInterface         $logger          Logger
     */
    public function __construct(
        ITimeFactory $time,
        private readonly ScheduledWorkflowMapper $workflowMapper,
        private readonly WorkflowEngineRegistry $engineRegistry,
        private readonly WorkflowExecutionMapper $executionMapper,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);
        // Run every 60 seconds; individual schedules are checked internally.
        $this->setInterval(seconds: 60);
    }//end __construct()

    /**
     * Execute the scheduled workflow evaluation.
     *
     * @param mixed $argument Job argument (unused for TimedJob)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($argument): void
    {
        $schedules = $this->workflowMapper->findAllEnabled();

        foreach ($schedules as $schedule) {
            try {
                $this->evaluateSchedule(schedule: $schedule);
            } catch (Exception $e) {
                $this->logger->error(
                    message: '[ScheduledWorkflowJob] Error processing schedule',
                    context: [
                        'scheduleId' => $schedule->getId(),
                        'name'       => $schedule->getName(),
                        'error'      => $e->getMessage(),
                    ]
                );
            }
        }
    }//end run()

    /**
     * Evaluate a single scheduled workflow and execute if due.
     *
     * @param ScheduledWorkflow $schedule The scheduled workflow entity
     *
     * @return void
     */
    private function evaluateSchedule(ScheduledWorkflow $schedule): void
    {
        $now     = new DateTime();
        $lastRun = $schedule->getLastRun();

        // Check if interval has elapsed since last run.
        if ($lastRun !== null) {
            $elapsed = ($now->getTimestamp() - $lastRun->getTimestamp());
            if ($elapsed < $schedule->getIntervalSec()) {
                return;
            }
        }

        $startTime  = hrtime(true);
        $engineType = $schedule->getEngine();

        try {
            $engines = $this->engineRegistry->getEnginesByType($engineType);
            if (empty($engines) === true) {
                $this->handleError(schedule: $schedule, startTime: $startTime, error: "No engine found for type '$engineType'");
                return;
            }

            $engine  = $engines[0];
            $adapter = $this->engineRegistry->resolveAdapter($engine);

            $payloadData = $schedule->getPayload() !== null ? (json_decode($schedule->getPayload(), true) ?? []) : [];

            $data = array_merge(
                    $payloadData,
                    [
                        'scheduledWorkflowId' => $schedule->getId(),
                        'registerId'          => $schedule->getRegisterId(),
                        'schemaId'            => $schedule->getSchemaId(),
                    ]
                    );

            $result = $adapter->executeWorkflow(
                workflowId: $schedule->getWorkflowId(),
                data: $data,
                timeout: 120
            );

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $schedule->setLastRun($now);
            $schedule->setLastStatus($result->getStatus());
            $schedule->setUpdated($now);
            $this->workflowMapper->update($schedule);

            // Persist execution history.
            $this->executionMapper->createFromArray(
                    [
                        'hookId'     => 'scheduled-'.$schedule->getId(),
                        'eventType'  => 'scheduled',
                        'objectUuid' => 'scheduled-'.$schedule->getUuid(),
                        'schemaId'   => $schedule->getSchemaId(),
                        'registerId' => $schedule->getRegisterId(),
                        'engine'     => $engineType,
                        'workflowId' => $schedule->getWorkflowId(),
                        'mode'       => 'sync',
                        'status'     => $result->getStatus(),
                        'durationMs' => $durationMs,
                        'errors'     => $result->isError() === true ? json_encode($result->getErrors()) : null,
                        'metadata'   => json_encode($result->getMetadata()),
                        'executedAt' => $now,
                    ]
                    );

            $this->logger->info(
                message: '[ScheduledWorkflowJob] Executed schedule',
                context: [
                    'scheduleId' => $schedule->getId(),
                    'name'       => $schedule->getName(),
                    'status'     => $result->getStatus(),
                    'durationMs' => $durationMs,
                ]
            );
        } catch (Exception $e) {
            $this->handleError(schedule: $schedule, startTime: $startTime, error: $e->getMessage());
        }//end try
    }//end evaluateSchedule()

    /**
     * Handle an error during scheduled workflow execution.
     *
     * @param ScheduledWorkflow $schedule  The scheduled workflow
     * @param int|float         $startTime Start time from hrtime
     * @param string            $error     Error message
     *
     * @return void
     */
    private function handleError(ScheduledWorkflow $schedule, $startTime, string $error): void
    {
        $now        = new DateTime();
        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        $schedule->setLastRun($now);
        $schedule->setLastStatus('error');
        $schedule->setUpdated($now);

        try {
            $this->workflowMapper->update($schedule);
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ScheduledWorkflowJob] Failed to update schedule after error',
                context: ['scheduleId' => $schedule->getId(), 'error' => $e->getMessage()]
            );
        }

        try {
            $this->executionMapper->createFromArray(
                    [
                        'hookId'     => 'scheduled-'.$schedule->getId(),
                        'eventType'  => 'scheduled',
                        'objectUuid' => 'scheduled-'.$schedule->getUuid(),
                        'schemaId'   => $schedule->getSchemaId(),
                        'registerId' => $schedule->getRegisterId(),
                        'engine'     => $schedule->getEngine(),
                        'workflowId' => $schedule->getWorkflowId(),
                        'mode'       => 'sync',
                        'status'     => 'error',
                        'durationMs' => $durationMs,
                        'errors'     => json_encode([['message' => $error]]),
                        'executedAt' => $now,
                    ]
                    );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ScheduledWorkflowJob] Failed to persist error execution',
                context: ['scheduleId' => $schedule->getId(), 'error' => $e->getMessage()]
            );
        }//end try

        $this->logger->error(
            message: '[ScheduledWorkflowJob] Schedule execution failed',
            context: [
                'scheduleId' => $schedule->getId(),
                'name'       => $schedule->getName(),
                'error'      => $error,
            ]
        );
    }//end handleError()
}//end class
