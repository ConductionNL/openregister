<?php

/**
 * MagicMetadataColumn: which underscore-prefixed magic-table columns are
 * object metadata and which are schema properties.
 *
 * Metadata columns carry a `_` prefix (`_uuid`, `_schema_version`). A schema
 * may also declare a property whose name starts with an underscore (`_meta`),
 * and that property's column carries the same prefix. A reader that took every
 * `_` column for metadata either dropped the property or, calling the setter
 * of an attribute ObjectEntity does not have, failed the whole row.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/underscore-properties-and-config-paths/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Classify a magic-table column as metadata or as a schema property.
 *
 * @spec openspec/changes/underscore-properties-and-config-paths/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
 */
final class MagicMetadataColumn {

	private const PREFIX = '_';

	/**
	 * The metadata field a column carries, or null when it carries a property.
	 *
	 * A column without the prefix is a property. A prefixed column the schema
	 * maps to one of its properties is that property, unless ObjectEntity has
	 * an attribute of the same name: the metadata column then wins, because
	 * the table holds one column of that name and the metadata is what it was
	 * made for.
	 *
	 * @param string                $column              The column name.
	 * @param array<string, string> $columnToPropertyMap The schema's column-to-property map.
	 *
	 * @return string|null The metadata field without its prefix (`schema_version`), or null.
	 *
	 * @spec openspec/changes/underscore-properties-and-config-paths/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
	 */
	public static function field(string $column, array $columnToPropertyMap): ?string {
		if (str_starts_with($column, self::PREFIX) === false) {
			return null;
		}

		$field = substr($column, strlen(self::PREFIX));
		if (isset($columnToPropertyMap[$column]) === true && self::attribute(field: $field) === null) {
			return null;
		}

		return $field;
	}//end field()

	/**
	 * The ObjectEntity attribute a metadata field sets, or null when it has none.
	 *
	 * @param string $field The metadata field (`uuid`, `schema_version`).
	 *
	 * @return string|null The attribute name (`uuid`, `schemaVersion`), or null.
	 *
	 * @spec openspec/changes/underscore-properties-and-config-paths/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
	 */
	public static function attribute(string $field): ?string {
		$attribute = lcfirst(str_replace('_', '', ucwords($field, '_')));
		if ($attribute === '' || property_exists(ObjectEntity::class, $attribute) === false) {
			return null;
		}

		return $attribute;
	}//end attribute()
}//end class
