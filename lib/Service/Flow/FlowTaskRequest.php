<?php

/**
 * A flow node's request to raise one human task for a run.
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
 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The task fields a node assembled, and the run, node and identity raising it.
 *
 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
 */
final class FlowTaskRequest {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data    The task fields the node assembled.
	 * @param string               $runUuid The run raising the task.
	 * @param string               $nodeId  The node raising it.
	 * @param string|null          $actor   The run's acting identity; the task's requester.
	 */
	public function __construct(
		public readonly array $data,
		public readonly string $runUuid,
		public readonly string $nodeId,
		public readonly ?string $actor,
	) {
	}//end __construct()
}//end class
