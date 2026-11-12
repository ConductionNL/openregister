<?php

/**
 * FlowNodeHop: one direct-invoked node call, as its log entry records it.
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

/**
 * The node, its step type and when it was called; builds the hop's log entry.
 *
 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md
 */
final class FlowNodeHop {

	/**
	 * Constructor.
	 *
	 * @param string $nodeId    The node that ran.
	 * @param string $stepType  The step's type.
	 * @param float  $startedAt `microtime(true)` when the node was called.
	 */
	public function __construct(
		public readonly string $nodeId,
		public readonly string $stepType,
		public readonly float $startedAt,
	) {
	}//end __construct()

	/**
	 * The hop's log entry: who ran, with what status, the detail, and how long it took until now.
	 *
	 * @param string               $status The outcome.
	 * @param array<string, mixed> $detail Outcome-specific keys, placed before the duration.
	 *
	 * @return array<string, mixed> The log entry.
	 *
	 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md
	 */
	public function logEntry(string $status, array $detail): array {
		return array_merge(
			['transition' => $this->nodeId, 'type' => $this->stepType, 'status' => $status],
			$detail,
			['durationMs' => (int)round((microtime(true) - $this->startedAt) * 1000)]
		);
	}//end logEntry()
}//end class
