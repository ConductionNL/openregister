<?php

/**
 * One pass of a user-task step: what it was handed, and which node it is.
 *
 * The items, the step configuration, the run context and the node id travel
 * together through every stage of {@see UserTaskNode} — creating the task,
 * reading it back, placing its outcome — so they are one value instead of
 * four arguments repeated on each method.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

/**
 * The items, configuration, run context and node id of one user-task pass.
 *
 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
 */
final class UserTaskStep {

	/**
	 * Constructor.
	 *
	 * @param array                $items   The input items; the first is the representative.
	 * @param array<string, mixed> $config  The step configuration.
	 * @param array<string, mixed> $context Run-level metadata.
	 * @param string               $nodeId  The node raising the task.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
	 */
	public function __construct(
		public readonly array $items,
		public readonly array $config,
		public readonly array $context = [],
		public readonly string $nodeId = '',
	) {

	}//end __construct()
}//end class
