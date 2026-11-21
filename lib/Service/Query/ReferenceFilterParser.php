<?php

/**
 * OpenRegister ReferenceFilterParser
 *
 * Parses `_ref[<property>][<field>]` out of a query: a filter on a field of the object a reference
 * property points to (query-filter-through-a-reference D-1, D-2, D-4).
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/query-filter-through-a-reference/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Query;

use Closure;
use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Schemas\ReferenceFilterDeclaration;

/**
 * Reads `_ref` blocks against the queried schema and refuses what it cannot honour.
 *
 *     _ref[aanvrager][woonplaats]=Zuiddrecht
 *     _ref[cursus][startdatum][gte]=2026-09-01
 *     _ref[aanvrager:2][woonplaats]=Oosterhout      (a second, independent referenced object)
 *
 * `_ref` names one of the queried schema's own reference properties; the
 * referenced schema is the one that property declares, so the caller never
 * names it. Each block becomes a forward {@see RelatedRowFilter} on that schema
 * joined on that property, the shape `_referenced` already produces, so the
 * applier, its access predicate and its refusals are shared rather than
 * written twice. The operators and the `eq` / `in` shorthands are
 * {@see RelatedRowFilterParser}'s, spelled the same way.
 *
 * 🔴 IT REFUSES RATHER THAN DROPS (D-2): an unknown property, a property that
 * is not a reference, a field the referenced schema lacks, an unknown operator
 * and a chain each throw, naming the part. A dropped filter answers the
 * unfiltered set to a narrow question.
 *
 * @spec openspec/changes/query-filter-through-a-reference/specs/zoeken-filteren/spec.md
 */
final class ReferenceFilterParser {

	/**
	 * The query key the blocks live under.
	 */
	public const KEY = '_ref';

	/**
	 * Separates a property from the suffix that asks for another referenced object.
	 */
	private const SUFFIX_SEPARATOR = ':';

	/**
	 * Constructor.
	 *
	 * @param Closure(string): ?Schema $resolveSchema Resolves a property's `$ref` (id, uuid, slug or path)
	 *                                                to a schema, or null.
	 */
	public function __construct(
		private readonly Closure $resolveSchema,
	) {
	}//end __construct()

	/**
	 * Every `_ref` block in a query, as forward related-row filters.
	 *
	 * @param array<string, mixed> $query  The query parameters.
	 * @param Schema               $schema The schema being queried.
	 *
	 * @return array<int, RelatedRowFilter> The filters, in the order written.
	 *
	 * @throws InvalidArgumentException When a block is unusable.
	 *
	 * @spec openspec/changes/query-filter-through-a-reference/specs/zoeken-filteren/spec.md
	 */
	public function parse(array $query, Schema $schema): array {
		$raw = ($query[self::KEY] ?? null);
		if ($raw === null) {
			return [];
		}

		if (is_array($raw) === false || $raw === []) {
			throw new InvalidArgumentException(self::KEY . ' must be an object of reference-property blocks, and it is not.');
		}

		$filters = [];
		foreach ($raw as $key => $block) {
			$filters = array_merge($filters, $this->filtersForBlock(key: (string)$key, block: $block, schema: $schema));
		}

		return $filters;
	}//end parse()

	/**
	 * The filter one `_ref[<property>]` block asks for.
	 *
	 * @param string $key    The block key: the property, optionally with a `:n` suffix.
	 * @param mixed  $block  The block's conditions.
	 * @param Schema $schema The schema being queried.
	 *
	 * @return array<int, RelatedRowFilter> The filter.
	 *
	 * @throws InvalidArgumentException When the block is unusable.
	 */
	private function filtersForBlock(string $key, mixed $block, Schema $schema): array {
		$property = self::propertyName(key: $key);
		$path     = sprintf('%s[%s]', self::KEY, $key);

		$referenced = $this->referencedSchema(property: $property, schema: $schema, path: $path);

		if (is_array($block) === false || $block === []) {
			throw new InvalidArgumentException(sprintf('%s must carry at least one condition.', $path));
		}

		$this->assertFields(block: $block, referenced: $referenced, path: $path);

		// The operator grammar is RelatedRowFilterParser's; reuse it on the
		// equivalent forward block so `_ref` and `_referenced` cannot drift apart.
		$schemaKey = (string)$referenced->getId();
		try {
			return (new RelatedRowFilterParser(key: RelatedRowFilterParser::FORWARD_KEY))->parse(
				[RelatedRowFilterParser::FORWARD_KEY => [$schemaKey => [$property => $block]]]
			);
		} catch (InvalidArgumentException $e) {
			// Name the block as the caller wrote it, not as it was translated.
			$internal = sprintf('%s[%s][%s]', RelatedRowFilterParser::FORWARD_KEY, $schemaKey, $property);
			throw new InvalidArgumentException(str_replace($internal, $path, $e->getMessage()), 0, $e);
		}
	}//end filtersForBlock()

	/**
	 * The property a block key names, without its suffix, refusing a blank name or a chain.
	 *
	 * @param string $key The block key.
	 *
	 * @return string The property.
	 *
	 * @throws InvalidArgumentException When it is blank or a chain.
	 */
	private static function propertyName(string $key): string {
		$property = trim($key);
		$cut      = strpos($property, self::SUFFIX_SEPARATOR);
		if ($cut !== false) {
			$suffix   = substr($property, ($cut + 1));
			$property = trim(substr($property, 0, $cut));
			if ($suffix === '' || ctype_digit($suffix) === false) {
				throw new InvalidArgumentException(
					sprintf('%s[%s]: the suffix after \':\' must be a number.', self::KEY, $key)
				);
			}
		}

		if ($property === '') {
			throw new InvalidArgumentException(sprintf('%s[%s] names no property.', self::KEY, $key));
		}

		self::assertNotAChain(name: $property, path: sprintf('%s[%s]', self::KEY, $key));

		return $property;
	}//end propertyName()

	/**
	 * The schema a reference property of the queried schema points to.
	 *
	 * @param string $property The property.
	 * @param Schema $schema   The schema being queried.
	 * @param string $path     The block path, for the message.
	 *
	 * @return Schema The referenced schema.
	 *
	 * @throws InvalidArgumentException When the property is unknown, not a reference, or its schema cannot be found.
	 */
	private function referencedSchema(string $property, Schema $schema, string $path): Schema {
		$properties = $schema->getProperties();
		$config     = ($properties[$property] ?? null);
		if (is_array($config) === false) {
			throw new InvalidArgumentException(
				sprintf('%s: the schema has no property \'%s\'.', $path, $property)
			);
		}

		$target = ReferenceFilterDeclaration::targetSchemaOf(config: $config);
		if ($target === null) {
			throw new InvalidArgumentException(
				sprintf('%s: the property \'%s\' is not a reference to another schema.', $path, $property)
			);
		}

		$referenced = ($this->resolveSchema)(self::schemaReference(target: $target));
		if ($referenced instanceof Schema === false) {
			throw new InvalidArgumentException(
				sprintf('%s: the schema \'%s\' that property \'%s\' references cannot be found.', $path, $target, $property)
			);
		}

		return $referenced;
	}//end referencedSchema()

	/**
	 * The identifier a `$ref` names: the last path segment, without a query string.
	 *
	 * @param string $target The `$ref` or `schema` value, e.g. `#/components/schemas/person`.
	 *
	 * @return string The identifier, e.g. `person`.
	 */
	private static function schemaReference(string $target): string {
		$clean = explode('?', $target, 2)[0];
		$slash = strrpos($clean, '/');
		if ($slash === false) {
			return $clean;
		}

		return substr($clean, ($slash + 1));
	}//end schemaReference()

	/**
	 * Refuse a field the referenced schema lacks, or one that reaches through a second reference.
	 *
	 * @param array<string|int, mixed> $block      The block's conditions, keyed by field.
	 * @param Schema                   $referenced The referenced schema.
	 * @param string                   $path       The block path, for the message.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a field is unknown or a chain.
	 */
	private function assertFields(array $block, Schema $referenced, string $path): void {
		$properties = $referenced->getProperties();
		foreach ($block as $field => $value) {
			$name = trim((string)$field);
			self::assertNotAChain(name: $name, path: $path);

			if (array_key_exists($name, $properties) === false) {
				throw new InvalidArgumentException(
					sprintf('%s: the referenced schema has no field \'%s\'.', $path, $name)
				);
			}

			$this->assertNotNested(config: $properties[$name], value: $value, path: sprintf('%s[%s]', $path, $name));
		}
	}//end assertFields()

	/**
	 * Refuse `_ref[a][b][c]` where `b` is itself a reference and `c` is not an operator: a second hop.
	 *
	 * @param mixed  $config The field's declaration on the referenced schema.
	 * @param mixed  $value  The condition as written.
	 * @param string $path   The condition path, for the message.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When it is a chain.
	 */
	private function assertNotNested(mixed $config, mixed $value, string $path): void {
		if (is_array($config) === false || is_array($value) === false || array_is_list($value) === true) {
			return;
		}

		if (ReferenceFilterDeclaration::targetSchemaOf(config: $config) === null) {
			return;
		}

		$operators = array_map(static fn (string|int $key): string => trim((string)$key), array_keys($value));
		if (array_diff($operators, RelatedRowFilterParser::OPERATORS) !== []) {
			throw self::chain(path: $path);
		}
	}//end assertNotNested()

	/**
	 * Refuse a dotted name: `applicant.address.city` is a chain, and one level is supported.
	 *
	 * @param string $name The property or field.
	 * @param string $path The path, for the message.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When it is a chain.
	 */
	private static function assertNotAChain(string $name, string $path): void {
		if (str_contains($name, '.') === true) {
			throw self::chain(path: $path . ' (' . $name . ')');
		}
	}//end assertNotAChain()

	/**
	 * The refusal of a filter through more than one reference.
	 *
	 * @param string $path What was written.
	 *
	 * @return InvalidArgumentException The refusal.
	 */
	private static function chain(string $path): InvalidArgumentException {
		return new InvalidArgumentException(
			sprintf('%s reaches through more than one reference; %s filters one level deep.', $path, self::KEY)
		);
	}//end chain()
}//end class
