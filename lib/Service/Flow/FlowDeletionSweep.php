<?php

/**
 * OpenRegister Flow Deletion Sweep
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
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

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Db\FlowStateMapperInterface;

/**
 * What deleting a flow sweeps with it: its runs, those runs' step rows and its carried state.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
 */
final readonly class FlowDeletionSweep {

	/**
	 * Bundle the collaborators.
	 *
	 * @param FlowRunMapperInterface $runs Sweeps a deleted flow's runs.
	 * @param FlowRunStepMapper $steps Sweeps those runs' step rows.
	 * @param FlowStateMapperInterface $state Sweeps a deleted flow's carried state.
	 */
	public function __construct(
		public FlowRunMapperInterface $runs,
		public FlowRunStepMapper $steps,
		public FlowStateMapperInterface $state,
	) {
	}//end __construct()
}//end class
