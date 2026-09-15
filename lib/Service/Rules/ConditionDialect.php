<?php

/**
 * OpenRegister ConditionDialect
 *
 * Decides which of the two condition dialects a node is written in, and
 * answers whether it holds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Flow\FlowExpression;
use Throwable;

/**
 * Which dialect, and does it hold.
 *
 * Two questions live here and they are one concern: JSONLogic writes `>` where
 * the JSON AST writes `gt`, so deciding the dialect and evaluating the node are
 * the same lookup made twice. Keeping them together means a node can never be
 * classified as one dialect and handed to the other's evaluator.
 *
 * It is separate from {@see ConditionTracer} because the tracer asks a
 * different question: not "is this true" but "which operand made it false".
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
final class ConditionDialect {

	/**
	 * The operator keys both dialects spell the same way.
	 *
	 * These are keys the AST catalogue also holds but that JSONLogic owns here:
	 * a node is JSONLogic unless it uses a spelling only the AST has.
	 *
	 * @var array<int, string>
	 */
	private const SHARED_SPELLINGS = ['and', 'or', 'not', '+', '-', '*', '/', '%', 'if'];

	/**
	 * Constructor.
	 *
	 * @param CalculationEvaluator $ast The JSON-AST evaluator.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CalculationEvaluator $ast,
	) {
	}//end __construct()

	/**
	 * Whether a node holds, in whichever dialect it is written.
	 *
	 * A node that cannot be evaluated does not hold. This is only ever asked
	 * about a clause whose parent has already been judged, so answering false
	 * for an unevaluable clause narrows the explanation rather than changing
	 * any verdict.
	 *
	 * @param mixed $node The condition node.
	 * @param array<string, mixed> $document The evaluation document.
	 *
	 * @return bool True when the node holds.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowExpression is the engine's stateless
	 *   JSONLogic facade; calling it statically IS the reuse.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function holds(mixed $node, array $document): bool {
		if (is_array($node) === false || $node === []) {
			return (bool)$node;
		}

		if ($this->isAst(op: (string)array_key_first($node)) === false) {
			return FlowExpression::isTrue(logic: $node, data: $document);
		}

		try {
			return (bool)$this->ast->evaluate($document, $node);
		} catch (Throwable $e) {
			return false;
		}
	}//end holds()

	/**
	 * Whether an operator key belongs to the JSON AST rather than to JSONLogic.
	 *
	 * @param string $op The operator key.
	 *
	 * @return bool True when the AST evaluator owns the key.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function isAst(string $op): bool {
		return self::isAstOperator(op: $op);
	}//end isAst()

	/**
	 * Whether an operator key belongs to the JSON AST rather than to JSONLogic.
	 *
	 * Static because it reads one constant table and nothing else, and because
	 * the schema save path validates a condition where no container is
	 * available to build a dialect from.
	 *
	 * @param string $op The operator key.
	 *
	 * @return bool True when the AST evaluator owns the key.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public static function isAstOperator(string $op): bool {
		return (in_array($op, self::SHARED_SPELLINGS, true) === false
			&& array_key_exists($op, CalculationEvaluator::OPERATORS) === true);
	}//end isAstOperator()

	/**
	 * Whether a node is a well-formed JSON-AST expression.
	 *
	 * The operator set is read from {@see CalculationEvaluator::OPERATORS},
	 * the same table the evaluator's `match` dispatches on, so an operator
	 * added to the evaluator is accepted here in the same edit and a condition
	 * can never be refused for an operator the engine would have run.
	 *
	 * `lit` is not descended into: its argument is a literal, and a literal
	 * that happens to be a single-key map is data, not an expression.
	 *
	 * @param mixed $node The node to check.
	 *
	 * @return bool True when every operator in the tree is one the evaluator holds.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public static function isWellFormedAst(mixed $node): bool {
		if (is_array($node) === false) {
			// A bare scalar is a literal operand, which is well formed.
			return true;
		}

		if (array_is_list($node) === true) {
			foreach ($node as $item) {
				if (self::isWellFormedAst(node: $item) === false) {
					return false;
				}
			}

			return true;
		}

		if (count($node) !== 1) {
			return false;
		}

		$op = (string)array_key_first($node);
		if (array_key_exists($op, CalculationEvaluator::OPERATORS) === false) {
			return false;
		}

		if ($op === 'lit') {
			return true;
		}

		return self::isWellFormedAst(node: $node[$op]);
	}//end isWellFormedAst()

	/**
	 * Whether a condition is well formed in whichever dialect it is written.
	 *
	 * The dialect is decided by the SAME lookup {@see self::holds()} makes, so
	 * the schema save can never accept a condition the save path would then
	 * hand to the other dialect's evaluator.
	 *
	 * @param mixed $node The condition node.
	 *
	 * @return bool True when the condition is a well-formed rule object.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowExpression is the engine's stateless
	 *   JSONLogic facade; calling it statically IS the reuse.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public static function isValidCondition(mixed $node): bool {
		if (is_array($node) === false || $node === []) {
			return false;
		}

		if (self::isAstOperator(op: (string)array_key_first($node)) === false) {
			return FlowExpression::isValid(logic: $node);
		}

		return self::isWellFormedAst(node: $node);
	}//end isValidCondition()
}//end class
