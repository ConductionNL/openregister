<?php

/**
 * MagicSchemaColumns: the columns a schema's magic table needs.
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
 * @spec openspec/specs/magic-table-columns/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\ObjectHandling;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\RuleColumns;
use Psr\Log\LoggerInterface;

/**
 * Turn a schema into column definitions: the metadata columns, the columns of
 * its linked types and one column per property.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class MagicSchemaColumns {
	use MagicColumnNameTrait;

	/**
	 * Schema keys that configure the schema instead of naming a property.
	 *
	 * `title`, `description` and `type` are absent: they are legitimate
	 * property names (catalog.title, module.type).
	 */
	public const CONFIGURATION_FIELDS = [
		'objectNameField',
		'objectDescriptionField',
		'objectSummaryField',
		'required',
		'$schema',
		'$id',
	];

	/**
	 * The column shape of each string format with a column of its own.
	 */
	private const STRING_FORMATS = [
		'date' => ['type' => 'datetime', 'nullable' => true, 'index' => true],
		'date-time' => ['type' => 'datetime', 'nullable' => true, 'index' => true],
		// RFC 5321 email length limit.
		'email' => ['type' => 'string', 'length' => 320, 'nullable' => true, 'index' => true],
		'uri' => ['type' => 'text', 'nullable' => true],
		'url' => ['type' => 'text', 'nullable' => true],
		'uuid' => ['type' => 'string', 'length' => 36, 'nullable' => true, 'index' => true],
	];

	/**
	 * Build the planner.
	 *
	 * @param LoggerInterface $logger Receives skipped properties and collisions.
	 */
	public function __construct(private readonly LoggerInterface $logger) {
	}//end __construct()

	/**
	 * The column definitions of a schema's table.
	 *
	 * Metadata columns are keyed by column name, property columns by property name.
	 *
	 * @param Schema $schema The schema to analyse.
	 *
	 * @return (bool|int|mixed|null|string)[][] Column definitions.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function forSchema(Schema $schema): array {
		$columns = array_merge(MagicMetadataColumns::ALL, $this->linkedTypeColumns(schema: $schema));
		$claims = new MagicColumnNameClaims(columns: $columns, logger: $this->logger);

		foreach ($schema->getProperties() as $key => $propertyConfig) {
			$propertyName = (string)$key;
			// A planned column keeps its definition: a property of that name is never written (REQ-MTR-001).
			if ($this->isProperty(name: $propertyName, config: $propertyConfig) === false || isset($columns[$propertyName]) === true) {
				continue;
			}

			$column = $this->storedColumn(propertyName: $propertyName, propertyConfig: $propertyConfig);
			$column['name'] = $claims->claim(propertyName: $propertyName, column: $column['name']);
			$columns[$propertyName] = $column;
		}

		return $columns;
	}//end forSchema()

	/**
	 * The column definition of one schema property.
	 *
	 * @param string $propertyName   The property name.
	 * @param array  $propertyConfig The property configuration from the JSON schema.
	 *
	 * @return (bool|int|mixed|null|string)[] Column definition.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function forProperty(string $propertyName, array $propertyConfig): array {
		$columnName = $this->sanitizeColumnName(name: $propertyName);

		return match ($this->declaredType(propertyConfig: $propertyConfig)) {
			'string' => $this->stringColumn(columnName: $columnName, propertyConfig: $propertyConfig),
			'integer' => $this->integerColumn(columnName: $columnName, propertyConfig: $propertyConfig),
			'number' => $this->numberColumn(columnName: $columnName, propertyConfig: $propertyConfig),
			'boolean' => $this->booleanColumn(propertyName: $propertyName, propertyConfig: $propertyConfig),
			'file' => $this->fileColumn(propertyName: $propertyName, propertyConfig: $propertyConfig),
			'array', 'object' => $this->structuredColumn(propertyName: $propertyName, propertyConfig: $propertyConfig),
			default => $this->unknownTypeColumn(propertyName: $propertyName, propertyConfig: $propertyConfig),
		};
	}//end forProperty()

	/**
	 * Whether a schema entry is a real property.
	 *
	 * @param mixed $name   The entry's key.
	 * @param mixed $config The entry's value; a real property has an array.
	 *
	 * @return bool
	 */
	private function isProperty(mixed $name, mixed $config): bool {
		if (in_array($name, self::CONFIGURATION_FIELDS, true) === true) {
			return false;
		}

		if (is_array($config) === true) {
			return true;
		}

		$this->logger->debug(
			message: '[MagicMapper] Skipping non-array property in schema',
			context: ['file' => __FILE__, 'line' => __LINE__, 'propertyName' => $name, 'propertyType' => gettype($config)]
		);

		return false;
	}//end isProperty()

	/**
	 * The column a property is stored in, encrypted or not.
	 *
	 * An `x-openregister-encrypted` property reaches the table as an
	 * `openregister:enc:v1:` envelope, an opaque string whatever type the
	 * schema declares. Its column is plain nullable TEXT without an index: it
	 * holds the ciphertext and stays useless for filtering, sorting and faceting.
	 *
	 * @param string $propertyName   The property name.
	 * @param array  $propertyConfig The property configuration.
	 *
	 * @return array Column definition.
	 */
	private function storedColumn(string $propertyName, array $propertyConfig): array {
		$column = $this->forProperty(propertyName: $propertyName, propertyConfig: $propertyConfig);
		if (($propertyConfig['x-openregister-encrypted'] ?? false) !== true) {
			return $column;
		}

		return [
			'name'     => $column['name'],
			'type'     => 'text',
			'nullable' => true,
			'comment'  => 'Encrypted value (x-openregister-encrypted)',
		];
	}//end storedColumn()

	/**
	 * The JSON columns of the linked types a schema declares.
	 *
	 * `files` has no entry: _files is a metadata column already.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array Column definitions keyed by column name.
	 */
	private function linkedTypeColumns(Schema $schema): array {
		$columns = [];
		foreach ($schema->getLinkedTypes() as $type) {
			$columnName = RuleColumns::LINKED_TYPES[$type] ?? null;
			if ($columnName !== null) {
				$columns[$columnName] = ['name' => $columnName, 'type' => 'json', 'nullable' => true, 'index' => true];
			}
		}

		return $columns;
	}//end linkedTypeColumns()

	/**
	 * The declared type of a property; untyped is a string, and so is `true`.
	 *
	 * @param array $propertyConfig The property configuration.
	 *
	 * @return mixed The type.
	 */
	private function declaredType(array $propertyConfig): mixed {
		$type = $propertyConfig['type'] ?? 'string';
		if ($type === true) {
			return 'string';
		}

		return $type;
	}//end declaredType()

	/**
	 * Whether a property is required: `required` is a boolean on the property
	 * or a list of names on the schema.
	 *
	 * @param string $name           The name to look for in a list.
	 * @param array  $propertyConfig The property configuration.
	 *
	 * @return bool
	 */
	private function isRequiredIn(string $name, array $propertyConfig): bool {
		$required = $propertyConfig['required'] ?? false;
		if (is_array($required) === true) {
			// Loose comparison, as the schema's required list has always been read.
			return in_array($name, $required);
		}

		return $required === true;
	}//end isRequiredIn()

	/**
	 * The column of a boolean property.
	 *
	 * @param string $propertyName   The property name.
	 * @param array  $propertyConfig The property configuration.
	 *
	 * @return array Column definition.
	 */
	private function booleanColumn(string $propertyName, array $propertyConfig): array {
		return [
			'name' => $this->sanitizeColumnName(name: $propertyName),
			'type' => 'boolean',
			'nullable' => $this->isRequiredIn(name: $propertyName, propertyConfig: $propertyConfig) === false,
			'default' => $propertyConfig['default'] ?? null,
		];
	}//end booleanColumn()

	/**
	 * The column of a file property.
	 *
	 * It stores the file ID after FilePropertyHandler ran. TEXT, not json: raw
	 * base64 stored by accident must not fail as invalid json.
	 *
	 * @param string $propertyName   The property name.
	 * @param array  $propertyConfig The property configuration.
	 *
	 * @return array Column definition.
	 */
	private function fileColumn(string $propertyName, array $propertyConfig): array {
		return [
			'name' => $this->sanitizeColumnName(name: $propertyName),
			'type' => 'text',
			'nullable' => $this->isRequiredIn(name: $propertyName, propertyConfig: $propertyConfig) === false,
			'comment' => 'File ID reference',
		];
	}//end fileColumn()

	/**
	 * The JSON column of a property of an unknown type.
	 *
	 * @param string $propertyName   The property name.
	 * @param array  $propertyConfig The property configuration.
	 *
	 * @return array Column definition.
	 */
	private function unknownTypeColumn(string $propertyName, array $propertyConfig): array {
		$this->logger->warning(
			message: '[MagicMapper] Unknown schema property type, storing as JSON',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'propertyName' => $propertyName,
				'type' => $propertyConfig['type'],
			]
		);

		return ['name' => $this->sanitizeColumnName(name: $propertyName), 'type' => 'json', 'nullable' => true];
	}//end unknownTypeColumn()

	/**
	 * The column of an array or object property.
	 *
	 * A relating object reference stores a bare UUID string, so it gets a
	 * VARCHAR column (a json column refused the raw UUID with SQLSTATE 22P02).
	 * Any other object gets an order-preserving JSON column: JSONB reorders
	 * keys, which breaks object-keyed schemas whose order matters (#1720).
	 * Arrays keep JSONB, which the search and facet handlers query with
	 * containment operators.
	 *
	 * @param string $propertyName   The property name.
	 * @param array  $propertyConfig The property configuration.
	 *
	 * @return array Column definition.
	 */
	private function structuredColumn(string $propertyName, array $propertyConfig): array {
		$shape = ['type' => 'json', 'nullable' => true];
		if ($propertyConfig['type'] === 'object') {
			$shape = $this->objectShape(propertyName: $propertyName, propertyConfig: $propertyConfig);
		}

		$shape['nullable'] = $this->isRequiredIn(name: $propertyName, propertyConfig: $propertyConfig) === false;

		return ['name' => $this->sanitizeColumnName(name: $propertyName)] + $shape;
	}//end structuredColumn()

	/**
	 * The column shape of an object property: a reference or ordered JSON.
	 *
	 * @param string $propertyName   The property name.
	 * @param array  $propertyConfig The property configuration.
	 *
	 * @return array Shape without name.
	 */
	private function objectShape(string $propertyName, array $propertyConfig): array {
		$reference = $this->resolveObjectReference(propertyConfig: $propertyConfig);
		if ($reference === null) {
			return ['type' => 'json_ordered', 'nullable' => true, 'comment' => 'Order-preserving JSON (object-typed schema property)'];
		}

		$this->logger->debug(
			message: '[MagicMapper] Detected object reference property, using VARCHAR for UUID storage',
			context: ['propertyName' => $propertyName, '$ref' => $propertyConfig['$ref'] ?? 'nested in items.oneOf', 'handling' => $reference]
		);

		return ['type' => 'string', 'length' => 255, 'nullable' => true, 'comment' => 'Object reference (UUID)'];
	}//end objectShape()

	/**
	 * The relating handling of a property that references another object, or
	 * null when it does not. Without a handling of its own, the first
	 * `items.oneOf` entry with a relating handling decides.
	 *
	 * @param array $propertyConfig The property configuration.
	 *
	 * @return string|null The handling (`related-object` or `related-schema`), or null.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectHandling::relates() is a pure enum-style helper
	 */
	private function resolveObjectReference(array $propertyConfig): ?string {
		$source = $propertyConfig;
		if (($propertyConfig['objectConfiguration']['handling'] ?? null) === null) {
			$source = $this->firstRelatingItem(propertyConfig: $propertyConfig) ?? $propertyConfig;
		}

		$handling = $source['objectConfiguration']['handling'] ?? null;
		if (isset($source['$ref']) === true && ObjectHandling::relates($handling) === true) {
			return $handling;
		}

		return null;
	}//end resolveObjectReference()

	/**
	 * The first `items.oneOf` entry with a relating handling.
	 *
	 * @param array $propertyConfig The property configuration.
	 *
	 * @return array|null The entry, or null.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectHandling::relates() is a pure enum-style helper
	 */
	private function firstRelatingItem(array $propertyConfig): ?array {
		foreach ($propertyConfig['items']['oneOf'] ?? [] as $item) {
			$handling = $item['objectConfiguration']['handling'] ?? null;
			if ($handling !== null && ObjectHandling::relates($handling) === true) {
				return $item;
			}
		}

		return null;
	}//end firstRelatingItem()

	/**
	 * The column of a string property.
	 *
	 * @param string $columnName     The column name.
	 * @param array  $propertyConfig The property configuration.
	 *
	 * @return (bool|int|string)[]
	 */
	private function stringColumn(string $columnName, array $propertyConfig): array {
		$shape = self::STRING_FORMATS[$this->stringFormat(propertyConfig: $propertyConfig)]
			?? $this->plainStringShape(maxLength: $propertyConfig['maxLength'] ?? null);
		$shape['nullable'] = $this->isRequiredIn(name: $columnName, propertyConfig: $propertyConfig) === false;

		return ['name' => $columnName] + $shape;
	}//end stringColumn()

	/**
	 * The format of a string property, the empty string without one.
	 *
	 * @param array $propertyConfig The property configuration.
	 *
	 * @return string The format; a non-string format is a TypeError.
	 */
	private function stringFormat(array $propertyConfig): string {
		return $propertyConfig['format'] ?? '';
	}//end stringFormat()

	/**
	 * The column shape of a string without a special format.
	 *
	 * A string of at most 255 characters is a VARCHAR, indexed up to 100
	 * characters for filtering; anything longer or unbounded is TEXT.
	 *
	 * @param mixed $maxLength The property's maxLength.
	 *
	 * @return array Shape without name.
	 */
	private function plainStringShape(mixed $maxLength): array {
		if ($maxLength === null || $maxLength > 255) {
			return ['type' => 'text', 'nullable' => true];
		}

		return ['type' => 'string', 'length' => $maxLength, 'nullable' => true, 'index' => $maxLength <= 100];
	}//end plainStringShape()

	/**
	 * The column of an integer property.
	 *
	 * @param string $columnName     The column name.
	 * @param array  $propertyConfig The property configuration.
	 *
	 * @return (bool|mixed|null|string)[]
	 */
	private function integerColumn(string $columnName, array $propertyConfig): array {
		return [
			'name' => $columnName,
			'type' => $this->integerType(propertyConfig: $propertyConfig),
			'nullable' => $this->isRequiredIn(name: $columnName, propertyConfig: $propertyConfig) === false,
			'default' => $propertyConfig['default'] ?? null,
			'index' => true,
		];
	}//end integerColumn()

	/**
	 * The integer type that fits a property's declared range.
	 *
	 * @param array $propertyConfig The property configuration.
	 *
	 * @return string `smallint`, `integer` or `bigint`.
	 */
	private function integerType(array $propertyConfig): string {
		$maximum = $propertyConfig['maximum'] ?? null;
		if ($maximum === null) {
			return 'integer';
		}

		if ($maximum > 2147483647) {
			return 'bigint';
		}

		if (($propertyConfig['minimum'] ?? -1) >= 0 && $maximum <= 65535) {
			return 'smallint';
		}

		return 'integer';
	}//end integerType()

	/**
	 * The DECIMAL column of a number property.
	 *
	 * @param string $columnName     The column name.
	 * @param array  $propertyConfig The property configuration.
	 *
	 * @return array Column definition.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
	 */
	private function numberColumn(string $columnName, array $propertyConfig): array {
		$size = (new MagicNumberColumn())->size(propertyConfig: $propertyConfig);

		return [
			'name' => $columnName,
			'type' => 'decimal',
			'precision' => $size['precision'],
			'scale' => $size['scale'],
			'nullable' => $this->isRequiredIn(name: $columnName, propertyConfig: $propertyConfig) === false,
			'default' => $propertyConfig['default'] ?? null,
			'index' => true,
		];
	}//end numberColumn()
}//end class
