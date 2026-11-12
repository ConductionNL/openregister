<?php

/**
 * OpenRegister Bulk Job Actions
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

use OCA\OpenRegister\Service\BulkActionRegistry;

/**
 * What a bulk job does: the catalogue of registered actions, the selection it resolves and the one executor.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md#requirement-a-bulk-action-is-one-job-with-an-actor-a-selection-and-a-reason-req-baj-001
 */
final readonly class BulkJobActions {

	/**
	 * Bundle the collaborators.
	 *
	 * @param BulkActionRegistry $registry The catalogue of registered actions.
	 * @param BulkSelectionResolver $resolver Selection resolution.
	 * @param BulkJobExecutor $executor The one executor.
	 */
	public function __construct(
		public BulkActionRegistry $registry,
		public BulkSelectionResolver $resolver,
		public BulkJobExecutor $executor,
	) {
	}//end __construct()
}//end class
