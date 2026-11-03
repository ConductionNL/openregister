<?php

/**
 * The scheduled-notification filter grammar, enumerated once.
 *
 * Before this class existed the grammar was written down twice — as a switch in
 * ScheduledFilterEvaluator and as a validation list in
 * NotificationAnnotationValidator — and the two disagreed. The validator
 * accepted any array that lacked an `operator` key as a "scalar shortcut",
 * while the evaluator compared that array to a scalar field and matched
 * nothing. 24 filter entries across three apps were accepted at save time and
 * silently never fired.
 *
 * Everything that needs to know what the grammar admits reads it from here, so
 * "the validator accepts a shape the evaluator cannot execute" is a
 * contradiction rather than a bug waiting to recur.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

/**
 * Constants describing the filter grammar shared by the parser, the evaluator
 * and the validator.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class ScheduledFilterGrammar {

	/**
	 * Every operator a filter clause may name.
	 *
	 * @var array<int, string>
	 */
	public const OPERATORS = [
		'equals',
		'notEquals',
		'withinNext',
		'olderThan',
		'in',
		'notIn',
		'before',
		'after',
	];

	/**
	 * Operators whose operand is a list, supplied under `values`.
	 *
	 * @var array<int, string>
	 */
	public const MEMBERSHIP_OPERATORS = ['in', 'notIn'];

	/**
	 * Operators whose operand is an ISO-8601 duration measured from now.
	 *
	 * @var array<int, string>
	 */
	public const DURATION_OPERATORS = ['withinNext', 'olderThan'];

	/**
	 * Operators whose operand is a reference instant.
	 *
	 * @var array<int, string>
	 */
	public const INSTANT_OPERATORS = ['before', 'after'];

	/**
	 * Operators taking a single scalar operand under `value`.
	 *
	 * @var array<int, string>
	 */
	public const SCALAR_OPERATORS = ['equals', 'notEquals'];

	/**
	 * Reserved keys that introduce a nested clause list rather than a field.
	 *
	 * @var array<int, string>
	 */
	public const COMBINATORS = ['all', 'any'];

	/**
	 * How deeply combinators may nest before nesting is a validation error.
	 *
	 * Bounded so a hand-written annotation cannot recurse the parser into a
	 * stack overflow. No fleet filter nests at all; the value only has to be
	 * finite and stated.
	 *
	 * @var int
	 */
	public const MAX_DEPTH = 5;

	/**
	 * The literal accepted by the instant operators meaning "the scan's now".
	 *
	 * @var string
	 */
	public const INSTANT_NOW = 'now';

	/**
	 * Report whether a name is a known operator.
	 *
	 * @param string $operator Candidate operator name.
	 *
	 * @return bool True when the operator is part of the grammar.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public static function isOperator(string $operator): bool {
		return in_array($operator, self::OPERATORS, true);

	}//end isOperator()

	/**
	 * Report whether a key introduces a nested clause list.
	 *
	 * @param string $key Candidate combinator key.
	 *
	 * @return bool True when the key is `all` or `any`.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public static function isCombinator(string $key): bool {
		return in_array($key, self::COMBINATORS, true);

	}//end isCombinator()

	/**
	 * Name the operand key an operator expects.
	 *
	 * @param string $operator A known operator name.
	 *
	 * @return string Either `values` for membership operators, or `value`.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public static function operandKey(string $operator): string {
		if (in_array($operator, self::MEMBERSHIP_OPERATORS, true) === true) {
			return 'values';
		}

		return 'value';

	}//end operandKey()

}//end class
