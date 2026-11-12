<?php

/**
 * The steps of a flow graph a token cannot leave.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Asks the preflight which steps of a graph are dead ends.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class FlowDeadEndSteps {

	/**
	 * Constructor.
	 *
	 * @param FlowNodePreflight $preflight Inspects a graph.
	 */
	public function __construct(
		private readonly FlowNodePreflight $preflight,
	) {
	}//end __construct()

	/**
	 * The ids of the steps a token cannot leave, in the order the preflight reports them.
	 *
	 * @param array<string, mixed> $graph The nodes and edges being judged.
	 *
	 * @return array<int, string> The step ids; empty when the graph is sound.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function inGraph(array $graph): array {
		$flow = ['nodes' => (array)($graph['nodes'] ?? []), 'edges' => (array)($graph['edges'] ?? [])];

		return self::stepsOf(findings: $this->preflight->inspect(flow: $flow));
	}//end inGraph()

	/**
	 * The step ids of the dead-end warnings among the findings.
	 *
	 * @param array<string, mixed> $findings The preflight findings.
	 *
	 * @return array<int, string> The step ids.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private static function stepsOf(array $findings): array {
		$steps = [];
		foreach (($findings['warnings'] ?? []) as $warning) {
			if (self::isDeadEnd(warning: $warning) === true) {
				$steps[] = (string)($warning['step'] ?? '');
			}
		}

		return $steps;
	}//end stepsOf()

	/**
	 * Whether a warning reports a dead end.
	 *
	 * @param mixed $warning The warning.
	 *
	 * @return bool True for a dead end.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private static function isDeadEnd(mixed $warning): bool {
		return ($warning['reason'] ?? '') === FlowNodePreflight::REASON_DEAD_END;
	}//end isDeadEnd()
}//end class
