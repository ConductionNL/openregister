<?php

/**
 * OpenRegister bulk jobs domain
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\BulkJobMapper;
use OCA\OpenRegister\Service\BulkActionRegistry;
use OCA\OpenRegister\Service\BulkJob\BulkJobReversal;
use OCA\OpenRegister\Service\BulkJob\BulkJobService;

/**
 * Services of the bulk jobs API.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */
final class BulkJobsDomain {
	/**
	 * Bundle the services.
	 *
	 * @param BulkJobMapper      $jobMapper Job persistence.
	 * @param BulkJobService     $service   The lifecycle service.
	 * @param BulkJobReversal    $reversal  The inverse of a reversible job.
	 * @param BulkActionRegistry $registry  The action catalogue.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function __construct(
		public readonly BulkJobMapper $jobMapper,
		public readonly BulkJobService $service,
		public readonly BulkJobReversal $reversal,
		public readonly BulkActionRegistry $registry,
	) {
	}//end __construct()
}//end class
