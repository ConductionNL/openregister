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
 * @spec openspec/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Classify a magic-table column as metadata or as a schema property.
 *
 * @spec openspec/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
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
	 * @spec openspec/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
	 */
	public function field(string $column, array $columnToPropertyMap): ?string {
		if (str_starts_with($column, self::PREFIX) === false) {
			return null;
		}

		$field = substr($column, strlen(self::PREFIX));
		if (isset($columnToPropertyMap[$column]) === true && $this->attribute(field: $field) === null) {
			return null;
		}

		return $field;
	}//end field()

	/**
	 * Whether a key of an object being written is metadata or `@self`, not a property column.
	 *
	 * A key starting with the prefix is metadata unless the schema declares it as a property.
	 * A key is also metadata when the column it sanitises to is a metadata column, whatever
	 * its first character (`@owner`, `-organisation`, `.authorization`, `$status`).
	 * A declared property whose column is a metadata column (`_owner`, `_authorization`,
	 * `_status`, and the spellings that sanitise to one, `_Owner` or `__owner_`) is skipped
	 * too: the writer stamps the metadata columns from server-side values, and a client
	 * value for a property of that name must never land in the same column. The read side
	 * applies the same rule in {@see self::field()}: the metadata column wins.
	 *
	 * @param string               $key              The object key.
	 * @param array<string, mixed> $schemaProperties The schema's properties.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
	 */
	public function skippedOnWrite(string $key, array $schemaProperties): bool {
		if ($key === '@self') {
			return true;
		}

		// Decide on the column the writer would really use: `@owner`, `-organisation`,
		// `.authorization` and `$status` sanitise to metadata columns just as `_owner` does.
		if ($this->shadowsMetadata(key: $key) === true) {
			return true;
		}

		if (str_starts_with($key, self::PREFIX) === false) {
			return false;
		}

		return isset($schemaProperties[$key]) === false;
	}//end skippedOnWrite()

	/**
	 * Whether the column a property key is written to is a metadata column.
	 *
	 * @param string $key The property key.
	 *
	 * @return bool True when writing the property would write a metadata column.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
	 */
	public function shadowsMetadata(string $key): bool {
		$column = $this->columnFor(key: $key);
		if (str_starts_with($column, self::PREFIX) === false) {
			return false;
		}

		return $this->attribute(field: substr($column, strlen(self::PREFIX))) !== null;
	}//end shadowsMetadata()

	/**
	 * The column a property key is written to, as the writers sanitise it.
	 *
	 * The same rule MagicMapper and MagicBulkHandler apply (camelCase to snake_case,
	 * anything else but letters, digits and underscores to an underscore, runs of
	 * underscores to one, trailing underscores dropped), so a key is judged by the
	 * column it would really write.
	 *
	 * @param string $key The property key.
	 *
	 * @return string The column name.
	 */
	private function columnFor(string $key): string {
		$name = strtolower((string)preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key));
		$name = (string)preg_replace('/[^a-z0-9_]/', '_', $name);
		$name = (string)preg_replace('/_+/', '_', $name);

		return rtrim($name, '_');
	}//end columnFor()

	/**
	 * The ObjectEntity attribute a metadata field sets, or null when it has none.
	 *
	 * @param string $field The metadata field (`uuid`, `schema_version`).
	 *
	 * @return string|null The attribute name (`uuid`, `schemaVersion`), or null.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
	 */
	public function attribute(string $field): ?string {
		$attribute = lcfirst(str_replace('_', '', ucwords($field, '_')));
		if ($attribute === '' || property_exists(ObjectEntity::class, $attribute) === false) {
			return null;
		}

		return $attribute;
	}//end attribute()
}//end class
