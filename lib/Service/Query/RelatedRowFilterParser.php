<?php

/**
 * Parses `_related[<schema>][<fk>]` out of a query.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Query
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Query;

use InvalidArgumentException;

/**
 * Reads the related-row blocks of a query and refuses the rest.
 *
 * A case's typed properties are rows of another schema, and until now they were
 * unfilterable from the case list: OpenRegister's query filters one schema at a
 * time. This is the wire format for asking about them, and this class is the
 * only thing that understands it. The query builders take the objects it
 * returns, so a builder never parses and a parser never builds SQL.
 *
 * 🔴 IT REFUSES RATHER THAN IGNORES, AND THAT IS THE ONLY SAFE DIRECTION FOR A
 * FILTER. A misspelt block that is quietly dropped answers the UNFILTERED set:
 * every case in the register, presented as the answer to a narrow question.
 * That is the failure this repository has already recorded twice, once as two
 * sibling endpoints spelling filters oppositely and once as a picker offering
 * every option when it could offer none. A refusal is a sentence somebody
 * fixes; a dropped filter is a list somebody believes.
 *
 * Wire format, and it nests because query strings do:
 *
 *     _related[caseProperty][case][propertyDefinition]=pd-7
 *     _related[caseProperty][case][value][gte]=100
 *
 * That is ONE block over the schema `caseProperty`, joined on its `case`
 * property, carrying two conditions: a row that is both. Repeat the block with
 * a numeric suffix to ask for two rows:
 *
 *     _related[caseProperty][case][0][propertyDefinition]=pd-7
 *     _related[caseProperty][case][1][propertyDefinition]=pd-9
 *
 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
 */
class RelatedRowFilterParser {

	/**
	 * The query key the blocks live under.
	 */
	public const KEY = '_related';

	/**
	 * The operators a row condition may use.
	 *
	 * The same six the object query already accepts, spelled the same way, read
	 * off `MariaDbSearchHandler::convertToSqlOperator()`. A filter over a
	 * related row is not a second query language, and a caller who learned `gte`
	 * on the object's own fields must not have to learn something else here.
	 *
	 * @var array<int, string>
	 */
	public const OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in'];

	/**
	 * Every related-row block in a query.
	 *
	 * @param array<string, mixed> $query The query parameters.
	 *
	 * @return array<int, RelatedRowFilter> The blocks, in the order written.
	 *
	 * @throws InvalidArgumentException When a block is unusable.
	 *
	 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function parse(array $query): array {
		$raw = ($query[self::KEY] ?? null);
		if ($raw === null) {
			return [];
		}

		if (is_array($raw) === false || $raw === []) {
			throw new InvalidArgumentException(
				sprintf('%s must be an object of schema blocks, and it is not.', self::KEY)
			);
		}

		$filters = [];
		foreach ($raw as $schema => $byForeignKey) {
			$schemaSlug = trim((string)$schema);
			if ($schemaSlug === '' || is_array($byForeignKey) === false || $byForeignKey === []) {
				throw new InvalidArgumentException(
					sprintf(
						'%s[%s] must name a foreign key and at least one condition.',
						self::KEY,
						(string)$schema
					)
				);
			}

			foreach ($byForeignKey as $foreignKey => $block) {
				$key = trim((string)$foreignKey);
				if ($key === '' || is_array($block) === false || $block === []) {
					throw new InvalidArgumentException(
						sprintf(
							'%s[%s][%s] must carry at least one condition.',
							self::KEY,
							$schemaSlug,
							(string)$foreignKey
						)
					);
				}

				foreach ($this->blocks(block: $block) as $conditions) {
					$filters[] = new RelatedRowFilter(
						schema: $schemaSlug,
						foreignKey: $key,
						conditions: $this->conditions(
							raw: $conditions,
							path: sprintf('%s[%s][%s]', self::KEY, $schemaSlug, $key)
						)
					);
				}
			}
		}

		return $filters;
	}//end parse()

	/**
	 * One block, or the several a numeric suffix asked for.
	 *
	 * 🔑 THE NUMERIC SUFFIX IS HOW A CALLER ASKS FOR TWO ROWS, and collapsing it
	 * into one block would answer a different question. `[0][x]=1 [1][x]=2` is
	 * "a row with x=1 AND a row with x=2"; merged, it becomes "one row with x=1
	 * and x=2", which no row can satisfy, so the caller gets an empty list and
	 * no explanation.
	 *
	 * @param array<string|int, mixed> $block The raw block.
	 *
	 * @return array<int, array<string, mixed>> One entry per row asked for.
	 */
	private function blocks(array $block): array {
		$numeric = array_filter(
			array_keys($block),
			static fn (string|int $key): bool => is_int($key) === true || ctype_digit((string)$key) === true
		);

		if ($numeric === []) {
			return [$block];
		}

		if (count($numeric) !== count(array_keys($block))) {
			throw new InvalidArgumentException(
				'A related block mixes numbered rows with bare conditions. Number all of them, or none.'
			);
		}

		$blocks = [];
		foreach ($numeric as $index) {
			$entry = $block[$index];
			if (is_array($entry) === false || $entry === []) {
				throw new InvalidArgumentException(
					sprintf('The related block at index %s carries no condition.', (string)$index)
				);
			}

			$blocks[] = $entry;
		}

		return $blocks;
	}//end blocks()

	/**
	 * The conditions of one block.
	 *
	 * @param array<string, mixed> $raw  The block's conditions.
	 * @param string               $path The block's path, for the message.
	 *
	 * @return array<int, array{field: string, operator: string, value: mixed}> The conditions.
	 *
	 * @throws InvalidArgumentException When a condition is unusable.
	 */
	private function conditions(array $raw, string $path): array {
		$conditions = [];

		foreach ($raw as $field => $value) {
			$name = trim((string)$field);
			if ($name === '') {
				throw new InvalidArgumentException(
					sprintf('A condition in %s names no field.', $path)
				);
			}

			// `field=value` is the `eq` shorthand, the same shorthand the
			// object's own filters use. `field[op]=value` names the operator.
			if (is_array($value) === false) {
				$conditions[] = ['field' => $name, 'operator' => 'eq', 'value' => $value];
				continue;
			}

			if ($value === []) {
				throw new InvalidArgumentException(
					sprintf('The condition %s[%s] carries no value.', $path, $name)
				);
			}

			// A bare list is the `in` shorthand: `field[]=a&field[]=b`.
			if (array_is_list($value) === true) {
				$conditions[] = ['field' => $name, 'operator' => 'in', 'value' => array_values($value)];
				continue;
			}

			foreach ($value as $operator => $operand) {
				$op = trim((string)$operator);
				if (in_array($op, self::OPERATORS, true) === false) {
					throw new InvalidArgumentException(
						sprintf(
							'The condition %s[%s] uses operator \'%s\'. It must be one of: %s.',
							$path,
							$name,
							$op,
							implode(', ', self::OPERATORS)
						)
					);
				}

				if ($op === 'in' && is_array($operand) === false) {
					$operand = array_map('trim', explode(',', (string)$operand));
				}

				$conditions[] = ['field' => $name, 'operator' => $op, 'value' => $operand];
			}
		}

		if ($conditions === []) {
			throw new InvalidArgumentException(sprintf('%s carries no usable condition.', $path));
		}

		return $conditions;
	}//end conditions()
}//end class
