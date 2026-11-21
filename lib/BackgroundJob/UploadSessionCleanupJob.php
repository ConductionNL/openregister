<?php

/**
 * Hourly removal of upload sessions that expired before they were completed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-an-interrupted-upload-resumes-and-states-its-completeness-req-lfh-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\File\ChunkedUploadService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removes every upload session past its expiry, with its stored parts, and says how many.
 *
 * A zero in the log is a counted zero. A failure to list the expired sessions
 * is logged and rethrown, so the job list does not record a run that did not
 * happen; a session that cannot be removed is logged by the service and left
 * for the next run.
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-an-interrupted-upload-resumes-and-states-its-completeness-req-lfh-003
 */
class UploadSessionCleanupJob extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory         $time    The clock.
	 * @param ChunkedUploadService $uploads The upload sessions and their parts.
	 * @param LoggerInterface      $logger  Where the count goes.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ChunkedUploadService $uploads,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 3600);
	}//end __construct()

	/**
	 * Remove the expired sessions and log the count.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @throws Throwable When the expired sessions cannot be read, after logging it.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) TimedJob's signature.
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-3-2
	 */
	protected function run(mixed $argument): void {
		try {
			$removed = $this->uploads->removeExpired();
		} catch (Throwable $exception) {
			$this->logger->error(
				message: '[UploadSessionCleanupJob] Removing expired upload sessions failed',
				context: ['exception' => $exception->getMessage()]
			);
			throw $exception;
		}

		$this->logger->info(
			message: '[UploadSessionCleanupJob] removed expired upload sessions',
			context: ['removed' => $removed]
		);
	}//end run()
}//end class
