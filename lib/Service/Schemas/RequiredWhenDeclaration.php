<?php

/**
 * OpenRegister RequiredWhenDeclaration
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-required-when-enforced/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

/**
 * `x-openregister-required-when`: a property that becomes required when every
 * one of its conditions holds on the object.
 *
 * 🔑 THE GRAMMAR AND THE COMPARISON ARE THE FORM'S, NOT THIS LAYER'S. The
 * annotation is written once, read by nextcloud-vue's form (which shows and
 * checks it) and by this server (which refuses a save that breaks it). If the
 * two compared differently, a form could accept what the server refuses, or a
 * client skipping the form could pass what the form would stop. So the
 * operators and their semantics follow `compareVisibleWhen()` in
 * nextcloud-vue's `src/utils/visibleWhen.js`: `eq`/`neq` compare loosely
 * through the string form (a form field sends `"3"` for `3`), the ordering
 * operators compare as numbers, or as moments when both sides are dates, and
 * `empty`/`notEmpty` take no `value`.
 *
 * @spec openspec/changes/modelling-required-when-enforced/specs/object-lifecycle/spec.md
 */
final class RequiredWhenDeclaration {

	/**
	 * The annotation.
	 */
	public const ANNOTATION = 'x-openregister-required-when';

	/**
	 * The operators the form evaluates for a required-when condition.
	 *
	 * @var array<int, string>
	 */
	public const OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'empty', 'notEmpty'];

	/**
	 * The operators that compare against no value.
	 *
	 * @var array<int, string>
	 */
	private const VALUELESS = ['empty', 'notEmpty'];

	/**
	 * The refusal for a block that is neither a condition nor a list of them: annotation, path.
	 */
	private const MSG_NOT_A_CONDITION = '\'%s\' at \'%s\' must be a condition {field, op, value} or a non-empty list of them.';

	/**
	 * The refusal for a condition without a field: annotation, path.
	 */
	private const MSG_NO_FIELD = '\'%s\' at \'%s\' has a condition that names no field.';

	/**
	 * The refusal for an unknown operator: annotation, path, operator, the operators.
	 */
	private const MSG_BAD_OP = '\'%s\' at \'%s\' has the operator \'%s\'. It must be one of: %s.';

	/**
	 * The refusal for a comparison without a value: annotation, path, operator.
	 */
	private const MSG_NO_VALUE = '\'%s\' at \'%s\' compares with \'%s\' but gives no value to compare against.';

	/**
	 * The refusal for a condition on a field the schema does not declare: annotation, path, field.
	 */
	private const MSG_UNDECLARED = '\'%s\' at \'%s\' depends on \'%s\', which this schema does not declare.';

	/**
	 * The refusal for a condition on the property itself: annotation, path.
	 */
	private const MSG_SELF = '\'%s\' at \'%s\' depends on the property itself, which can never make it required.';

	/**
	 * Build a declaration.
	 *
	 * @param array<int, array{field: string, op: string, value: mixed}> $conditions The conditions, all of which must hold.
	 */
	private function __construct(
		public readonly array $conditions,
	) {
	}//end __construct()

	/**
	 * Read the declaration off a property, refusing anything malformed.
	 *
	 * A null annotation is no declaration, like a missing one: every other
	 * check in the property validator is guarded on a key being present AND
	 * non-null.
	 *
	 * @param array<string, mixed> $property The property.
	 * @param string               $path     Where the property sits, for the message.
	 *
	 * @return self|null The declaration, or null when the property carries none.
	 *
	 * @throws PropertyVocabularyException When a condition cannot be evaluated.
	 *
	 * @spec openspec/changes/modelling-required-when-enforced/specs/object-lifecycle/spec.md
	 */
	public static function fromProperty(array $property, string $path=''): ?self {
		$raw = ($property[self::ANNOTATION] ?? null);
		if ($raw === null) {
			return null;
		}

		$conditions = [];
		foreach (self::rawConditions(raw: $raw, path: $path) as $condition) {
			$conditions[] = self::validCondition(condition: $condition, path: $path);
		}

		return new self(conditions: $conditions);
	}//end fromProperty()

	/**
	 * Refuse a condition on a field the schema does not declare, or on the property itself.
	 *
	 * Checked where the sibling properties are in hand: a property alone
	 * cannot say which fields exist beside it.
	 *
	 * @param array<int, string> $declared The property names declared beside this one.
	 * @param string             $property This property's name.
	 * @param string             $path     Where the property sits, for the message.
	 *
	 * @return void
	 *
	 * @throws PropertyVocabularyException When a condition names an undeclared field or the property itself.
	 *
	 * @spec openspec/changes/modelling-required-when-enforced/specs/object-lifecycle/spec.md
	 */
	public function assertFieldsDeclared(array $declared, string $property, string $path): void {
		foreach ($this->conditions as $condition) {
			$root = explode('.', $condition['field'])[0];
			if ($root === $property) {
				throw self::refusal(format: self::MSG_SELF, values: [$path]);
			}

			if (in_array($root, $declared, true) === false) {
				throw self::refusal(format: self::MSG_UNDECLARED, values: [$path, $condition['field']]);
			}
		}
	}//end assertFieldsDeclared()

	/**
	 * Whether every condition holds on the object's values.
	 *
	 * @param array<string, mixed> $data The object's values as they will be saved.
	 *
	 * @return bool True when the property is required for this object.
	 *
	 * @spec openspec/changes/modelling-required-when-enforced/specs/object-lifecycle/spec.md
	 */
	public function holds(array $data): bool {
		foreach ($this->conditions as $condition) {
			$actual = self::read(data: $data, field: $condition['field']);
			if (self::compare(actual: $actual, operator: $condition['op'], expected: $condition['value']) === false) {
				return false;
			}
		}

		return true;
	}//end holds()

	/**
	 * The sentence a refusal shows: "Required when requestType eq Klacht".
	 *
	 * @return string The conditions, joined with "and".
	 *
	 * @spec openspec/changes/modelling-required-when-enforced/specs/object-lifecycle/spec.md
	 */
	public function describe(): string {
		$parts = [];
		foreach ($this->conditions as $condition) {
			$part = $condition['field'] . ' ' . $condition['op'];
			if (in_array($condition['op'], self::VALUELESS, true) === false) {
				$part .= ' ' . self::text(value: $condition['value']);
			}

			$parts[] = $part;
		}

		return 'Required when ' . implode(' and ', $parts);
	}//end describe()

	/**
	 * Whether a value counts as not given: null, an empty string or an empty list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True when the value is empty.
	 *
	 * @spec openspec/changes/modelling-required-when-enforced/specs/object-lifecycle/spec.md
	 */
	public static function isEmpty(mixed $value): bool {
		return $value === null || $value === '' || $value === [];
	}//end isEmpty()

	/**
	 * The conditions of a block: one condition, or a non-empty list of them.
	 *
	 * @param mixed  $raw  The annotation's value.
	 * @param string $path Where the property sits, for the message.
	 *
	 * @return array<int, mixed> The raw conditions.
	 *
	 * @throws PropertyVocabularyException When the block is neither.
	 */
	private static function rawConditions(mixed $raw, string $path): array {
		if (is_array($raw) === false || $raw === []) {
			throw self::refusal(format: self::MSG_NOT_A_CONDITION, values: [$path]);
		}

		if (array_is_list($raw) === true) {
			return $raw;
		}

		return [$raw];
	}//end rawConditions()

	/**
	 * One condition, checked: a field, a known operator and, unless the operator takes none, a value.
	 *
	 * @param mixed  $condition The raw condition.
	 * @param string $path      Where the property sits, for the message.
	 *
	 * @return array{field: string, op: string, value: mixed} The condition.
	 *
	 * @throws PropertyVocabularyException When the condition cannot be evaluated.
	 */
	private static function validCondition(mixed $condition, string $path): array {
		if (is_array($condition) === false || array_is_list($condition) === true) {
			throw self::refusal(format: self::MSG_NOT_A_CONDITION, values: [$path]);
		}

		$field = ($condition['field'] ?? null);
		if (is_string($field) === false || trim($field) === '') {
			throw self::refusal(format: self::MSG_NO_FIELD, values: [$path]);
		}

		$operator = ($condition['op'] ?? 'eq');
		if (is_string($operator) === false || in_array($operator, self::OPERATORS, true) === false) {
			throw self::refusal(format: self::MSG_BAD_OP, values: [$path, self::text(value: $operator), implode(', ', self::OPERATORS)]);
		}

		if (in_array($operator, self::VALUELESS, true) === false && array_key_exists('value', $condition) === false) {
			throw self::refusal(format: self::MSG_NO_VALUE, values: [$path, $operator]);
		}

		return ['field' => trim($field), 'op' => $operator, 'value' => ($condition['value'] ?? null)];
	}//end validCondition()

	/**
	 * Read a dotted field path off the object, the way the form does.
	 *
	 * @param array<string, mixed> $data  The object's values.
	 * @param string               $field The field, possibly dotted.
	 *
	 * @return mixed The value, or null when the path is not there.
	 */
	private static function read(array $data, string $field): mixed {
		$value = $data;
		foreach (explode('.', $field) as $key) {
			if (is_array($value) === false || array_key_exists($key, $value) === false) {
				return null;
			}

			$value = $value[$key];
		}

		return $value;
	}//end read()

	/**
	 * Compare a value with a condition's operand, as nextcloud-vue's `compareVisibleWhen()` does.
	 *
	 * @param mixed  $actual   The object's value.
	 * @param string $operator The operator.
	 * @param mixed  $expected The condition's value.
	 *
	 * @return bool Whether the condition holds.
	 */
	private static function compare(mixed $actual, string $operator, mixed $expected): bool {
		if (in_array($operator, self::VALUELESS, true) === true) {
			return self::isEmpty(value: $actual) === ($operator === 'empty');
		}

		if ($operator === 'eq' || $operator === 'neq') {
			$equal = ($actual === $expected || self::text(value: $actual) === self::text(value: $expected));
			return $equal === ($operator === 'eq');
		}

		$pair = self::orderable(actual: $actual, expected: $expected);
		if ($pair === null) {
			return false;
		}

		[$left, $right] = $pair;
		return match ($operator) {
			'gt' => $left > $right,
			'gte' => $left >= $right,
			'lt' => $left < $right,
			default => $left <= $right,
		};
	}//end compare()

	/**
	 * Both sides as numbers, or as moments when both are dates; null when they cannot be ordered.
	 *
	 * @param mixed $actual   The object's value.
	 * @param mixed $expected The condition's value.
	 *
	 * @return array{0: float, 1: float}|null The two sides, or null.
	 */
	private static function orderable(mixed $actual, mixed $expected): ?array {
		$left  = self::number(value: $actual);
		$right = self::number(value: $expected);
		if ($left !== null && $right !== null) {
			return [$left, $right];
		}

		$leftMoment  = self::moment(value: $actual);
		$rightMoment = self::moment(value: $expected);
		if ($leftMoment !== null && $rightMoment !== null) {
			return [$leftMoment, $rightMoment];
		}

		return null;
	}//end orderable()

	/**
	 * A value as a number the way JavaScript's `Number()` reads it, or null when it is none.
	 *
	 * @param mixed $value The value.
	 *
	 * @return float|null The number.
	 */
	private static function number(mixed $value): ?float {
		if ($value === null || $value === '' || $value === false) {
			return 0.0;
		}

		if ($value === true) {
			return 1.0;
		}

		if (is_int($value) === true || is_float($value) === true) {
			return (float)$value;
		}

		if (is_string($value) === true && is_numeric(trim($value)) === true) {
			return (float)trim($value);
		}

		return null;
	}//end number()

	/**
	 * A date-like string as a timestamp, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return float|null The moment.
	 */
	private static function moment(mixed $value): ?float {
		if (is_string($value) === false || trim($value) === '' || is_numeric(trim($value)) === true) {
			return null;
		}

		$time = strtotime($value);
		if ($time === false) {
			return null;
		}

		return (float)$time;
	}//end moment()

	/**
	 * A value in the string form JavaScript's `String()` gives it.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text.
	 */
	private static function text(mixed $value): string {
		if ($value === null) {
			return 'null';
		}

		if (is_bool($value) === true) {
			return $value === true ? 'true' : 'false';
		}

		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return (string)json_encode($value);
	}//end text()

	/**
	 * A refusal answered as a 422 naming the property.
	 *
	 * @param string            $format The message, with the annotation first.
	 * @param array<int, mixed> $values The rest of its values.
	 *
	 * @return PropertyVocabularyException The refusal.
	 */
	private static function refusal(string $format, array $values): PropertyVocabularyException {
		return new PropertyVocabularyException(sprintf($format, self::ANNOTATION, ...$values));
	}//end refusal()
}//end class
