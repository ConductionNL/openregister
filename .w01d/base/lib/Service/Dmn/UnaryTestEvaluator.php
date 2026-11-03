<?php

/**
 * OpenRegister DMN Expression Evaluator
 *
 * A pure, closed, bounded evaluator for DMN-style "input entry" cell
 * expressions — comparisons, ranges, set membership, and bare-literal
 * equality. Every branch below is a fixed parse followed by a plain PHP
 * comparison; there is NO `eval()`, NO reflection, and NO dynamic code
 * execution of any kind, so rule authoring (which end users can do through
 * the settings UI) can never become a code-injection vector.
 *
 * Grammar (see openspec/changes/archive/2026-07-14-dmn-decision-tables/design.md Decision 2):
 *   ''  or  '-'          wildcard — always matches
 *   '"literal"'          explicit quoted literal (escapes wildcard collision)
 *   '< X' '<= X' '> X' '>= X' '= X' '!= X'   comparison, X coerced to type
 *   '[A..B]' '(A..B)' '[A..B)' '(A..B]'      inclusive/exclusive range
 *   'in (a,b,c)'         set membership (members may be quoted)
 *   'literal'            bare-literal equality
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dmn;

use DateTimeImmutable;
use Throwable;

/**
 * DMN unary tests: does one rule cell match one input value.
 *
 * MOVED, not rewritten. This is dossiq's ExpressionEvaluator, ported verbatim
 * apart from its namespace and name, because ADR-065 Decision 6 says decision
 * tables consolidate HERE and the fleet had already built the grammar twice —
 * openbuild on 2026-06-05 and dossiq on 2026-07-15, six weeks apart, neither
 * knowing the other existed. Retyping it would have been a third.
 *
 * dossiq's is the stronger of the two dialects: it carries typed coercion
 * (string / number / boolean / date), inclusive and exclusive ranges, set
 * membership and the quoted-literal escape that lets a rule match a literal
 * "-" rather than reading it as the wildcard. openbuild's contributes the
 * `priority` hit policy, which the table evaluator beside this class takes.
 *
 * Renamed from ExpressionEvaluator because it does NOT evaluate expressions in
 * the general sense: it decides whether a value satisfies one unary test, which
 * is a closed, bounded grammar with no code execution in it. The old name
 * invited someone to reach for it as a general evaluator.
 *
 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) — a closed grammar parser is
 * branchy by nature; every branch is a fixed, tested form. Carried over with the
 * class: adding a second docblock above the original detached this tag from the
 * class and the rule fired on a file nobody had changed a line of.
 */
class UnaryTestEvaluator {

	/**
	 * Declared input/output types this evaluator understands.
	 *
	 * @var string[]
	 */
	public const VALID_TYPES = ['string', 'number', 'boolean', 'date'];

	/**
	 * Check whether a rule cell expression matches an already-coerced value.
	 *
	 * @param string $expression The raw cell text (e.g. `'[0..25000]'`, `'-'`, `'in (a,b)'`).
	 * @param mixed $value The runtime value, already coerced via {@see coerce()} for `$type`.
	 * @param string $type One of {@see VALID_TYPES}.
	 *
	 * @return bool True when the expression matches the value.
	 *
	 * @throws DecisionEvaluationException `invalid_expression` on malformed grammar,
	 *                                     `type_mismatch` when a literal in the expression
	 *                                     cannot be coerced to `$type`.
	 *
	 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) — one dispatch per grammar form; splitting hides the grammar
	 * @SuppressWarnings(PHPMD.NPathComplexity)      — same: the branches are a flat form-dispatch, not nested logic
	 */
	public function matches(string $expression, mixed $value, string $type): bool {
		$trimmed = trim($expression);

		// Explicit quoted literal — bypasses the wildcard shortcut so a rule
		// author can match the literal string "-" by writing `"-"`.
		if (strlen($trimmed) >= 2 && $trimmed[0] === '"' && str_ends_with($trimmed, '"') === true) {
			$literal = $this->unquote(raw: $trimmed);
			return $this->equals(left: $value, right: $this->coerce(value: $literal, type: $type), type: $type);
		}

		if ($trimmed === '' || $trimmed === '-') {
			return true;
		}

		if (preg_match('/^in\s*\((.*)\)$/is', $trimmed, $setMatch) === 1) {
			$members = $this->parseSetMembers(inner: $setMatch[1]);
			foreach ($members as $member) {
				if ($this->equals(left: $value, right: $this->coerce(value: $member, type: $type), type: $type) === true) {
					return true;
				}
			}

			return false;
		}

		if (preg_match('/^([\[(])\s*(.*?)\s*\.\.\s*(.*?)\s*([\])])$/s', $trimmed, $rangeMatch) === 1) {
			return $this->matchesRange(match: $rangeMatch, value: $value, type: $type);
		}

		// Two-character operators BEFORE single-character ones (`<=` before `<`).
		foreach (['<=', '>=', '!='] as $operator) {
			if (str_starts_with($trimmed, $operator) === true) {
				return $this->matchesComparison(operator: $operator, remainder: substr($trimmed, 2), value: $value, type: $type);
			}
		}

		foreach (['<', '>', '='] as $operator) {
			if (str_starts_with($trimmed, $operator) === true) {
				return $this->matchesComparison(operator: $operator, remainder: substr($trimmed, 1), value: $value, type: $type);
			}
		}

		// Bare literal — plain equality.
		return $this->equals(left: $value, right: $this->coerce(value: $trimmed, type: $type), type: $type);
	}//end matches()

	/**
	 * Coerce a raw scalar (runtime input or rule-cell literal) to `$type`.
	 *
	 * @param mixed $value The raw value.
	 * @param string $type One of {@see VALID_TYPES}.
	 *
	 * @return string|float|bool|int The coerced value (int for `date`, a Unix timestamp).
	 *
	 * @throws DecisionEvaluationException `type_mismatch` when coercion fails.
	 *
	 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
	 */
	public function coerce(mixed $value, string $type): string|float|bool|int {
		return match ($type) {
			'string' => $this->coerceString(value: $value),
			'number' => $this->coerceNumber(value: $value),
			'boolean' => $this->coerceBoolean(value: $value),
			'date' => $this->coerceDate(value: $value),
			default => throw new DecisionEvaluationException(errorCode: 'type_mismatch', details: ['reason' => 'unsupported_type', 'type' => $type]),
		};
	}//end coerce()

	/**
	 * Coerce to string.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 *
	 * @throws DecisionEvaluationException `type_mismatch` for non-scalar input.
	 */
	private function coerceString(mixed $value): string {
		if (is_scalar($value) === false) {
			throw new DecisionEvaluationException(errorCode: 'type_mismatch', details: ['expected' => 'string']);
		}

		return (string)$value;
	}//end coerceString()

	/**
	 * Coerce to a float.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return float
	 *
	 * @throws DecisionEvaluationException `type_mismatch` for non-numeric input.
	 */
	private function coerceNumber(mixed $value): float {
		if (is_int($value) === true || is_float($value) === true) {
			return (float)$value;
		}

		if (is_string($value) === true && is_numeric(trim($value)) === true) {
			return (float)trim($value);
		}

		throw new DecisionEvaluationException(errorCode: 'type_mismatch', details: ['expected' => 'number', 'value' => $value]);
	}//end coerceNumber()

	/**
	 * Coerce to a bool.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return bool
	 *
	 * @throws DecisionEvaluationException `type_mismatch` for unrecognised input.
	 */
	private function coerceBoolean(mixed $value): bool {
		if (is_bool($value) === true) {
			return $value;
		}

		if (is_int($value) === true && ($value === 0 || $value === 1)) {
			return ($value === 1);
		}

		if (is_string($value) === true) {
			$lower = strtolower(trim($value));
			if ($lower === 'true') {
				return true;
			}

			if ($lower === 'false') {
				return false;
			}
		}

		throw new DecisionEvaluationException(errorCode: 'type_mismatch', details: ['expected' => 'boolean', 'value' => $value]);
	}//end coerceBoolean()

	/**
	 * Coerce to a Unix timestamp (int).
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return int
	 *
	 * @throws DecisionEvaluationException `type_mismatch` for unparsable input.
	 */
	private function coerceDate(mixed $value): int {
		if ($value instanceof \DateTimeInterface) {
			return $value->getTimestamp();
		}

		if (is_string($value) === true && trim($value) !== '') {
			try {
				return (new DateTimeImmutable(trim($value)))->getTimestamp();
			} catch (Throwable $e) {
				throw new DecisionEvaluationException(errorCode: 'type_mismatch', details: ['expected' => 'date', 'value' => $value]);
			}
		}

		throw new DecisionEvaluationException(errorCode: 'type_mismatch', details: ['expected' => 'date', 'value' => $value]);
	}//end coerceDate()

	/**
	 * Evaluate a parsed range match against a coerced value.
	 *
	 * @param array<int, string> $match Regex capture groups: [0]=full, [1]=open bracket, [2]=low, [3]=high, [4]=close bracket.
	 * @param mixed $value The already-coerced runtime value.
	 * @param string $type Declared type.
	 *
	 * @return bool
	 *
	 * @throws DecisionEvaluationException `invalid_expression` on a missing bound, `type_mismatch` on an unparsable bound.
	 */
	private function matchesRange(array $match, mixed $value, string $type): bool {
		[, $open, $lowRaw, $highRaw, $close] = $match;
		if ($lowRaw === '' || $highRaw === '') {
			throw new DecisionEvaluationException(errorCode: 'invalid_expression', details: ['reason' => 'missing_range_bound']);
		}

		$low = $this->coerce(value: $lowRaw, type: $type);
		$high = $this->coerce(value: $highRaw, type: $type);

		$lowOk = ($value > $low);
		if ($open === '[') {
			$lowOk = ($value >= $low);
		}

		$highOk = ($value < $high);
		if ($close === ']') {
			$highOk = ($value <= $high);
		}

		return ($lowOk === true && $highOk === true);
	}//end matchesRange()

	/**
	 * Evaluate a comparison operator against a coerced value.
	 *
	 * @param string $operator One of `< > <= >= = !=`.
	 * @param string $remainder The raw operand text (before the leading whitespace is trimmed).
	 * @param mixed $value The already-coerced runtime value.
	 * @param string $type Declared type.
	 *
	 * @return bool
	 *
	 * @throws DecisionEvaluationException `invalid_expression` when the operand is empty, `type_mismatch` when it cannot be coerced.
	 */
	private function matchesComparison(string $operator, string $remainder, mixed $value, string $type): bool {
		$operand = trim($remainder);
		if ($operand === '') {
			throw new DecisionEvaluationException(errorCode: 'invalid_expression', details: ['reason' => 'missing_operand', 'operator' => $operator]);
		}

		if (strlen($operand) >= 2 && $operand[0] === '"' && str_ends_with($operand, '"') === true) {
			$operand = $this->unquote(raw: $operand);
		}

		$coerced = $this->coerce(value: $operand, type: $type);

		return match ($operator) {
			'<' => ($value < $coerced),
			'<=' => ($value <= $coerced),
			'>' => ($value > $coerced),
			'>=' => ($value >= $coerced),
			'=' => $this->equals(left: $value, right: $coerced, type: $type),
			'!=' => ($this->equals(left: $value, right: $coerced, type: $type) === false),
			default => throw new DecisionEvaluationException(
				errorCode: 'invalid_expression',
				details: ['reason' => 'unknown_operator', 'operator' => $operator],
			),
		};
	}//end matchesComparison()

	/**
	 * Type-aware equality.
	 *
	 * @param mixed $left Left operand (already coerced).
	 * @param mixed $right Right operand (already coerced).
	 * @param string $type Declared type.
	 *
	 * @return bool
	 */
	private function equals(mixed $left, mixed $right, string $type): bool {
		if ($type === 'number' || $type === 'date') {
			return (abs(((float)$left) - ((float)$right)) < 1.0e-9);
		}

		return ($left === $right);
	}//end equals()

	/**
	 * Split the inner text of `in (...)` into raw member strings, respecting
	 * double-quoted members that may themselves contain commas.
	 *
	 * @param string $inner The text between the parentheses.
	 *
	 * @return array<int, string> Raw (still-quoted) member strings.
	 */
	private function parseSetMembers(string $inner): array {
		$members = [];
		$buffer = '';
		$inQuotes = false;
		$length = strlen($inner);

		for ($i = 0; $i < $length; $i++) {
			$char = $inner[$i];
			if ($char === '"') {
				$inQuotes = !$inQuotes;
				$buffer .= $char;
				continue;
			}

			if ($char === ',' && $inQuotes === false) {
				$members[] = trim($buffer);
				$buffer = '';
				continue;
			}

			$buffer .= $char;
		}

		if (trim($buffer) !== '') {
			$members[] = trim($buffer);
		}

		return array_map(
			function (string $member): string {
				if (strlen($member) >= 2 && $member[0] === '"' && str_ends_with($member, '"') === true) {
					return $this->unquote(raw: $member);
				}

				return $member;
			},
			$members,
		);
	}//end parseSetMembers()

	/**
	 * Strip one layer of surrounding double quotes and unescape `\"`.
	 *
	 * @param string $raw The quoted raw text, e.g. `'"a b"'`.
	 *
	 * @return string
	 */
	private function unquote(string $raw): string {
		$inner = substr($raw, 1, -1);
		return str_replace('\\"', '"', $inner);
	}//end unquote()
}//end class
