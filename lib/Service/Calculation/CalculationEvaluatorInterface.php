<?php

/**
 * CalculationEvaluatorInterface: the public surface of CalculationEvaluator, for callers to type against.
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
 * Every public method of CalculationEvaluator. Callers depend on this interface, not on the class.
 */
interface CalculationEvaluatorInterface {

	/**
	 * Evaluate an expression against an object payload.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $expression Expression AST (scalar literal or array).
	 * @param SequenceContext|null $sequenceContext Consume-sequences context; non-null ONLY on
	 *                                              the create path. When null, `sequence` nodes
	 *                                              resolve to null instead of reserving a number.
	 *
	 * @return mixed The computed value.
	 *
	 * @throws EvaluationException When the expression is malformed or references unknown properties/operators.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-1
	 */
	public function evaluate(array $object, mixed $expression, ?SequenceContext $sequenceContext = null): mixed;

	/**
	 * Whether an expression tree contains a `sequence` node.
	 *
	 * A `sequence` reserves its running number exactly once, on create, through
	 * the per-save SequenceContext. Off that path {@see CalculationEvaluator::sequence()}
	 * deliberately returns null — which is correct for the evaluator but
	 * catastrophic for a caller that PERSISTS the result: `concat` renders the
	 * null as an empty string, so a declared identifier such as
	 * `concat(year(startDate), "-", sequence(...))` recomputes to "2026-" and
	 * overwrites the number assigned at create.
	 *
	 * Every caller that materialises a calculation must therefore skip a
	 * sequence-bearing expression while no context is active, leaving the
	 * stored value untouched. See openregister#3075.
	 *
	 * @param mixed $expression Expression AST (any node).
	 *
	 * @return bool True when a `sequence` operator appears anywhere in the tree.
	 *
	 * @spec openspec/specs/computed-fields/spec.md#requirement-save-time-evaluation
	 */
	public function expressionUsesSequence(mixed $expression): bool;

	/**
	 * The properties an expression reads, derived from the expression itself.
	 *
	 * A `dependsOn` list written beside an expression is a second source of
	 * truth, and it fails silently: a dependency the author forgot is a value
	 * that never refreshes. The AST is a tree, so the answer is derivable, and
	 * this is the one place that derives it. The annotation validator's cycle
	 * check runs on the list this produces.
	 *
	 * Names are returned exactly as the expression writes them, including the
	 * `@self.`, `@ref.` and `@aggregate.` prefixes, in first-seen order with
	 * duplicates removed.
	 *
	 * @param mixed $expression Expression AST (any node).
	 *
	 * @return array<int, string> The property names the expression reads.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function referencedProperties(mixed $expression): array;
}//end interface
