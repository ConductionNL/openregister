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
 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/schema-property-scope/spec.md
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
 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/schema-property-scope/spec.md#requirement-a-reference-property-may-narrow-its-choices-with-a-query-over-the-record-req-fuc-003
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
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/schema-property-scope/spec.md#requirement-a-reference-property-may-narrow-its-choices-with-a-query-over-the-record-req-fuc-003
	 */
	public static function fromProperty(array $property, string $path = ''): ?self {
		$raw = ($property[self::ANNOTATION] ?? null);
		if ($raw === null) {
			return null;
		}

		if (is_array($raw) === false || $raw === []) {
			throw new ReferenceFilterException(
				sprintf('%s at \'%s\' must be a non-empty list of conditions.', self::ANNOTATION, $path),
				path: $path
			);
		}

		// A REFERENCE, OR NOTHING TO NARROW. A filter on a plain string is not
		// a narrower picker, it is a rule nothing reads, and the author who
		// wrote it believes their field is filtered.
		if (isset($property['$ref']) === false && ($property['type'] ?? '') !== 'array') {
			throw new ReferenceFilterException(
				sprintf(
					'%s at \'%s\' is on a property that references nothing. Put it on a `$ref` property.',
					self::ANNOTATION,
					$path
				),
				path: $path
			);
		}

		$conditions = [];
		foreach ($raw as $index => $condition) {
			$conditions[] = self::condition(condition: $condition, path: $path . '/' . (string)$index);
		}

		return new self(conditions: $conditions);
	}//end fromProperty()

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
			throw new ReferenceFilterException(
				sprintf('The condition at \'%s\' must be an object.', $path),
				path: $path
			);
		}

		$field = trim((string)($condition['field'] ?? ''));
		$from = trim((string)($condition['from'] ?? ''));
		$op = trim((string)($condition['op'] ?? 'eq'));

		if ($field === '' || $from === '') {
			throw new ReferenceFilterException(
				sprintf(
					'The condition at \'%s\' needs a \'field\' on the referenced schema and a \'from\' on this one.',
					$path
				),
				path: $path
			);
		}

		if (in_array($op, self::OPERATORS, true) === false) {
			throw new ReferenceFilterException(
				sprintf(
					'The condition at \'%s\' uses operator \'%s\'. It must be one of: %s.',
					$path,
					$op,
					implode(', ', self::OPERATORS)
				),
				path: $path
			);
		}

		return ['field' => $field, 'op' => $op, 'from' => $from];
	}//end condition()

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
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/schema-property-scope/spec.md#requirement-a-reference-property-may-narrow-its-choices-with-a-query-over-the-record-req-fuc-003
	 */
	public function assertOperandsExist(array $ownProperties, array $farProperties, string $path = ''): void {
		foreach ($this->conditions as $condition) {
			if (array_key_exists($condition['from'], $ownProperties) === false) {
				throw new ReferenceFilterException(
					sprintf(
						'The filter at \'%s\' reads \'%s\' off this record, and this schema does not declare it.',
						$path,
						$condition['from']
					),
					path: $path
				);
			}

			if ($farProperties !== [] && array_key_exists($condition['field'], $farProperties) === false) {
				throw new ReferenceFilterException(
					sprintf(
						'The filter at \'%s\' matches on \'%s\', and the referenced schema does not declare it.',
						$path,
						$condition['field']
					),
					path: $path
				);
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
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/schema-property-scope/spec.md#requirement-an-unresolved-filter-offers-nothing-and-names-what-it-needs-req-fuc-004
	 */
	public function resolve(array $record): array {
		$filter = [];
		$needs = [];

		foreach ($this->conditions as $condition) {
			$value = ($record[$condition['from']] ?? null);
			if ($value === null || $value === '' || $value === []) {
				$needs[] = $condition['from'];
				continue;
			}

			$filter[$condition['field']] = ($condition['op'] === 'eq'
				? $value
				: [$condition['op'] => $value]);
		}

		if ($needs !== []) {
			// The filter is dropped whole. Half a filter is a wider answer than
			// no filter at all was ever meant to be.
			return ['filter' => [], 'needs' => array_values(array_unique($needs))];
		}

		return ['filter' => $filter, 'needs' => []];
	}//end resolve()
}//end class
