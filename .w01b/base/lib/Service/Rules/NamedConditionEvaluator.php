<?php

/**
 * Evaluating a condition that references other conditions by name.
 *
 * 🔴 AN UNRESOLVABLE REFERENCE THROWS. It does not return false. A rules
 * engine that answers false for what it could not resolve fails OPEN one
 * negation later: `{"not": {"$condition": "spoedeisend"}}` with `spoedeisend`
 * missing would evaluate to TRUE and the rule would fire on everything. That is
 * the shape ADR-005 is written against — "a rule that cannot be evaluated is a
 * refusal, never a silent pass" — and a `bool` return has nowhere to put it, so
 * the refusal travels as {@see ConditionRefusedException} and the caller
 * records it in the run log.
 *
 * 🔑 THE REFERENCE IS RESOLVED, NOT SPLICED. The referenced expression is
 * evaluated in its own right, so a correction to the named condition reaches
 * every rule that names it without any rule being rewritten (D-1).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * Evaluates a condition, resolving `$condition` references as it goes.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */
class NamedConditionEvaluator {

	/**
	 * Constructor.
	 *
	 * @param ConditionDialect      $dialect The evaluator both dialects go through.
	 * @param NamedConditionLibrary $library The vocabulary and its walk.
	 */
	public function __construct(
		private readonly ConditionDialect $dialect,
		private readonly NamedConditionLibrary $library,
	) {
	}//end __construct()

	/**
	 * Whether a condition holds, resolving named references.
	 *
	 * @param mixed                $node     The condition node.
	 * @param array<string, mixed> $document The evaluation document.
	 * @param array<string, mixed> $library  The named conditions in scope.
	 * @param int                  $depth    The composition depth so far.
	 *
	 * @return bool True when it holds.
	 *
	 * @throws ConditionRefusedException When a reference cannot be resolved.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function holds(mixed $node, array $document, array $library, int $depth = 0): bool {
		if (is_array($node) === false || $node === []) {
			return $this->dialect->holds(node: $node, document: $document);
		}

		if (array_key_exists(NamedConditionLibrary::REF, $node) === true) {
			return $this->holdsReference(
				name: (string)$node[NamedConditionLibrary::REF],
				document: $document,
				library: $library,
				depth: $depth
			);
		}

		// A node with no reference anywhere inside it is handed to the dialect
		// whole, so composition costs nothing on the overwhelmingly common
		// case and the two evaluators cannot disagree about ordinary nodes.
		if ($this->library->referencesIn(node: $node) === []) {
			return $this->dialect->holds(node: $node, document: $document);
		}

		return $this->holdsBranch(node: $node, document: $document, library: $library, depth: $depth);
	}//end holds()

	/**
	 * Resolve one reference and evaluate what it names.
	 *
	 * @param string               $name     The referenced condition.
	 * @param array<string, mixed> $document The evaluation document.
	 * @param array<string, mixed> $library  The named conditions in scope.
	 * @param int                  $depth    The depth so far.
	 *
	 * @return bool True when it holds.
	 *
	 * @throws ConditionRefusedException When it cannot be resolved.
	 */
	private function holdsReference(string $name, array $document, array $library, int $depth): bool {
		if ($depth >= NamedConditionLibrary::MAX_DEPTH) {
			throw new ConditionRefusedException(
				conditionName: $name,
				why: sprintf('composition is deeper than the administered depth of %d', NamedConditionLibrary::MAX_DEPTH)
			);
		}

		if (array_key_exists($name, $library) === false) {
			throw new ConditionRefusedException(
				conditionName: $name,
				why: 'it is not declared in this schema\'s condition library'
			);
		}

		$declaration = $library[$name];
		if (is_array($declaration) === false || array_key_exists('expression', $declaration) === false) {
			throw new ConditionRefusedException(
				conditionName: $name,
				why: 'it is declared with no expression behind it'
			);
		}

		return $this->holds(
			node: $declaration['expression'],
			document: $document,
			library: $library,
			depth: ($depth + 1)
		);
	}//end holdsReference()

	/**
	 * Evaluate a branch node whose children may hold references.
	 *
	 * Only `and`, `or` and `not` are composed here. Every other node holding a
	 * reference is a shape this evaluator does not understand, and the honest
	 * answer to that is a refusal rather than a guess: a silently mis-evaluated
	 * `if` is a rule that fires on the wrong half of its own branch.
	 *
	 * @param array<string, mixed> $node     The node.
	 * @param array<string, mixed> $document The document.
	 * @param array<string, mixed> $library  The library.
	 * @param int                  $depth    The depth.
	 *
	 * @return bool True when it holds.
	 *
	 * @throws ConditionRefusedException When the shape is one this cannot compose.
	 */
	private function holdsBranch(array $node, array $document, array $library, int $depth): bool {
		$op = (string)array_key_first($node);
		$value = $node[$op];

		if ($op === 'not' || $op === '!') {
			return $this->holdsNegation(value: $value, document: $document, library: $library, depth: $depth);
		}

		if (($op === 'and' || $op === 'or') && is_array($value) === true) {
			return $this->holdsJunction(
				op: $op,
				value: $value,
				document: $document,
				library: $library,
				depth: $depth
			);
		}

		throw new ConditionRefusedException(
			conditionName: implode(', ', $this->library->referencesIn(node: $node)),
			why: sprintf('a named condition sits inside "%s", which this evaluator cannot compose', $op)
		);
	}//end holdsBranch()

	/**
	 * Whether a `not` branch holds.
	 *
	 * `{"not": {...}}` and `{"not": [{...}]}` both appear in the corpus, so a
	 * single-element list is read as the node it wraps.
	 *
	 * @param mixed                $value    What the branch carries.
	 * @param array<string, mixed> $document The document.
	 * @param array<string, mixed> $library  The library.
	 * @param int                  $depth    The depth.
	 *
	 * @return bool True when the negation holds.
	 *
	 * @throws ConditionRefusedException When the child shape is one this cannot compose.
	 */
	private function holdsNegation(mixed $value, array $document, array $library, int $depth): bool {
		$child = $value;
		if (is_array($value) === true && array_is_list($value) === true) {
			$child = ($value[0] ?? null);
		}

		return ($this->holds(node: $child, document: $document, library: $library, depth: $depth) === false);
	}//end holdsNegation()

	/**
	 * Whether an `and` or `or` branch holds.
	 *
	 * Short-circuits exactly as it did: `and` stops on the first child that
	 * does not hold, `or` on the first that does, and an empty list is true for
	 * `and` and false for `or`.
	 *
	 * @param string               $op       Either 'and' or 'or'.
	 * @param array<mixed>         $value    The child or children.
	 * @param array<string, mixed> $document The document.
	 * @param array<string, mixed> $library  The library.
	 * @param int                  $depth    The depth.
	 *
	 * @return bool True when the junction holds.
	 *
	 * @throws ConditionRefusedException When a child shape is one this cannot compose.
	 */
	private function holdsJunction(string $op, array $value, array $document, array $library, int $depth): bool {
		$children = [$value];
		if (array_is_list($value) === true) {
			$children = $value;
		}

		foreach ($children as $child) {
			$holds = $this->holds(node: $child, document: $document, library: $library, depth: $depth);

			if ($op === 'and' && $holds === false) {
				return false;
			}

			if ($op === 'or' && $holds === true) {
				return true;
			}
		}

		return ($op === 'and');
	}//end holdsJunction()
}//end class
