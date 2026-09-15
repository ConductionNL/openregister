<?php

/**
 * OpenRegister StateConditionEvaluator
 *
 * Evaluates a state's `entry` and `exit` conditions on every path in and out
 * of it, and names the clause that refused.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/field-rules-by-state/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\ConditionTracer;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;

/**
 * One rule per state, instead of the same rule on every edge.
 *
 * A transition `condition` guards one edge. A state with three ways in needs
 * the rule written three times, and the fourth transition somebody adds next
 * month is unguarded without anybody noticing. An `entry` condition is
 * evaluated on every path into the state and an `exit` condition on every path
 * out of it, whichever transition is used, so the rule outlives the edges.
 *
 * The clause that refused is named by {@see ConditionTracer}, the same walk the
 * transition conditions use, so a refusal reads the same wherever it came from.
 *
 * @spec openspec/changes/field-rules-by-state/specs/object-lifecycle/spec.md
 */
class StateConditionEvaluator {

	/**
	 * The refusal code an `entry` condition produces.
	 */
	public const CODE_ENTRY = 'lifecycle-state-entry-refused';

	/**
	 * The refusal code an `exit` condition produces.
	 */
	public const CODE_EXIT = 'lifecycle-state-exit-refused';

	/**
	 * Constructor.
	 *
	 * @param StateFieldRuleResolver $resolver Reads the annotation and builds the evaluation document.
	 * @param ConditionDialect $dialect Decides whether a condition holds, in either dialect.
	 * @param ConditionTracer $tracer Names the clause that decided a refusal.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly StateFieldRuleResolver $resolver,
		private readonly ConditionDialect $dialect,
		private readonly ConditionTracer $tracer,
	) {
	}//end __construct()

	/**
	 * The refusal a move between two states earns, or null when it may proceed.
	 *
	 * Both halves are evaluated in the order they happen: the object leaves the
	 * old state before it enters the new one, so an `exit` refusal is reported
	 * ahead of an `entry` one. A move that stays in the same state is not a
	 * move and is evaluated as neither.
	 *
	 * @param array<string, mixed> $annotation The lifecycle annotation.
	 * @param array<string, mixed> $newData The object as it would be saved.
	 * @param string|null $from The state being left, null on a create.
	 * @param string|null $to The state being entered.
	 *
	 * @return array<string, mixed>|null The structured refusal, or null.
	 *
	 * @spec openspec/changes/field-rules-by-state/specs/object-lifecycle/spec.md
	 */
	public function refusal(array $annotation, array $newData, ?string $from, ?string $to): ?array {
		if ($from === $to) {
			return null;
		}

		if ($from !== null && $from !== '') {
			$refusal = $this->check(
				annotation: $annotation,
				newData: $newData,
				state: $from,
				key: 'exit',
				code: self::CODE_EXIT
			);
			if ($refusal !== null) {
				return $refusal;
			}
		}

		if ($to !== null && $to !== '') {
			return $this->check(
				annotation: $annotation,
				newData: $newData,
				state: $to,
				key: 'entry',
				code: self::CODE_ENTRY
			);
		}

		return null;
	}//end refusal()

	/**
	 * Evaluate one half, and shape its refusal.
	 *
	 * @param array<string, mixed> $annotation The lifecycle annotation.
	 * @param array<string, mixed> $newData The object as it would be saved.
	 * @param string $state The state carrying the condition.
	 * @param string $key Either `entry` or `exit`.
	 * @param string $code The refusal code to report.
	 *
	 * @return array<string, mixed>|null The structured refusal, or null.
	 */
	private function check(array $annotation, array $newData, string $state, string $key, string $code): ?array {
		$block = $this->resolver->blockFor(annotation: $annotation, state: $state);
		if ($block === null) {
			return null;
		}

		[$condition, $message] = $this->declarationOf(block: $block, key: $key);
		if ($condition === null) {
			return null;
		}

		$document = $this->resolver->document(data: $newData, state: $state);
		if ($this->dialect->holds(node: $condition, document: $document) === true) {
			return null;
		}

		$trace = $this->tracer->trace(
			condition: $condition,
			document: $document,
			verdict: RuleVocabulary::VERDICT_REFUSED,
			message: $message
		);

		// The tracer names the operand for a comparison and for the clauses of
		// an `and`, but not for the single-argument `{"!!": {"var": "x"}}`
		// idiom the lifecycle docs themselves use: its walk iterates a list of
		// arguments, and that shape's argument is one object rather than a
		// list, so the reference is never reached. Falling back to a local walk
		// keeps the promise that a refusal names the clause that failed.
		// Naming it here rather than widening ConditionTracer is deliberate:
		// that file belongs to the rules engine and is owned by another lane.
		$operand = ($trace->getOperand() ?? $this->decidingReference(node: $condition, document: $document));
		$refusal = [
			'code' => $code,
			'field' => $this->resolver->fieldOf(annotation: $annotation),
			'state' => $state,
			'message' => ($message ?? $this->sentence(key: $key, state: $state, operand: $operand)),
		];

		if ($operand !== null) {
			$refusal['clause'] = $operand;
		}

		return $refusal;
	}//end check()

	/**
	 * The condition and its message, in either declared shape.
	 *
	 * A state may carry `entry` as a bare condition node with the text under
	 * `entryMessage`, or as `{condition, message}`. Both are accepted because
	 * the transitions already accept both spellings of the same pair, and an
	 * author who learnt one there should not be refused here.
	 *
	 * @param array<string, mixed> $block The state block.
	 * @param string $key Either `entry` or `exit`.
	 *
	 * @return array{0: mixed, 1: string|null} The condition and the declared message.
	 */
	private function declarationOf(array $block, string $key): array {
		$declared = ($block[$key] ?? null);
		if (is_array($declared) === false || $declared === []) {
			return [null, null];
		}

		$message = ($block[($key . 'Message')] ?? null);
		if (is_string($message) === false || $message === '') {
			$message = null;
		}

		$nested = ($declared['condition'] ?? ($declared['when'] ?? null));
		if (is_array($nested) === true && $nested !== []) {
			$own = ($declared['message'] ?? null);
			if (is_string($own) === true && $own !== '') {
				$message = $own;
			}

			return [$nested, $message];
		}

		return [$declared, $message];
	}//end declarationOf()

	/**
	 * The property the clause that failed reads, walked locally.
	 *
	 * Only consulted when {@see ConditionTracer} named nothing, which happens
	 * for the single-argument `{"!!": {"var": "x"}}` idiom the lifecycle docs
	 * themselves use: the tracer's walk iterates a LIST of arguments, and that
	 * shape's argument is one object rather than a list.
	 *
	 * An `and` is descended into the first clause that does not hold, because
	 * naming a clause that DID hold is worse than naming nothing: it sends the
	 * reader to a field that is already filled in. An `or` failed in all of its
	 * clauses, so the first one is as good an answer as any.
	 *
	 * @param mixed $node The condition node.
	 * @param array<string, mixed> $document The evaluation document.
	 *
	 * @return string|null The reference path, or null when the condition reads nothing.
	 */
	private function decidingReference(mixed $node, array $document): ?string {
		if (is_array($node) === false || count($node) !== 1) {
			return self::firstReferenceOf(node: $node);
		}

		$op = (string)array_key_first($node);
		$args = $node[$op];

		if (($op === 'and' || $op === 'or') && is_array($args) === true) {
			foreach ($args as $clause) {
				if ($op === 'and' && $this->dialect->holds(node: $clause, document: $document) === true) {
					continue;
				}

				return $this->decidingReference(node: $clause, document: $document);
			}

			return null;
		}

		return self::firstReferenceOf(node: $node);
	}//end decidingReference()

	/**
	 * The first property a condition reads, by its declared path.
	 *
	 * Both dialects are walked: JSONLogic spells a reference `var`, the JSON
	 * AST spells it `prop`.
	 *
	 * @param mixed $node The condition node.
	 *
	 * @return string|null The reference path, or null when the condition reads nothing.
	 */
	private static function firstReferenceOf(mixed $node): ?string {
		if (is_array($node) === false) {
			return null;
		}

		foreach ($node as $key => $value) {
			if ($key === 'var' || $key === 'prop') {
				if (is_string($value) === true && $value !== '') {
					return $value;
				}

				if (is_array($value) === true && is_string(($value[0] ?? null)) === true && $value[0] !== '') {
					return $value[0];
				}

				continue;
			}

			$nested = self::firstReferenceOf(node: $value);
			if ($nested !== null) {
				return $nested;
			}
		}

		return null;
	}//end firstReferenceOf()

	/**
	 * The sentence a refusal carries when the author declared none.
	 *
	 * @param string $key Either `entry` or `exit`.
	 * @param string $state The state carrying the condition.
	 * @param string|null $operand The clause that decided, when one is nameable.
	 *
	 * @return string The refusal text.
	 */
	private function sentence(string $key, string $state, ?string $operand): string {
		if ($key === 'exit') {
			if ($operand === null) {
				return sprintf('The exit condition of state "%s" does not hold.', $state);
			}

			return sprintf('The exit condition of state "%s" does not hold: "%s".', $state, $operand);
		}

		if ($operand === null) {
			return sprintf('The entry condition of state "%s" does not hold.', $state);
		}

		return sprintf('The entry condition of state "%s" does not hold: "%s".', $state, $operand);
	}//end sentence()
}//end class
