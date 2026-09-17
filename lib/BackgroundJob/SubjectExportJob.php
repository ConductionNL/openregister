<?php

/**
 * SubjectExportJob — assembles one data subject's own export, off the request.
 *
 * D-4: assembling everything an instance holds about a person walks every
 * register, and a request that times out is a request nobody answers. The
 * subject asks, the row is recorded pending, and this job turns it into a
 * ready export with an expiry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  BackgroundJob
 * @package   OCA\OpenRegister\BackgroundJob
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Gdpr\Export\SubjectExportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Assembles a queued subject export.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
 */
class SubjectExportJob extends QueuedJob {
	/**
	 * Wire the job.
	 *
	 * @param ITimeFactory         $time    The clock the job base class needs.
	 * @param SubjectExportService $exports The assembler.
	 * @param LoggerInterface      $logger  PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SubjectExportService $exports,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

	}//end __construct()

	/**
	 * Assemble the export named in the argument.
	 *
	 * @param mixed $argument The job argument, carrying `uuid`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	protected function run($argument): void {
		$uuid = '';
		if (is_array($argument) === true) {
			$uuid = trim((string)($argument['uuid'] ?? ''));
		}

		if ($uuid === '') {
			$this->logger->warning(
				message: '[SubjectExportJob] Queued with no export uuid, so there is nothing to assemble'
			);
			return;
		}

		try {
			$this->exports->assemble(uuid: $uuid);
		} catch (Throwable $e) {
			// The service records its own failure on the row. This catch keeps
			// one bad export from taking the job runner down with it.
			$this->logger->error(
				message: '[SubjectExportJob] The assembly threw past the service',
				context: ['uuid' => $uuid, 'error' => $e->getMessage()]
			);
		}
	}//end run()
}//end class
