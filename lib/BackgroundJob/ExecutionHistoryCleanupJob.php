<?php

/**
 * OpenRegister Execution History Cleanup Job
 *
 * Background job for pruning old workflow execution history records.
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
 * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-32
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use OCA\OpenRegister\Db\WorkflowExecutionMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * TimedJob that prunes old workflow execution history records.
 *
 * Runs once daily. Reads the retention period from IAppConfig
 * (key: workflow_execution_retention_days, default: 90).
 *
 * @psalm-suppress UnusedClass
 */
class ExecutionHistoryCleanupJob extends TimedJob
{
    /**
     * Default retention period in days.
     */
    private const DEFAULT_RETENTION_DAYS = 90;

    /**
     * Constructor for ExecutionHistoryCleanupJob.
     *
     * @param ITimeFactory            $time            Time factory
     * @param WorkflowExecutionMapper $executionMapper Execution mapper
     * @param IAppConfig              $appConfig       App configuration
     * @param LoggerInterface         $logger          Logger
     */
    public function __construct(
        ITimeFactory $time,
        private readonly WorkflowExecutionMapper $executionMapper,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);
        // Run once daily (86400 seconds).
        $this->setInterval(seconds: 86400);
    }//end __construct()

    /**
     * Execute the cleanup job.
     *
     * @param mixed $argument Job argument (unused for TimedJob)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($argument): void
    {
        $retentionDays = (int) $this->appConfig->getValueString(
            'openregister',
            'workflow_execution_retention_days',
            (string) self::DEFAULT_RETENTION_DAYS
        );

        if ($retentionDays <= 0) {
            $retentionDays = self::DEFAULT_RETENTION_DAYS;
        }

        $cutoff = new DateTime("-{$retentionDays} days");

        try {
            $deleted = $this->executionMapper->deleteOlderThan($cutoff);

            $this->logger->info(
                message: '[ExecutionHistoryCleanupJob] Pruned execution history',
                context: [
                    'retentionDays' => $retentionDays,
                    'deleted'       => $deleted,
                    'cutoff'        => $cutoff->format('c'),
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[ExecutionHistoryCleanupJob] Failed to prune execution history',
                context: ['error' => $e->getMessage()]
            );
        }
    }//end run()
}//end class
