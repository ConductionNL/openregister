<?php

/**
 * What a decision-table step runs each item through, resolved once per pass.
 *
 * The resolved table and the step's mappings, result key and default row
 * travel together from {@see DecisionTableNode::execute()} into the per-item
 * decision, so they are one value instead of a list of arguments.
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
 * @spec openspec/specs/flow-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

/**
 * A resolved table plus the step configuration it is read through.
 *
 * @spec openspec/specs/flow-decision-tables/spec.md
 */
final class DecisionTablePlan {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $table  The resolved table definition.
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @spec openspec/specs/flow-decision-tables/spec.md
	 */
	public function __construct(
		public readonly array $table,
		private readonly array $config,
	) {

	}//end __construct()

	/**
	 * Declared input name to field path.
	 *
	 * @return array<string, mixed> The mapping, empty when none is configured.
	 *
	 * @spec openspec/specs/flow-decision-tables/spec.md
	 */
	public function inputMapping(): array {
		return (array)($this->config['inputMapping'] ?? []);
	}//end inputMapping()

	/**
	 * Declared output name to field path.
	 *
	 * @return array<string, mixed> The mapping, empty when none is configured.
	 *
	 * @spec openspec/specs/flow-decision-tables/spec.md
	 */
	public function outputMapping(): array {
		return (array)($this->config['outputMapping'] ?? []);
	}//end outputMapping()

	/**
	 * Where the evaluation summary goes.
	 *
	 * @return string The trimmed key, or '' for nowhere.
	 *
	 * @spec openspec/specs/flow-decision-tables/spec.md
	 */
	public function resultKey(): string {
		return trim((string)($this->config['resultKey'] ?? ''));
	}//end resultKey()

	/**
	 * The author's no-match row, exactly as configured.
	 *
	 * @return mixed The row; only an array counts as a default.
	 *
	 * @spec openspec/specs/flow-decision-tables/spec.md
	 */
	public function defaultOutputs(): mixed {
		return ($this->config['defaultOutputs'] ?? null);
	}//end defaultOutputs()
}//end class
