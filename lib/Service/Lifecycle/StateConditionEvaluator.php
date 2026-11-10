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
 * @spec openspec/specs/object-lifecycle/spec.md
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
 * @spec openspec/specs/object-lifecycle/spec.md
 *
 * @phpstan-type StateConditionFailure array{
 *   state: string,
 *   key: string,
 *   condition: mixed,
 *   message: string|null,
 *   document: array<string, mixed>
 * }
 */
class StateConditionEvaluator implements StateConditionEvaluatorInterface {

	/**
	 * The refusal code an `entry` condition produces.
	 */
	public const CODE_ENTRY = 'lifecycle-state-entry-refused';

	/**
	 * The refusal code an `exit` condition produces.
	 */
	public const CODE_EXIT = 'lifecycle-state-exit-refused';

	/**
	 * Names the clause that decided a refusal.
	 *
	 * @var ConditionTracer
	 */
	private readonly ConditionTracer $tracer;

	/**
	 * Constructor.
	 *
	 * The tracer only reads the dialect, so it is built over the same one.
	 *
	 * @param StateFieldRuleResolver $resolver Reads the annotation and builds the evaluation document.
	 * @param ConditionDialect       $dialect  Decides whether a condition holds, in either dialect.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly StateFieldRuleResolver $resolver,
		private readonly ConditionDialect $dialect,
	) {
		$this->tracer = new ConditionTracer(dialect: $dialect);
	}//end __construct()

	/**
	 * The refusal a move between two states earns, or null when it may proceed.
	 *
	 * Both halves are evaluated in the order they happen: the object leaves the
	 * old state before it enters the new one, so an `exit` refusal is reported
	 * ahead of an `entry` one. A move that stays in the same state is not a
	 * move and is evaluated as neither.
	 *
	 * @param StateSave     $save     The lifecycle annotation and the object as it would be saved.
	 * @param StateCrossing $crossing The state being left (null on a create) and the state being entered.
	 *
	 * @return array<string, mixed>|null The structured refusal, or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function refusal(StateSave $save, StateCrossing $crossing): ?array {
		if ($crossing->from === $crossing->to) {
			return null;
		}

		return ($this->check(save: $save, half: ['state' => $crossing->from, 'key' => 'exit'])
			?? $this->check(save: $save, half: ['state' => $crossing->to, 'key' => 'entry']));
	}//end refusal()

	/**
	 * Evaluate one half, and shape its refusal.
	 *
	 * @param StateSave                              $save The annotation and the object as it would be saved.
	 * @param array{state: string|null, key: string} $half The state carrying the condition, and `entry` or `exit`.
	 *
	 * @return array<string, mixed>|null The structured refusal, or null.
	 */
	private function check(StateSave $save, array $half): ?array {
		$failure = $this->declaredCondition(save: $save, half: $half);
		if ($failure === null) {
			return null;
		}

		$failure['document'] = $this->resolver->document(data: $save->newData, state: $failure['state']);
		if ($this->dialect->holds(node: $failure['condition'], document: $failure['document']) === true) {
			return null;
		}

		return $this->refusalFor(failure: $failure, annotation: $save->annotation);
	}//end check()

	/**
	 * The condition and message a half declares, or null when its state declares none.
	 *
	 * @param StateSave                              $save The annotation and the object as it would be saved.
	 * @param array{state: string|null, key: string} $half The state carrying the condition, and `entry` or `exit`.
	 *
	 * @return array{state: string, key: string, condition: mixed, message: string|null}|null The declaration.
	 */
	private function declaredCondition(StateSave $save, array $half): array|null {
		if ($half['state'] === null || $half['state'] === '') {
			return null;
		}

		$block = $this->resolver->blockFor(annotation: $save->annotation, state: $half['state']);
		if ($block === null) {
			return null;
		}

		[$condition, $message] = $this->declarationOf(block: $block, key: $half['key']);
		if ($condition === null) {
			return null;
		}

		return ['state' => $half['state'], 'key' => $half['key'], 'condition' => $condition, 'message' => $message];
	}//end declaredCondition()

	/**
	 * Shape the refusal of a condition that does not hold.
	 *
	 * @param array<string, mixed> $failure    The failed declaration and its document.
	 * @param array<string, mixed> $annotation The lifecycle annotation.
	 *
	 * @phpstan-param StateConditionFailure $failure
	 *
	 * @return array<string, mixed> The structured refusal.
	 */
	private function refusalFor(array $failure, array $annotation): array {
		$operand = $this->operandOf(failure: $failure);
		$refusal = [
			'code' => self::codeFor(key: $failure['key']),
			'field' => $this->resolver->fieldOf(annotation: $annotation),
			'state' => $failure['state'],
			'message' => ($failure['message'] ?? self::sentence(failure: $failure, operand: $operand)),
		];

		if ($operand !== null) {
			$refusal['clause'] = $operand;
		}

		return $refusal;
	}//end refusalFor()

	/**
	 * The refusal code of a half: {@see CODE_EXIT} for `exit`, {@see CODE_ENTRY} for `entry`.
	 *
	 * @param string $key Either `entry` or `exit`.
	 *
	 * @return string The code.
	 */
	private static function codeFor(string $key): string {
		if ($key === 'exit') {
			return self::CODE_EXIT;
		}

		return self::CODE_ENTRY;
	}//end codeFor()

	/**
	 * The property the clause that refused reads, or null when none is nameable.
	 *
	 * The tracer names the operand for a comparison and for the clauses of
	 * an `and`, but not for the single-argument `{"!!": {"var": "x"}}`
	 * idiom the lifecycle docs themselves use: its walk iterates a list of
	 * arguments, and that shape's argument is one object rather than a
	 * list, so the reference is never reached. Falling back to a local walk
	 * keeps the promise that a refusal names the clause that failed.
	 * Naming it here rather than widening ConditionTracer is deliberate:
	 * that file belongs to the rules engine and is owned by another lane.
	 *
	 * @param array<string, mixed> $failure The failed declaration and its document.
	 *
	 * @phpstan-param StateConditionFailure $failure
	 *
	 * @return string|null The reference path.
	 */
	private function operandOf(array $failure): ?string {
		$trace = $this->tracer->trace(
			condition: $failure['condition'],
			document: $failure['document'],
			verdict: RuleVocabulary::VERDICT_REFUSED,
			message: $failure['message']
		);

		return ($trace->getOperand() ?? $this->decidingReference(node: $failure['condition'], document: $failure['document']));
	}//end operandOf()

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

		$message = self::nonEmptyString(value: ($block[($key . 'Message')] ?? null));
		$nested  = ($declared['condition'] ?? ($declared['when'] ?? null));
		if (is_array($nested) === true && $nested !== []) {
			return [$nested, (self::nonEmptyString(value: ($declared['message'] ?? null)) ?? $message)];
		}

		return [$declared, $message];
	}//end declarationOf()

	/**
	 * A value when it is a non-empty string, else null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string.
	 */
	private static function nonEmptyString(mixed $value): ?string {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end nonEmptyString()

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
		if (self::isJunction(node: $node) === false) {
			return self::firstReferenceOf(node: $node);
		}

		$found = $this->failingClause(junction: $node, document: $document);
		if ($found === []) {
			return null;
		}

		return $this->decidingReference(node: $found[0], document: $document);
	}//end decidingReference()

	/**
	 * Whether a node is a single `and` or `or` over a list of clauses.
	 *
	 * @param mixed $node The condition node.
	 *
	 * @return bool True when it is.
	 */
	private static function isJunction(mixed $node): bool {
		return is_array($node) === true && count($node) === 1
			&& in_array(array_key_first($node), ['and', 'or'], true) === true
			&& is_array($node[array_key_first($node)]) === true;
	}//end isJunction()

	/**
	 * The clause of a junction to descend into: an `and`'s first clause that does not hold, an `or`'s first.
	 *
	 * @param array<string, array<mixed>> $junction The `and` or `or` node.
	 * @param array<string, mixed>        $document The evaluation document.
	 *
	 * @return array<int, mixed> The clause as a one-item list, or an empty list when there is none.
	 */
	private function failingClause(array $junction, array $document): array {
		$op = array_key_first($junction);
		foreach ($junction[$op] as $clause) {
			if ($op === 'or' || $this->dialect->holds(node: $clause, document: $document) === false) {
				return [$clause];
			}
		}

		return [];
	}//end failingClause()

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
			$found = self::referenceUnder(key: $key, value: $value);
			if ($found !== null) {
				return $found;
			}
		}

		return null;
	}//end firstReferenceOf()

	/**
	 * The reference one node entry declares, or the first one nested in it.
	 *
	 * A `var` or `prop` entry is a reference, never descended into, even when
	 * its path is unusable.
	 *
	 * @param int|string $key   The entry key.
	 * @param mixed      $value The entry value.
	 *
	 * @return string|null The reference path.
	 */
	private static function referenceUnder(int|string $key, mixed $value): ?string {
		if ($key !== 'var' && $key !== 'prop') {
			return self::firstReferenceOf(node: $value);
		}

		$path = $value;
		if (is_array($value) === true) {
			$path = ($value[0] ?? null);
		}

		return self::nonEmptyString(value: $path);
	}//end referenceUnder()

	/**
	 * The sentence a refusal carries when the author declared none.
	 *
	 * @param array{state: string, key: string} $failure The state carrying the condition, and `entry` or `exit`.
	 * @param string|null                       $operand The clause that decided, when one is nameable.
	 *
	 * @return string The refusal text.
	 */
	private static function sentence(array $failure, ?string $operand): string {
		$text = sprintf('The %s condition of state "%s" does not hold', $failure['key'], $failure['state']);
		if ($operand === null) {
			return $text . '.';
		}

		return sprintf('%s: "%s".', $text, $operand);
	}//end sentence()
}//end class
