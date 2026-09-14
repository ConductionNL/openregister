<?php

/**
 * OpenRegister ConditionTracer
 *
 * Finds the first operand that made a rule's condition false, with the value
 * that operand read.
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

use Throwable;

/**
 * Turns "did not match" into a fact an administrator can act on.
 *
 * WHY THIS IS A SEPARATE CLASS AND NOT A FLAG ON THE EVALUATORS. There are two
 * condition dialects in the engine, JSONLogic (`{">": [{"var": "bedrag"}, 500]}`)
 * and the JSON AST (`{"gt": [{"prop": "bedrag"}, 500]}`), and per D-7 both stay.
 * Tracing is the same walk over both: descend into the first clause that is
 * false and name its first reference operand. Written into each evaluator it
 * would be the same walk twice, and the two copies would answer differently
 * about the same rule within a release.
 *
 * WHAT IT DOES NOT DO. It never decides the verdict. The engine that owns the
 * rule decides that, and hands the answer in, so a trace can never disagree
 * with the refusal the caller actually received. This class only explains a
 * decision already taken, which is why a tracer that throws is caught by the
 * caller and costs a trace rather than a save.
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
final class ConditionTracer {

	/**
	 * The JSONLogic operators whose falseness a reference operand explains.
	 *
	 * An `and` is not here: an `and` is false because one of its clauses is,
	 * and the clause is the useful answer, so the walk descends instead.
	 *
	 * @var array<int, string>
	 */
	private const COMPARISONS = [
		'==', '===', '!=', '!==', '>', '>=', '<', '<=',
		'in', 'eq', 'ne', 'lt', 'lte', 'gt', 'gte',
	];

	/**
	 * The keys that read a value off the document, in both dialects.
	 *
	 * @var array<int, string>
	 */
	private const REFERENCES = ['var', 'prop'];

	/**
	 * Constructor.
	 *
	 * @param ConditionDialect $dialect Decides which dialect a clause is in and whether it holds.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ConditionDialect $dialect,
	) {
	}//end __construct()

	/**
	 * The trace for a condition that has already been judged.
	 *
	 * @param mixed $condition The condition as the author declared it.
	 * @param array<string, mixed> $document The data the condition was evaluated against.
	 * @param string $verdict The verdict the owning engine reached.
	 * @param string|null $message The engine's own sentence, when it has one.
	 *
	 * @return RuleTrace The verdict with its deciding operand, when one decided.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) RuleTrace::renderValue is a pure rendering
	 *   function on an immutable value object, not a collaborator: injecting the value
	 *   object's own formatter would be a seam with exactly one possible implementation.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function trace(mixed $condition, array $document, string $verdict, ?string $message = null): RuleTrace {
		if ($verdict === RuleVocabulary::VERDICT_FIRED || is_array($condition) === false || $condition === []) {
			return new RuleTrace(verdict: $verdict, message: $message);
		}

		try {
			$deciding = $this->decidingOperand(node: $condition, document: $document);
		} catch (Throwable $e) {
			// A trace is a courtesy on a decision already taken. Losing it must
			// never turn a refusal into an error, so the walk's failure costs
			// the operand and nothing else.
			return new RuleTrace(verdict: $verdict, message: $message);
		}

		if ($deciding === null) {
			return new RuleTrace(verdict: $verdict, message: $message);
		}

		return new RuleTrace(
			verdict: $verdict,
			operand: $deciding['operand'],
			operandValue: RuleTrace::renderValue(value: $deciding['value']),
			message: $message
		);
	}//end trace()

	/**
	 * Walk a condition node and name the operand that made it false.
	 *
	 * @param array<string, mixed> $node The condition node.
	 * @param array<string, mixed> $document The evaluation document.
	 *
	 * @return array{operand: string, value: mixed}|null The deciding operand, or null when none is nameable.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function decidingOperand(array $node, array $document): ?array {
		if (count($node) !== 1) {
			return null;
		}

		$op = (string)array_key_first($node);
		$args = $node[$op];

		if (is_array($args) === true) {
			// `and` and `or` are the two shapes whose falseness is explained by
			// a clause rather than by an operand, so each has its own descent.
			if ($op === 'and') {
				return $this->decidingClauseOfAnd(clauses: $args, document: $document);
			}

			if ($op === 'or') {
				return $this->decidingClauseOfOr(clauses: $args, document: $document);
			}
		}

		if ($op === '!' || $op === 'not') {
			return $this->operandOf(node: $this->negated(args: $args), document: $document);
		}

		if (in_array($op, self::COMPARISONS, true) === true && is_array($args) === true) {
			return $this->firstReference(args: $args, document: $document);
		}

		return $this->firstReference(args: [$node], document: $document);
	}//end decidingOperand()

	/**
	 * The operand of the first clause of an `and` that does not hold.
	 *
	 * An `and` is false because one of its clauses is, and the clause is the
	 * useful answer, so this evaluates clauses in order until one fails and
	 * then explains that one.
	 *
	 * @param array<int|string, mixed> $clauses The `and`'s clauses.
	 * @param array<string, mixed> $document The evaluation document.
	 *
	 * @return array{operand: string, value: mixed}|null The deciding operand, or null.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function decidingClauseOfAnd(array $clauses, array $document): ?array {
		foreach ($clauses as $clause) {
			if ($this->dialect->holds(node: $clause, document: $document) === true) {
				continue;
			}

			return $this->operandOf(node: $clause, document: $document);
		}

		return null;
	}//end decidingClauseOfAnd()

	/**
	 * The operand of the first clause of an `or`.
	 *
	 * Every clause of a false `or` is false, so the first one is as good an
	 * answer as any and is the one the author wrote first.
	 *
	 * @param array<int|string, mixed> $clauses The `or`'s clauses.
	 * @param array<string, mixed> $document The evaluation document.
	 *
	 * @return array{operand: string, value: mixed}|null The deciding operand, or null.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function decidingClauseOfOr(array $clauses, array $document): ?array {
		if ($clauses === []) {
			return null;
		}

		return $this->operandOf(node: $clauses[array_key_first($clauses)], document: $document);
	}//end decidingClauseOfOr()

	/**
	 * The clause a negation wraps, whether it is written as a list or bare.
	 *
	 * @param mixed $args The negation's argument.
	 *
	 * @return mixed The wrapped clause.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function negated(mixed $args): mixed {
		if (is_array($args) === true && array_is_list($args) === true && $args !== []) {
			return $args[0];
		}

		return $args;
	}//end negated()

	/**
	 * The deciding operand of an arbitrary clause.
	 *
	 * @param mixed $node The clause.
	 * @param array<string, mixed> $document The evaluation document.
	 *
	 * @return array{operand: string, value: mixed}|null The deciding operand, or null.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function operandOf(mixed $node, array $document): ?array {
		if (is_array($node) === false || $node === []) {
			return null;
		}

		return $this->decidingOperand(node: $node, document: $document);
	}//end operandOf()

	/**
	 * The first reference operand inside a list of arguments, with its value.
	 *
	 * A comparison is written reference-first by convention and by every
	 * example in the specs, so the first reference is the property the author
	 * was reasoning about. A comparison between two literals names neither.
	 *
	 * @param array<int|string, mixed> $args The argument list.
	 * @param array<string, mixed> $document The evaluation document.
	 *
	 * @return array{operand: string, value: mixed}|null The reference and its value, or null.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function firstReference(array $args, array $document): ?array {
		foreach ($args as $arg) {
			if (is_array($arg) === false || count($arg) !== 1) {
				continue;
			}

			$key = (string)array_key_first($arg);
			if (in_array($key, self::REFERENCES, true) === false) {
				// A nested expression may hold the reference: `{"gt": [{"-": [{"prop": "a"}, 1]}, 5]}`.
				$nested = $this->firstReference(args: (array)($arg[$key] ?? []), document: $document);
				if ($nested !== null) {
					return $nested;
				}

				continue;
			}

			$path = $this->pathOf(reference: $arg[$key]);
			if ($path === null) {
				continue;
			}

			return ['operand' => $path, 'value' => $this->read(path: $path, document: $document)];
		}

		return null;
	}//end firstReference()

	/**
	 * The property path a reference operand names.
	 *
	 * JSONLogic writes `{"var": "bedrag"}` and also accepts
	 * `{"var": ["bedrag", "default"]}`, where the path is the first element.
	 * Anything that is not a non-empty string names no property.
	 *
	 * @param mixed $reference The reference operand's argument.
	 *
	 * @return string|null The path, or null when the operand names no property.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function pathOf(mixed $reference): ?string {
		$path = $reference;
		if (is_array($path) === true && $path !== []) {
			$path = $path[array_key_first($path)];
		}

		if (is_string($path) === false || $path === '') {
			return null;
		}

		return $path;
	}//end pathOf()

	/**
	 * Read a dotted path out of the evaluation document.
	 *
	 * @param string $path The operand path.
	 * @param array<string, mixed> $document The evaluation document.
	 *
	 * @return mixed The value at the path, or null when the path is absent.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function read(string $path, array $document): mixed {
		$cursor = $document;
		foreach (explode('.', $path) as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return null;
			}

			$cursor = $cursor[$segment];
		}

		return $cursor;
	}//end read()

}//end class
