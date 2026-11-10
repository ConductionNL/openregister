<?php

/**
 * One branch-pruning pass over a run's suspended user tasks.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-task-whose-run-or-branch-has-died-is-terminated-not-orphaned
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The resume state being pruned, the run walking, and the transition whose decision pruned it.
 *
 * Built by {@see FlowTaskMootness::placesPruned()} and never leaves that class.
 *
 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-task-whose-run-or-branch-has-died-is-terminated-not-orphaned
 */
final class FlowPruningRun {
	/**
	 * Constructor.
	 *
	 * @param FlowResumeState $state        The run's resume state; forgotten slots are visible to the walk.
	 * @param string          $runUuid      The run walking, empty when unknown.
	 * @param string          $byTransition The transition whose exit decision pruned the places.
	 */
	public function __construct(
		public readonly FlowResumeState $state,
		public readonly string $runUuid,
		public readonly string $byTransition,
	) {
	}//end __construct()
}//end class
