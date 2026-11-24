<?php

/**
 * OpenRegister ReferenceFacetConfig
 *
 * Reads the `_ref` part of a facet configuration: facets over a field of the
 * object a reference property points to (query-filter-through-a-reference).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
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

namespace OCA\OpenRegister\Db\MagicMapper;

use InvalidArgumentException;
use OCA\OpenRegister\Exception\RelatedRowFilterNotSupportedException;
use OCA\OpenRegister\Service\Query\ReferenceFilterParser;

/**
 * The facets a `_facets[_ref]` block asks for, in the filter's own names.
 *
 *     _facets[_ref][aanvrager][woonplaats][type]=terms
 *     _facets[_ref][aanvrager][woonplaats]=terms
 *     _facets[_ref][aanvrager][]=woonplaats
 *
 * Only terms facets: a chip list of the referenced field's values. Like the
 * filter it refuses rather than drops: a malformed block or another facet type
 * throws, naming the part, because an omitted facet reads as "no values".
 *
 * @spec openspec/changes/query-filter-through-a-reference/specs/zoeken-filteren/spec.md
 */
final class ReferenceFacetConfig {

	/**
	 * The facet type a referenced field supports.
	 */
	private const TYPE_TERMS = 'terms';

	/**
	 * Every facet the block asks for.
	 *
	 * @param mixed $config The `_ref` part of the facet configuration.
	 *
	 * @return array<int, array{property: string, field: string, config: array<mixed>}> The facets, in the order written.
	 *
	 * @throws InvalidArgumentException When the block is malformed or asks for another type.
	 *
	 * @spec openspec/changes/query-filter-through-a-reference/specs/zoeken-filteren/spec.md
	 */
	public function entries(mixed $config): array {
		$key = '_facets[' . ReferenceFilterParser::KEY . ']';
		if (is_array($config) === false || $config === [] || array_is_list($config) === true) {
			throw new InvalidArgumentException($key . ' must be an object of reference-property blocks, and it is not.');
		}

		$entries = [];
		foreach ($config as $property => $fields) {
			$entries = array_merge($entries, $this->entriesFor(property: (string)$property, fields: $fields, path: sprintf('%s[%s]', $key, $property)));
		}

		return $entries;
	}//end entries()

	/**
	 * Refuse `_facets[_ref]` on a facet request across several schemas.
	 *
	 * A reference property belongs to one schema; across several there is no
	 * single referenced schema to count by.
	 *
	 * @param mixed $facetConfig The whole facet configuration of the request.
	 *
	 * @return void
	 *
	 * @throws RelatedRowFilterNotSupportedException When it carries a `_ref` block.
	 *
	 * @spec openspec/changes/query-filter-through-a-reference/specs/zoeken-filteren/spec.md
	 */
	public static function refuseAcrossSchemas(mixed $facetConfig): void {
		if (is_array($facetConfig) === false || array_key_exists(ReferenceFilterParser::KEY, $facetConfig) === false) {
			return;
		}

		throw new RelatedRowFilterNotSupportedException(
			message: '\'_facets[' . ReferenceFilterParser::KEY . ']\' is not supported on a facet request across several schemas; narrow it to one schema.'
		);
	}//end refuseAcrossSchemas()

	/**
	 * The facets one reference property's block asks for.
	 *
	 * @param string $property The reference property.
	 * @param mixed  $fields   The fields: a list of names, or names to their configuration.
	 * @param string $path     The block path, for the message.
	 *
	 * @return array<int, array{property: string, field: string, config: array<mixed>}> The facets.
	 *
	 * @throws InvalidArgumentException When the block is malformed or asks for another type.
	 */
	private function entriesFor(string $property, mixed $fields, string $path): array {
		if (is_array($fields) === false || $fields === []) {
			throw new InvalidArgumentException(sprintf('%s must name at least one field of the referenced schema.', $path));
		}

		$entries = [];
		foreach ($fields as $name => $config) {
			if (is_int($name) === true) {
				$name   = $config;
				$config = [];
			}

			$field = '';
			if (is_string($name) === true) {
				$field = trim($name);
			}

			if ($field === '') {
				throw new InvalidArgumentException(sprintf('%s names a field that is not a name.', $path));
			}

			$entries[] = ['property' => $property, 'field' => $field, 'config' => self::config(config: $config, path: sprintf('%s[%s]', $path, $field))];
		}

		return $entries;
	}//end entriesFor()

	/**
	 * One facet's configuration, refusing a type other than terms.
	 *
	 * @param mixed  $config The configuration: a type name, an object with `type`, or nothing.
	 * @param string $path   The facet path, for the message.
	 *
	 * @return array<mixed> The configuration, with its type.
	 *
	 * @throws InvalidArgumentException When it asks for another type or is malformed.
	 */
	private static function config(mixed $config, string $path): array {
		if ($config === null || $config === '') {
			$config = [];
		}

		if (is_string($config) === true) {
			$config = ['type' => $config];
		}

		if (is_array($config) === false) {
			throw new InvalidArgumentException(sprintf('%s must be a facet type or an object with one.', $path));
		}

		$type = ($config['type'] ?? self::TYPE_TERMS);
		if ($type !== self::TYPE_TERMS) {
			$named = get_debug_type($type);
			if (is_scalar($type) === true) {
				$named = (string)$type;
			}

			throw new InvalidArgumentException(
				sprintf('%s: a facet through a reference counts terms; \'%s\' is not supported.', $path, $named)
			);
		}

		$config['type'] = self::TYPE_TERMS;
		return $config;
	}//end config()
}//end class
