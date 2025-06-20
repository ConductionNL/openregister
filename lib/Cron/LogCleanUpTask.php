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
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Background job for cleaning up expired audit trail logs
 *
 * This job runs periodically to remove expired audit trail entries from the database
 * to prevent the database from growing indefinitely and maintain performance.
 *
 * @package OCA\OpenRegister\Cron
 */
class LogCleanUpTask extends TimedJob
{

	/**
	 * The audit trail mapper for database operations
	 *
	 * @var AuditTrailMapper
	 */
	private readonly AuditTrailMapper $auditTrailMapper;


	/**
	 * Constructor for the LogCleanUpTask
	 *
	 * @param ITimeFactory      $time              The time factory for time operations
	 * @param AuditTrailMapper  $auditTrailMapper  The audit trail mapper for database operations
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		AuditTrailMapper $auditTrailMapper,
	) {
		parent::__construct($time);
		$this->auditTrailMapper = $auditTrailMapper;

		// Run every hour (3600 seconds)
		$this->setInterval(3600);

		// Delay until low-load time
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);

		// Only run one instance of this job at a time
		$this->setAllowParallelRuns(false);
	}


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
			// Attempt to clear expired logs
			$logsCleared = $this->auditTrailMapper->clearLogs();
			
			// Log the result for monitoring purposes
			if ($logsCleared === true) {
				\OC::$server->getLogger()->info('Successfully cleared expired audit trail logs', [
					'app' => 'openregister'
				]);
			} else {
				\OC::$server->getLogger()->debug('No expired audit trail logs found to clear', [
					'app' => 'openregister'
				]);
			}
		} catch (\Exception $e) {
			// Log any errors that occur during cleanup
			\OC::$server->getLogger()->error('Failed to clear expired audit trail logs: ' . $e->getMessage(), [
				'app' => 'openregister',
				'exception' => $e
			]);
		}
	}
}
