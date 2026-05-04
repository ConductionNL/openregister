<?php

/**
 * OpenRegister ActionScheduleJob
 *
 * Background job for evaluating and executing scheduled actions.
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
 * @spec openspec/changes/retrofit-actions-2026-05-01/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use Cron\CronExpression;
use DateTime;
use OCA\OpenRegister\Db\ActionMapper;
use OCA\OpenRegister\Service\ActionExecutor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\EventDispatcher\Event;
use Psr\Log\LoggerInterface;

/**
 * Timed job that evaluates cron-scheduled actions and executes them when due
 *
 * Runs every 60 seconds. Queries all actions with non-null schedule field that
 * are enabled and active, evaluates their cron expressions, and executes via
 * ActionExecutor when due.
 *
 * @psalm-suppress UnusedClass
 */
class ActionScheduleJob extends TimedJob
{
    /**
     * Constructor
     *
     * @param ITimeFactory    $time           Time factory
     * @param ActionMapper    $actionMapper   Action mapper
     * @param ActionExecutor  $actionExecutor Action executor
     * @param LoggerInterface $logger         Logger
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-6
     */
    public function __construct(
        ITimeFactory $time,
        private readonly ActionMapper $actionMapper,
        private readonly ActionExecutor $actionExecutor,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: 60);
    }//end __construct()

    /**
     * Run the schedule evaluation
     *
     * @param mixed $argument Job arguments (unused)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-6
     * @spec openspec/changes/retrofit-actions-2026-05-01/tasks.md#task-5
     */
    protected function run($argument): void
    {
        try {
            $actions = $this->actionMapper->findAll(
                filters: [
                    'enabled'  => true,
                    'status'   => 'active',
                    'schedule' => 'IS NOT NULL',
                ]
            );

            // Further filter to ensure schedule is actually non-null (the mapper filter
            // uses IS NOT NULL which is correct, but double-check in PHP).
            $scheduledActions = array_filter(
                $actions,
                function ($action) {
                    return $action->getSchedule() !== null
                        && $action->getSchedule() !== ''
                        && $action->getDeleted() === null;
                }
            );

            $now = new DateTime();

            foreach ($scheduledActions as $action) {
                try {
                    /*
                     * @psalm-suppress UndefinedClass CronExpression is an optional runtime dependency
                     */

                    $cron = new CronExpression($action->getSchedule());

                    $lastExecuted = $action->getLastExecutedAt();
                    $isDue        = false;

                    if ($lastExecuted === null) {
                        $isDue = true;
                    } else {
                        /*
                         * @psalm-suppress UndefinedClass
                         */

                        $nextRun = $cron->getNextRunDate($lastExecuted);
                        $isDue   = $nextRun <= $now;
                    }

                    if ($isDue === false) {
                        continue;
                    }

                    $this->logger->info(
                        message: '[ActionScheduleJob] Executing scheduled action',
                        context: [
                            'actionId'   => $action->getId(),
                            'actionName' => $action->getName(),
                            'schedule'   => $action->getSchedule(),
                        ]
                    );

                    // Build synthetic scheduled event payload.
                    $payload = [
                        'schedule'  => $action->getSchedule(),
                        'schemas'   => $action->getSchemasArray(),
                        'registers' => $action->getRegistersArray(),
                    ];

                    // Create a synthetic event for scheduled execution.
                    $syntheticEvent = new Event();

                    $this->actionExecutor->executeActions(
                        actions: [$action],
                        event: $syntheticEvent,
                        payload: $payload,
                        eventType: 'nl.openregister.action.scheduled'
                    );
                } catch (\Exception $e) {
                    $this->logger->error(
                        message: '[ActionScheduleJob] Error executing scheduled action',
                        context: [
                            'actionId' => $action->getId(),
                            'error'    => $e->getMessage(),
                        ]
                    );
                }//end try
            }//end foreach
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[ActionScheduleJob] Error in schedule evaluation',
                context: ['error' => $e->getMessage()]
            );
        }//end try
    }//end run()
}//end class
