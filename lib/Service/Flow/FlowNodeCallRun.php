<?php

/**
 * FlowNodeCallRun: one direct-invoked node call while it is being recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;

/**
 * The queued run, the hop being recorded, the context the node was handed and
 * how many items went in. Built by FlowRunService::runNode() and never leaves it.
 *
 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md
 */
final class FlowNodeCallRun {

	/**
	 * Constructor.
	 *
	 * @param FlowRun              $run     The run.
	 * @param FlowNodeHop          $hop     The hop being recorded.
	 * @param array<string, mixed> $context The context the node was handed.
	 * @param int                  $itemsIn How many items the node received.
	 */
	public function __construct(
		public readonly FlowRun $run,
		public readonly FlowNodeHop $hop,
		public readonly array $context,
		public readonly int $itemsIn,
	) {
	}//end __construct()
}//end class
