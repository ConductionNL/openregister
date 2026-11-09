<?php

/**
 * OpenRegister Bulk Job Action Call
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

/**
 * The bulk action a job runs, and the parameters it runs it with.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */
final readonly class BulkJobActionCall {
	/**
	 * Name the call.
	 *
	 * @param string               $actionId   The action to run.
	 * @param array<string, mixed> $parameters The action's parameters.
	 */
	public function __construct(
		public string $actionId,
		public array $parameters,
	) {
	}//end __construct()
}//end class
