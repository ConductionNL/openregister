<?php

/**
 * OpenRegister OperatorCatalogue
 *
 * Publishes the JSON-AST calculation operator vocabulary: every operator the
 * evaluator dispatches on, with its arity, operand types, result type and a
 * sentence an expression builder can put beside it in a form.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

/**
 * The published operator catalogue.
 *
 * One source of truth. The descriptor table lives on
 * {@see CalculationEvaluator::OPERATORS}, directly beside the `match` that
 * dispatches on it, so an operator added to the evaluator is added to the
 * catalogue in the same edit and cannot be forgotten in a second file.
 * `OperatorCatalogueTest` reads the `match` arms out of the evaluator source
 * and fails when the two sets drift apart, which is what makes "generated from
 * the evaluator's own dispatch" a checkable claim rather than a comment.
 *
 * The annotation validator reads its operator vocabulary from here too, so a
 * schema can never be refused for an operator the catalogue advertises.
 */
final class OperatorCatalogue {

	/**
	 * Every operator, in a shape an expression builder can render.
	 *
	 * @return array<int, array{op: string, category: string, arity: string,
	 *   operands: array<int, string>, result: string, description: string}> The catalogue rows.
	 *
	 * @spec openspec/changes/computed-values-by-json-ast/specs/computed-fields/spec.md
	 */
	public function all(): array {
		$rows = [];
		foreach (CalculationEvaluator::OPERATORS as $op => $descriptor) {
			$rows[] = [
				'op' => (string)$op,
				'category' => $descriptor['category'],
				'arity' => $descriptor['arity'],
				'operands' => $descriptor['operands'],
				'result' => $descriptor['result'],
				'description' => $descriptor['description'],
			];
		}

		return $rows;
	}//end all()

	/**
	 * The operator keys alone.
	 *
	 * @return array<int, string> Operator keys in declaration order.
	 *
	 * @spec openspec/changes/computed-values-by-json-ast/specs/computed-fields/spec.md
	 */
	public function operators(): array {
		return array_map('strval', array_keys(CalculationEvaluator::OPERATORS));
	}//end operators()

	/**
	 * Whether the catalogue holds an operator.
	 *
	 * @param string $op The operator key to look up.
	 *
	 * @return bool True when the evaluator dispatches on this key.
	 *
	 * @spec openspec/changes/computed-values-by-json-ast/specs/computed-fields/spec.md
	 */
	public function has(string $op): bool {
		return array_key_exists($op, CalculationEvaluator::OPERATORS);
	}//end has()

	/**
	 * The categories the catalogue groups its operators under.
	 *
	 * @return array<int, string> Unique category names in declaration order.
	 *
	 * @spec openspec/changes/computed-values-by-json-ast/specs/computed-fields/spec.md
	 */
	public function categories(): array {
		$categories = [];
		foreach (CalculationEvaluator::OPERATORS as $descriptor) {
			if (in_array($descriptor['category'], $categories, true) === false) {
				$categories[] = $descriptor['category'];
			}
		}

		return $categories;
	}//end categories()
}//end class
