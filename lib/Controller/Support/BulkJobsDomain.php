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

use OCA\OpenRegister\Db\BulkJobMapperInterface;
use OCA\OpenRegister\Service\BulkActionRegistry;
use OCA\OpenRegister\Service\BulkJob\BulkJobReversalInterface;
use OCA\OpenRegister\Service\BulkJob\BulkJobServiceInterface;

/**
 * Services of the bulk jobs API.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */
final class BulkJobsDomain {
	/**
	 * Bundle the services.
	 *
	 * @param BulkJobMapperInterface            $jobMapper Job persistence.
	 * @param BulkJobServiceInterface  $service   The lifecycle service.
	 * @param BulkJobReversalInterface $reversal  The inverse of a reversible job.
	 * @param BulkActionRegistry       $registry  The action catalogue.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function __construct(
		public readonly BulkJobMapperInterface $jobMapper,
		public readonly BulkJobServiceInterface $service,
		public readonly BulkJobReversalInterface $reversal,
		public readonly BulkActionRegistry $registry,
	) {
	}//end __construct()
}//end class
