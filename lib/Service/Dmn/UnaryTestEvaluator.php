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
	 * Check whether a rule cell expression matches a value under test.
	 *
	 * @param string         $expression The raw cell text (e.g. `'[0..25000]'`, `'-'`, `'in (a,b)'`).
	 * @param UnaryTestValue $subject    The runtime value, already coerced via {@see coerce()}, and its type.
	 *
	 * @return bool True when the expression matches the value.
	 *
	 * @throws DecisionEvaluationException `invalid_expression` on malformed grammar,
	 *                                     `type_mismatch` when a literal in the expression
	 *                                     cannot be coerced to the subject's type.
	 *
	 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
	 */
	public function matchesValue(string $expression, UnaryTestValue $subject): bool {
		$trimmed = trim($expression);

		// A quoted literal bypasses the wildcard, so `"-"` matches the string "-".
		if ($this->isQuoted(raw: $trimmed) === true) {
			return $subject->equals(coerced: $this->coerce(value: $this->unquote(raw: $trimmed), type: $subject->type));
		}

		if ($trimmed === '' || $trimmed === '-') {
			return true;
		}

		return $this->matchesForm(trimmed: $trimmed, subject: $subject);
	}//end matchesValue()

	/**
	 * Match a non-wildcard, unquoted cell: a set, a range, a comparison or, failing those,
	 * a bare literal by plain equality.
	 *
	 * @param string         $trimmed The trimmed cell text.
	 * @param UnaryTestValue $subject The value under test.
	 *
	 * @return bool True when the cell matches the value.
	 *
	 * @throws DecisionEvaluationException `invalid_expression` or `type_mismatch`, as matches() documents.
	 */
	private function matchesForm(string $trimmed, UnaryTestValue $subject): bool {
		if (preg_match('/^in\s*\((.*)\)$/is', $trimmed, $setMatch) === 1) {
			return $this->matchesSet(inner: $setMatch[1], subject: $subject);
		}

		if (preg_match('/^([\[(])\s*(.*?)\s*\.\.\s*(.*?)\s*([\])])$/s', $trimmed, $rangeMatch) === 1) {
			return $this->matchesRange(match: $rangeMatch, subject: $subject);
		}

		if ($this->operatorOf(trimmed: $trimmed) !== null) {
			return $this->matchesComparison(trimmed: $trimmed, subject: $subject);
		}

		return $subject->equals(coerced: $this->coerce(value: $trimmed, type: $subject->type));
	}//end matchesForm()

	/**
	 * Whether any member of an `in (...)` set equals the value.
	 *
	 * Members are coerced in order; the first match answers, so a member after
	 * it is never coerced.
	 *
	 * @param string         $inner   The text between the parentheses.
	 * @param UnaryTestValue $subject The value under test.
	 *
	 * @return bool True when a member matches.
	 *
	 * @throws DecisionEvaluationException `type_mismatch` when a member before the match cannot be coerced.
	 */
	private function matchesSet(string $inner, UnaryTestValue $subject): bool {
		foreach ($this->parseSetMembers(inner: $inner) as $member) {
			if ($subject->equals(coerced: $this->coerce(value: $member, type: $subject->type)) === true) {
				return true;
			}
		}

		return false;
	}//end matchesSet()

	/**
	 * The comparison operator a cell starts with, two-character operators first.
	 *
	 * @param string $trimmed The trimmed cell text.
	 *
	 * @return string|null The operator, or null when the cell starts with none.
	 */
	private function operatorOf(string $trimmed): ?string {
		// Two-character operators BEFORE single-character ones (`<=` before `<`).
		foreach (['<=', '>=', '!=', '<', '>', '='] as $operator) {
			if (str_starts_with($trimmed, $operator) === true) {
				return $operator;
			}
		}

		return null;
	}//end operatorOf()

	/**
	 * Whether a text is wrapped in double quotes.
	 *
	 * @param string $raw The text.
	 *
	 * @return bool True for at least two characters that open and close with a quote.
	 */
	private function isQuoted(string $raw): bool {
		return (strlen($raw) >= 2 && $raw[0] === '"' && str_ends_with($raw, '"') === true);
	}//end isQuoted()

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
	 * @param array<int, string> $match   Regex capture groups: [0]=full, [1]=open bracket, [2]=low, [3]=high, [4]=close bracket.
	 * @param UnaryTestValue     $subject The value under test.
	 *
	 * @return bool
	 *
	 * @throws DecisionEvaluationException `invalid_expression` on a missing bound, `type_mismatch` on an unparsable bound.
	 */
	private function matchesRange(array $match, UnaryTestValue $subject): bool {
		[, $open, $lowRaw, $highRaw, $close] = $match;
		if ($lowRaw === '' || $highRaw === '') {
			throw new DecisionEvaluationException(errorCode: 'invalid_expression', details: ['reason' => 'missing_range_bound']);
		}

		$low = $this->coerce(value: $lowRaw, type: $subject->type);
		$high = $this->coerce(value: $highRaw, type: $subject->type);
		$lowOk = $subject->compare(operator: (['[' => '>='][$open] ?? '>'), coerced: $low);
		$highOk = $subject->compare(operator: ([']' => '<='][$close] ?? '<'), coerced: $high);

		return ($lowOk === true && $highOk === true);
	}//end matchesRange()

	/**
	 * Evaluate a cell that starts with a comparison operator.
	 *
	 * @param string         $trimmed The trimmed cell text, starting with an operator.
	 * @param UnaryTestValue $subject The value under test.
	 *
	 * @return bool
	 *
	 * @throws DecisionEvaluationException `invalid_expression` when the operand is empty, `type_mismatch` when it cannot be coerced.
	 */
	private function matchesComparison(string $trimmed, UnaryTestValue $subject): bool {
		$operator = (string)$this->operatorOf(trimmed: $trimmed);
		$operand = trim(substr($trimmed, strlen($operator)));
		if ($operand === '') {
			throw new DecisionEvaluationException(errorCode: 'invalid_expression', details: ['reason' => 'missing_operand', 'operator' => $operator]);
		}

		if ($this->isQuoted(raw: $operand) === true) {
			$operand = $this->unquote(raw: $operand);
		}

		return $subject->compare(operator: $operator, coerced: $this->coerce(value: $operand, type: $subject->type));
	}//end matchesComparison()

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
