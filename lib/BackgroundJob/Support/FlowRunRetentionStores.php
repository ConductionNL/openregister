<?php

/**
 * OpenRegister flow run retention stores
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

use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\FlowRunStepMapperInterface;

/**
 * The flow-run history the retention job prunes: runs, their steps, and the
 * flows that declare their own retention period.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
 */
final readonly class FlowRunRetentionStores {
	/**
	 * Bundle the stores.
	 *
	 * @param FlowRunMapperInterface     $runs  Deletes expired runs.
	 * @param FlowRunStepMapperInterface $steps Deletes those runs' step rows.
	 * @param FlowMapperInterface        $flows Finds the flows declaring an override.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	public function __construct(
		public FlowRunMapperInterface $runs,
		public FlowRunStepMapperInterface $steps,
		public FlowMapperInterface $flows,
	) {
	}//end __construct()
}//end class
