<?php

/**
 * OpenRegister Bulk Job Pass
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

use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;

/**
 * One pass of a job's action over a hydrated selection, as the job's actor:
 * a preview rehearsal or one committed batch.
 *
 * Passed between the member helpers of {@see BulkJobExecutor}.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */
final readonly class BulkJobPass {
	/**
	 * Name the pass.
	 *
	 * @param BulkJob                     $job     The job.
	 * @param BulkActionInterface         $action  The action the job runs.
	 * @param IUser|null                  $actor   The user the job runs as.
	 * @param array<string, ObjectEntity> $objects The hydrated selection, keyed by uuid.
	 */
	public function __construct(
		public BulkJob $job,
		public BulkActionInterface $action,
		public ?IUser $actor,
		public array $objects,
	) {
	}//end __construct()

	/**
	 * The hydrated object for a uuid, or null when it is not readable.
	 *
	 * @param string $uuid The object uuid.
	 *
	 * @return ObjectEntity|null The object.
	 */
	public function object(string $uuid): ?ObjectEntity {
		return ($this->objects[$uuid] ?? null);
	}//end object()
}//end class
