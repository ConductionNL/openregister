<?php
/**
 * OpenRegister Log Cleanup Task
 *
 * This file contains the background job for cleaning up expired audit trail logs
 * in the OpenRegister application.
 *
 * @category Background Jobs
 * @package  OCA\OpenRegister\Cron
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Cron;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Background job for cleaning up expired audit trail logs
 *
 * This job runs periodically to remove expired audit trail entries from the database
 * to prevent the database from growing indefinitely and maintain performance.
 *
 * @package OCA\OpenRegister\Cron
 */
class ObjectCleanUpTask extends TimedJob
{

    /**
     * Constructor for the LogCleanUpTask
     *
     * @param ITimeFactory     $time             The time factory for time operations
     * @param LoggerInterface  $logger           The logger for logging operations
     *
     * @return void
     */
    public function __construct(
        ITimeFactory $time,
        private LoggerInterface $logger,
		private ObjectService $objectService
    ) {
        parent::__construct($time);

        // Run every 15 minutes (900 seconds)
        $this->setInterval(60);

        // Delay until low-load time
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);

        // Only run one instance of this job at a time
        $this->setAllowParallelRuns(false);

    }//end __construct()


    /**
     * Execute the log cleanup task
     *
     * This method is called by the Nextcloud background job system to clean up
     * expired audit trail logs from the database.
     *
     * @param mixed $argument The job argument (not used in this implementation)
     *
     * @return void
     */
    public function run(mixed $argument): void
    {
		try {
			$this->objectService->deleteExpiredObjects();
			$this->objectService->deleteExpiredObjects(); //second run to perform hard delete when delete-retention is small enough to enforce hard deletion.

			$this->logger->info('Successfully cleared expired objects', ['app' => 'openregister']);
		} catch (\Exception $e) {
			$this->logger->error('Failed to clear expired objects: '.$e->getMessage(), [
				'app'       => 'openregister',
				'exception' => $e,
			]);
		}

    }//end run()


}//end class
