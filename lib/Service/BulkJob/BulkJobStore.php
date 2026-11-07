<?php

/**
 * OpenRegister Bulk Job Store
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\BulkJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\BulkJob;

use OCA\OpenRegister\Db\BulkJobMapper;
use OCA\OpenRegister\Db\BulkJobMemberMapper;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Where a bulk job lives: the job and member tables, the background queue and the log.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md#requirement-a-bulk-action-is-one-job-with-an-actor-a-selection-and-a-reason-req-baj-001
 */
final readonly class BulkJobStore {

	/**
	 * Bundle the collaborators.
	 *
	 * @param BulkJobMapper $jobMapper Job persistence.
	 * @param BulkJobMemberMapper $memberMapper Member persistence.
	 * @param IJobList $jobList The background queue.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		public BulkJobMapper $jobMapper,
		public BulkJobMemberMapper $memberMapper,
		public IJobList $jobList,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
