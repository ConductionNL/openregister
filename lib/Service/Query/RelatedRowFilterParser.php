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
	 * The query key of the forward blocks: the object's own property references
	 * a row of the named schema.
	 *
	 *     _referenced[subsidieAanvraag][subsidieaanvraag][case]=<case uuid>
	 *
	 * One block over the schema `subsidieAanvraag`, joined on the object's
	 * `subsidieaanvraag` property, with one condition on the referenced row.
	 * Same grammar, operators and numbered blocks as `_related`.
	 */
	public const FORWARD_KEY = '_referenced';

	/**
	 * Constructor.
	 *
	 * @param string $key KEY for inverse blocks, FORWARD_KEY for forward ones.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $key = self::KEY,
	) {
	}//end __construct()

	/**
	 * The operators a row condition may use.
	 *
	 * The same ones the object query already accepts, spelled the same way, as
	 * listed in `MagicSearchHandler::COMPARISON_OPERATORS`. A filter over a
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
	 * @spec openspec/changes/query-referenced-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function parse(array $query): array {
		$raw = ($query[$this->key] ?? null);
		if ($raw === null) {
			return [];
		}

		$this->assertSchemaBlocks(raw: $raw);

		$filters = [];
		foreach ($raw as $schema => $byForeignKey) {
			$filters = array_merge(
				$filters,
				$this->filtersForSchema(
					schemaSlug: $this->schemaSlug(schema: $schema, byForeignKey: $byForeignKey),
					byForeignKey: $byForeignKey
				)
			);
		}

		return $filters;
	}//end parse()

	/**
	 * Refuse a `_related` value that is not a non-empty object of schema blocks.
	 *
	 * @param mixed $raw The value under this parser's key.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When it is not.
	 *
	 * @psalm-assert non-empty-array $raw
	 */
	private function assertSchemaBlocks(mixed $raw): void {
		if (is_array($raw) === false || $raw === []) {
			throw new InvalidArgumentException(
				sprintf('%s must be an object of schema blocks, and it is not.', $this->key)
			);
		}
	}//end assertSchemaBlocks()

	/**
	 * The trimmed schema slug of one block, refusing a block that names nothing.
	 *
	 * @param string|int $schema       The block's key.
	 * @param mixed      $byForeignKey The block.
	 *
	 * @return string The slug.
	 *
	 * @throws InvalidArgumentException When the slug is blank or the block carries no foreign key.
	 */
	private function schemaSlug(string|int $schema, mixed $byForeignKey): string {
		$schemaSlug = trim((string)$schema);
		if ($schemaSlug === '' || is_array($byForeignKey) === false || $byForeignKey === []) {
			throw new InvalidArgumentException(
				sprintf(
					'%s[%s] must name a foreign key and at least one condition.',
					$this->key,
					(string)$schema
				)
			);
		}

		return $schemaSlug;
	}//end schemaSlug()

	/**
	 * The direction the blocks under this parser's key run.
	 *
	 * @return string RelatedRowFilter::DIRECTION_FORWARD for `_referenced`, else inverse.
	 *
	 * @spec openspec/changes/query-referenced-schema-rows/specs/zoeken-filteren/spec.md
	 */
	private function direction(): string {
		if ($this->key === self::FORWARD_KEY) {
			return RelatedRowFilter::DIRECTION_FORWARD;
		}

		return RelatedRowFilter::DIRECTION_INVERSE;
	}//end direction()

	/**
	 * The filters one schema block declares, in the order written.
	 *
	 * @param string                   $schemaSlug   The schema the block names.
	 * @param array<string|int, mixed> $byForeignKey The block, keyed by foreign key.
	 *
	 * @return array<int, RelatedRowFilter> The filters.
	 *
	 * @throws InvalidArgumentException When a foreign key block is unusable.
	 *
	 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
	 */
	private function filtersForSchema(string $schemaSlug, array $byForeignKey): array {
		$filters = [];

		foreach ($byForeignKey as $foreignKey => $block) {
			$at  = sprintf('%s[%s][%s]', $this->key, $schemaSlug, (string)$foreignKey);
			$key = self::foreignKeyName(at: $at, foreignKey: $foreignKey);

			foreach ($this->blocks(block: self::conditionBlock(at: $at, block: $block)) as $conditions) {
				$filters[] = new RelatedRowFilter(
					schema: $schemaSlug,
					foreignKey: $key,
					conditions: $this->conditions(
						raw: $conditions,
						path: sprintf('%s[%s][%s]', $this->key, $schemaSlug, $key)
					),
					direction: $this->direction()
				);
			}
		}

		return $filters;
	}//end filtersForSchema()

	/**
	 * The trimmed foreign key of one block, refusing a blank one.
	 *
	 * @param string     $at         The block's path as written, for the message.
	 * @param string|int $foreignKey The foreign key as written.
	 *
	 * @return string The foreign key.
	 *
	 * @throws InvalidArgumentException When the key is blank.
	 */
	private static function foreignKeyName(string $at, string|int $foreignKey): string {
		$key = trim((string)$foreignKey);
		if ($key === '') {
			throw self::noCondition(at: $at);
		}

		return $key;
	}//end foreignKeyName()

	/**
	 * The block under one foreign key, refusing a block that carries no condition.
	 *
	 * @param string $at    The block's path as written, for the message.
	 * @param mixed  $block The block.
	 *
	 * @return array<string|int, mixed> The block.
	 *
	 * @throws InvalidArgumentException When the block is empty.
	 */
	private static function conditionBlock(string $at, mixed $block): array {
		if (is_array($block) === false || $block === []) {
			throw self::noCondition(at: $at);
		}

		return $block;
	}//end conditionBlock()

	/**
	 * The refusal of a foreign key block that carries no condition.
	 *
	 * @param string $at The block's path as written.
	 *
	 * @return InvalidArgumentException
	 */
	private static function noCondition(string $at): InvalidArgumentException {
		return new InvalidArgumentException(sprintf('%s must carry at least one condition.', $at));
	}//end noCondition()

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

		return $this->numberedBlocks(block: $block, numeric: $numeric);
	}//end blocks()

	/**
	 * The rows of a numbered block, refusing a number that carries no condition.
	 *
	 * @param array<string|int, mixed> $block   The raw block.
	 * @param array<int, string|int>   $numeric Its keys, all numeric.
	 *
	 * @return array<int, array<string, mixed>> One entry per row asked for.
	 *
	 * @throws InvalidArgumentException When a numbered entry is empty.
	 */
	private function numberedBlocks(array $block, array $numeric): array {
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
	}//end numberedBlocks()

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
			$conditions = array_merge(
				$conditions,
				$this->conditionsFor(field: new RelatedRowBlockField(name: self::fieldName(field: $field, path: $path), path: $path), value: $value)
			);
		}

		if ($conditions === []) {
			throw new InvalidArgumentException(sprintf('%s carries no usable condition.', $path));
		}

		return $conditions;
	}//end conditions()

	/**
	 * The trimmed name of one condition's field, refusing a blank one.
	 *
	 * @param int|string $field The field as written.
	 * @param string     $path  The block's path, for the message.
	 *
	 * @return string The name.
	 *
	 * @throws InvalidArgumentException When the field is blank.
	 */
	private static function fieldName(int|string $field, string $path): string {
		$name = trim((string)$field);
		if ($name === '') {
			throw new InvalidArgumentException(
				sprintf('A condition in %s names no field.', $path)
			);
		}

		return $name;
	}//end fieldName()

	/**
	 * The conditions ONE field declares.
	 *
	 * Three spellings, all of them in use: `field=value` is the `eq` shorthand
	 * the object's own filters use, a bare list `field[]=a&field[]=b` is the
	 * `in` shorthand, and `field[op]=value` names the operator outright.
	 *
	 * @param RelatedRowBlockField $field The field name, already trimmed and non-empty, and the block's path.
	 * @param mixed                $value The declared value in any of the three spellings.
	 *
	 * @return array<int, array{field: string, operator: string, value: mixed}> The conditions.
	 *
	 * @throws InvalidArgumentException When the condition is unusable.
	 *
	 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
	 */
	private function conditionsFor(RelatedRowBlockField $field, mixed $value): array {
		if (is_array($value) === false) {
			return [['field' => $field->name, 'operator' => 'eq', 'value' => $value]];
		}

		if ($value === []) {
			throw new InvalidArgumentException(
				sprintf('The condition %s carries no value.', $field->label())
			);
		}

		if (array_is_list($value) === true) {
			return [['field' => $field->name, 'operator' => 'in', 'value' => $value]];
		}

		return $this->operatorConditions(field: $field, byOperator: $value);
	}//end conditionsFor()

	/**
	 * The `field[op]=value` conditions of one field.
	 *
	 * @param RelatedRowBlockField     $field      The field and the block's path.
	 * @param array<string|int, mixed> $byOperator The operands, keyed by operator.
	 *
	 * @return array<int, array{field: string, operator: string, value: mixed}> The conditions.
	 *
	 * @throws InvalidArgumentException When an operator is not one of OPERATORS.
	 */
	private function operatorConditions(RelatedRowBlockField $field, array $byOperator): array {
		$conditions = [];
		foreach ($byOperator as $operator => $operand) {
			$operator = self::knownOperator(field: $field, operator: trim((string)$operator));

			$conditions[] = ['field' => $field->name, 'operator' => $operator, 'value' => self::operand(operator: $operator, operand: $operand)];
		}

		return $conditions;
	}//end operatorConditions()

	/**
	 * The operator, refusing one that is not one of OPERATORS.
	 *
	 * @param RelatedRowBlockField $field    The field, for the message.
	 * @param string               $operator The operator, already trimmed.
	 *
	 * @return string The operator.
	 *
	 * @throws InvalidArgumentException When the operator is not one of OPERATORS.
	 */
	private static function knownOperator(RelatedRowBlockField $field, string $operator): string {
		if (in_array($operator, self::OPERATORS, true) === true) {
			return $operator;
		}

		throw new InvalidArgumentException(
			sprintf(
				'The condition %s uses operator \'%s\'. It must be one of: %s.',
				$field->label(),
				$operator,
				implode(', ', self::OPERATORS)
			)
		);
	}//end knownOperator()

	/**
	 * The operand of one condition: a scalar `in` is split on commas.
	 *
	 * @param string $operator The operator.
	 * @param mixed  $operand  The value as written.
	 *
	 * @return mixed
	 */
	private static function operand(string $operator, mixed $operand): mixed {
		if ($operator === 'in' && is_array($operand) === false) {
			return array_map('trim', explode(',', (string)$operand));
		}

		return $operand;
	}//end operand()
}//end class
