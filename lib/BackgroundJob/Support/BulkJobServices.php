<?php

/**
 * OpenRegister bulk job services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
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

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCA\OpenRegister\Db\BulkJobMapperInterface;
use OCA\OpenRegister\Service\BulkJob\BulkJobServiceInterface;

/**
 * The bulk job store and the service that runs one slice of a bulk job.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */
final readonly class BulkJobServices {
	/**
	 * Bundle the services.
	 *
	 * @param BulkJobMapperInterface  $jobMapper      Bulk job store.
	 * @param BulkJobServiceInterface $bulkJobService Runs a slice.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function __construct(
		public BulkJobMapperInterface $jobMapper,
		public BulkJobServiceInterface $bulkJobService,
	) {
	}//end __construct()
}//end class
