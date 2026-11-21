<?php

/**
 * Where a flow run and everything it leaves behind is stored.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/flow-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\FlowRunStepMapperInterface;
use OCA\OpenRegister\Db\FlowStateMapperInterface;

/**
 * Where a flow run is stored: the run rows, the state that outlives a run, the per-node
 * step history and the stream persistence.
 *
 * @spec openspec/specs/flow-runs/spec.md
 */
final readonly class FlowRunStores {
	/**
	 * Bundle the collaborators.
	 *
	 * @param FlowRunMapperInterface          $mapper      Persists runs.
	 * @param FlowStateMapperInterface        $stateMapper Persists state that outlives a run.
	 * @param FlowRunStepMapperInterface|null $steps       Records one row per node execution; null records no history, never a faked one.
	 * @param FlowRunStreamStores             $streams     Stream rows, claims and the delta commit.
	 *
	 * @spec openspec/specs/flow-runs/spec.md
	 */
	public function __construct(
		public FlowRunMapperInterface $mapper,
		public FlowStateMapperInterface $stateMapper,
		public ?FlowRunStepMapperInterface $steps = null,
		public FlowRunStreamStores $streams = new FlowRunStreamStores(),
	) {
	}//end __construct()
}//end class
