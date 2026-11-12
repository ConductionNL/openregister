<?php

/**
 * OpenRegister RuleMatch
 *
 * The rules of a decision table that matched, with the outputs they fill.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dmn;

/**
 * What every hit-policy step of {@see DecisionTableEvaluator} reads.
 *
 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
 */
final class RuleMatch {

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>>              $rules           The matching rules, in declaration order.
	 * @param array<int, array{name: string, type: string}> $declaredOutputs The declared outputs, positionally aligned.
	 */
	public function __construct(
		public readonly array $rules,
		public readonly array $declaredOutputs,
	) {
	}//end __construct()

	/**
	 * The matching rules' ids, a rule without one named by its position among the matches.
	 *
	 * @return array<int, string> The ids.
	 *
	 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
	 */
	public function ids(): array {
		$ids = [];
		foreach ($this->rules as $position => $rule) {
			$ids[] = (string)($rule['id'] ?? $position);
		}

		return $ids;
	}//end ids()

	/**
	 * One rule's value per declared output, null where the rule gives none.
	 *
	 * @param array<string, mixed> $rule A matching rule.
	 *
	 * @return array<string, mixed> The outputs, keyed by output name.
	 *
	 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
	 */
	public function outputsOf(array $rule): array {
		$outputs = [];
		foreach ($this->declaredOutputs as $position => $declared) {
			$outputs[$declared['name']] = ($rule['outputEntries'][$position] ?? null);
		}

		return $outputs;
	}//end outputsOf()
}//end class
