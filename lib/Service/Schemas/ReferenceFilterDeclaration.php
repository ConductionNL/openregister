<?php

/**
 * A reference property that narrows its choices with a query over the record.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

/**
 * Reads and checks `x-openregister-reference-filter`.
 *
 * A `contactPerson` reference on a case may offer only the contacts of the
 * organisation already chosen on that case. The filter names a property of the
 * REFERENCED schema and an operand that is a property of the record being
 * edited, and the options read answers only the matching objects.
 *
 * 🔴 THE FAILURE THIS IS SHAPED AROUND IS "NO OPTIONS" TURNING INTO "EVERY
 * OPTION". When the operand has no value yet, because nobody has chosen the
 * organisation, the honest answer is no options and a sentence naming what is
 * needed. The tempting implementation drops an unresolved condition and runs
 * the query without it, which offers the whole contact list of every
 * organisation on the instance. That is a disclosure, it looks exactly like a
 * working picker, and REQ-FUC-004 exists because of it.
 * {@see self::resolve()} answers `needs` rather than a filter, and never both.
 *
 * WHAT THIS CLASS DOES NOT DO. It does not read objects and it does not refuse
 * writes. It is the declaration and its resolution, so the options read and the
 * save path can share one evaluator instead of writing the rule twice and
 * disagreeing about it, which is the shape `NoSecondPermissionEvaluatorTest`
 * exists to stop one layer up.
 *
 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md#requirement-a-reference-property-may-narrow-its-choices-with-a-query-over-the-record-req-fuc-003
 */
final class ReferenceFilterDeclaration {

	/**
	 * The annotation a reference property carries.
	 */
	public const ANNOTATION = 'x-openregister-reference-filter';

	/**
	 * The operators a condition may use.
	 *
	 * Deliberately small. Every one of these is a comparison the objects API
	 * already answers, so a filter cannot declare something the options read
	 * would have to emulate in PHP over an unbounded set.
	 *
	 * @var array<int, string>
	 */
	public const OPERATORS = ['eq', 'neq', 'in'];

	/**
	 * The refusal for a filter on a property that references nothing: annotation, path.
	 */
	private const MSG_NOT_A_REFERENCE = '%s at \'%s\' is on a property that references nothing. Put it on a `$ref` property.';

	/**
	 * The refusal for a condition missing an operand: path.
	 */
	private const MSG_NO_OPERANDS = 'The condition at \'%s\' needs a \'field\' on the referenced schema and a \'from\' on this one.';

	/**
	 * The refusal for an unknown operator: path, operator, the operators.
	 */
	private const MSG_BAD_OPERATOR = 'The condition at \'%s\' uses operator \'%s\'. It must be one of: %s.';

	/**
	 * The refusal for a `from` this schema does not declare: path, property.
	 */
	private const MSG_UNKNOWN_FROM = 'The filter at \'%s\' reads \'%s\' off this record, and this schema does not declare it.';

	/**
	 * The refusal for a `field` the referenced schema does not declare: path, property.
	 */
	private const MSG_UNKNOWN_FIELD = 'The filter at \'%s\' matches on \'%s\', and the referenced schema does not declare it.';

	/**
	 * Constructor.
	 *
	 * @param array<int, array{field: string, op: string, from: string}> $conditions The parsed conditions.
	 *
	 * @return void
	 */
	private function __construct(
		public readonly array $conditions,
	) {
	}//end __construct()

	/**
	 * The filter declared on a property, or null when it declares none.
	 *
	 * @param array<string,mixed> $property The schema property definition.
	 * @param string              $path     The property path, for the message.
	 *
	 * @return self|null The declaration, or null.
	 *
	 * @throws ReferenceFilterException When the annotation is present and unusable.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md#requirement-a-reference-property-may-narrow-its-choices-with-a-query-over-the-record-req-fuc-003
	 */
	public static function fromProperty(array $property, string $path = ''): ?self {
		if (($property[self::ANNOTATION] ?? null) === null) {
			return null;
		}

		$raw = $property[self::ANNOTATION];
		self::assertNarrowsAReference(raw: $raw, property: $property, path: $path);

		$conditions = [];
		foreach ($raw as $index => $condition) {
			$conditions[] = self::condition(condition: $condition, path: $path . '/' . (string)$index);
		}

		return new self(conditions: $conditions);
	}//end fromProperty()

	/**
	 * Refuse a filter that is no list of conditions, or sits on a property that references nothing.
	 *
	 * @param mixed                $raw      The declared filter.
	 * @param array<string, mixed> $property The schema property definition.
	 * @param string               $path     The property path, for the message.
	 *
	 * @return void
	 *
	 * @throws ReferenceFilterException When the filter cannot narrow anything.
	 */
	private static function assertNarrowsAReference(mixed $raw, array $property, string $path): void {
		if (is_array($raw) === false || $raw === []) {
			throw self::refusal(message: sprintf('%s at \'%s\' must be a non-empty list of conditions.', self::ANNOTATION, $path), path: $path);
		}

		// A REFERENCE, OR NOTHING TO NARROW. A filter on a plain string is not
		// a narrower picker, it is a rule nothing reads, and the author who
		// wrote it believes their field is filtered.
		if (isset($property['$ref']) === false && ($property['type'] ?? '') !== 'array') {
			throw self::refusal(message: sprintf(self::MSG_NOT_A_REFERENCE, self::ANNOTATION, $path), path: $path);
		}
	}//end assertNarrowsAReference()

	/**
	 * One condition, checked.
	 *
	 * @param mixed  $condition The raw condition.
	 * @param string $path      The condition's path, for the message.
	 *
	 * @return array{field: string, op: string, from: string} The condition.
	 *
	 * @throws ReferenceFilterException When it is unusable.
	 */
	private static function condition(mixed $condition, string $path): array {
		if (is_array($condition) === false) {
			throw self::refusal(message: sprintf('The condition at \'%s\' must be an object.', $path), path: $path);
		}

		$field = trim((string)($condition['field'] ?? ''));
		$from  = trim((string)($condition['from'] ?? ''));
		$op    = trim((string)($condition['op'] ?? 'eq'));

		if ($field === '' || $from === '') {
			throw self::refusal(message: sprintf(self::MSG_NO_OPERANDS, $path), path: $path);
		}

		if (in_array($op, self::OPERATORS, true) === false) {
			throw self::refusal(message: sprintf(self::MSG_BAD_OPERATOR, $path, $op, implode(', ', self::OPERATORS)), path: $path);
		}

		return ['field' => $field, 'op' => $op, 'from' => $from];
	}//end condition()

	/**
	 * A refusal about the filter at one path.
	 *
	 * @param string $message The sentence.
	 * @param string $path    The path it is about.
	 *
	 * @return ReferenceFilterException The refusal.
	 */
	private static function refusal(string $message, string $path): ReferenceFilterException {
		return new ReferenceFilterException($message, path: $path);
	}//end refusal()

	/**
	 * Refuse a filter naming a property neither schema declares.
	 *
	 * Called at schema save, where the author is present. The alternative is
	 * discovering it when a picker is empty and no message says which of the
	 * two schemas is missing the property.
	 *
	 * @param array<string, mixed> $ownProperties The properties of the schema holding the reference.
	 * @param array<string, mixed> $farProperties The properties of the referenced schema.
	 * @param string               $path          The property path, for the message.
	 *
	 * @return void
	 *
	 * @throws ReferenceFilterException When an operand is not declared anywhere.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md#requirement-a-reference-property-may-narrow-its-choices-with-a-query-over-the-record-req-fuc-003
	 */
	public function assertOperandsExist(array $ownProperties, array $farProperties, string $path = ''): void {
		foreach ($this->conditions as $condition) {
			if (array_key_exists($condition['from'], $ownProperties) === false) {
				throw self::refusal(message: sprintf(self::MSG_UNKNOWN_FROM, $path, $condition['from']), path: $path);
			}

			if ($farProperties !== [] && array_key_exists($condition['field'], $farProperties) === false) {
				throw self::refusal(message: sprintf(self::MSG_UNKNOWN_FIELD, $path, $condition['field']), path: $path);
			}
		}
	}//end assertOperandsExist()

	/**
	 * The filter for one record, or what it is still waiting for.
	 *
	 * 🔴 IT NEVER ANSWERS BOTH, AND NEVER A PARTIAL FILTER. An unresolved
	 * operand means no options, not "the conditions we could resolve". Dropping
	 * one condition and running the rest is how a picker meant to show the
	 * contacts of one organisation shows the contacts of all of them.
	 *
	 * @param array<string, mixed> $record The record being edited.
	 *
	 * @return array{filter: array<string, mixed>, needs: array<int, string>} The filter, or what it needs.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md#requirement-an-unresolved-filter-offers-nothing-and-names-what-it-needs-req-fuc-004
	 */
	public function resolve(array $record): array {
		$filter = [];
		$needs = [];

		foreach ($this->conditions as $condition) {
			$value = ($record[$condition['from']] ?? null);
			if (self::isUnresolved(value: $value) === true) {
				$needs[] = $condition['from'];
				continue;
			}

			$filter[$condition['field']] = self::clause(op: $condition['op'], value: $value);
		}

		return self::wholeOrNothing(filter: $filter, needs: $needs);
	}//end resolve()

	/**
	 * The resolved filter, or, when any operand is unresolved, no filter and what it needs.
	 *
	 * @param array<string, mixed> $filter The clauses of the resolved conditions.
	 * @param array<int, string>   $needs  The operands still unresolved.
	 *
	 * @return array{filter: array<string, mixed>, needs: array<int, string>} The answer.
	 */
	private static function wholeOrNothing(array $filter, array $needs): array {
		if ($needs !== []) {
			// The filter is dropped whole. Half a filter is a wider answer than
			// no filter at all was ever meant to be.
			return ['filter' => [], 'needs' => array_values(array_unique($needs))];
		}

		return ['filter' => $filter, 'needs' => []];
	}//end wholeOrNothing()

	/**
	 * The schema a reference property points at.
	 *
	 * Mirrors what a reference means everywhere else in this app: `$ref` or
	 * `schema` on the property, and for an array of references, the same two
	 * keys on its `items`.
	 *
	 * @param array<string, mixed> $config The reference property's declaration.
	 *
	 * @return string|null The target identifier, or null when the property names none.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md#requirement-a-reference-property-may-narrow-its-choices-with-a-query-over-the-record-req-fuc-003
	 */
	public static function targetSchemaOf(array $config): ?string {
		$direct = self::namedTarget(config: $config);
		if ($direct !== null) {
			return $direct;
		}

		// An array of references points at its item shape.
		$items = ($config['items'] ?? null);
		if (is_array($items) === true) {
			return self::targetSchemaOf(config: $items);
		}

		return null;
	}//end targetSchemaOf()

	/**
	 * The schema `$ref` or `schema` names on the property itself.
	 *
	 * @param array<string, mixed> $config The reference property's declaration.
	 *
	 * @return string|null The target identifier, or null when neither names one.
	 */
	private static function namedTarget(array $config): ?string {
		foreach (['$ref', 'schema'] as $key) {
			$value = ($config[$key] ?? null);
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return null;
	}//end namedTarget()

	/**
	 * The objects-API clause one condition becomes: the bare value for `eq`, else `{op: value}`.
	 *
	 * @param string $op    The operator.
	 * @param mixed  $value The resolved operand.
	 *
	 * @return mixed The clause.
	 */
	private static function clause(string $op, mixed $value): mixed {
		if ($op === 'eq') {
			return $value;
		}

		return [$op => $value];
	}//end clause()

	/**
	 * Whether a record leaves an operand unresolved: absent, null, an empty string or an empty list.
	 *
	 * @param mixed $value The record's value.
	 *
	 * @return bool True when the operand is unresolved.
	 */
	private static function isUnresolved(mixed $value): bool {
		return in_array($value, [null, '', []], true);
	}//end isUnresolved()
}//end class
