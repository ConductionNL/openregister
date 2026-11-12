<?php

/**
 * MagicPropertyColumns: the property columns written for one object.
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
 * @spec openspec/specs/magic-table-reads/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\Schema;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Turn an object's property values into the columns its row stores.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class MagicPropertyColumns {
	use MagicColumnNameTrait;

	/**
	 * The schema properties already warned about, once per process.
	 *
	 * @var array<string, true>
	 */
	private static array $warned = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the date normalizer.
	 * @param LoggerInterface    $logger    Receives dropped uploads and metadata-named properties.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The property columns written for one object, keyed by sanitised column name.
	 *
	 * A property absent from the data is skipped, so its column is left unchanged; a
	 * property present with a null value is written as null.
	 *
	 * A property whose column is a metadata column (`_owner`, `_authorization`, `_status`,
	 * ...) never writes it: the metadata columns are stamped from server-side values and
	 * the client's value must not replace them. The read side treats such a column as
	 * metadata too.
	 *
	 * @param array  $data   The object's property values, without `@self`.
	 * @param Schema $schema The schema context.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function forObject(array $data, Schema $schema): array {
		$columns = [];
		foreach ($schema->getProperties() as $name => $config) {
			if ($this->isWritable(name: $name, schema: $schema) === true && array_key_exists($name, $data) === true) {
				$property = ['name' => $name, 'config' => $config];
				$columns[$this->sanitizeColumnName(name: $name)] = $this->storedValue(property: $property, value: $data[$name]);
			}
		}

		return $columns;
	}//end forObject()

	/**
	 * Whether a property writes its own column; one named like a metadata column does not.
	 *
	 * @param int|string $name   The property.
	 * @param Schema     $schema The schema.
	 *
	 * @return bool
	 */
	private function isWritable(int|string $name, Schema $schema): bool {
		if (is_string($name) === false || (new MagicMetadataColumn())->shadowsMetadata(key: $name) === false) {
			return true;
		}

		$this->warnMetadataNamed(schema: $schema, propertyName: $name);

		return false;
	}//end isWritable()

	/**
	 * Warn, once per schema and property per process, that a property named like a
	 * metadata column is not written.
	 *
	 * @param Schema $schema       The schema.
	 * @param string $propertyName The property.
	 *
	 * @return void
	 */
	private function warnMetadataNamed(Schema $schema, string $propertyName): void {
		$warnedKey = $schema->getId().':'.$propertyName;
		if (isset(self::$warned[$warnedKey]) === true) {
			return;
		}

		self::$warned[$warnedKey] = true;
		$this->logger->warning(
			message: '[MagicMapper] A schema property is named like a metadata column; its value is not written',
			context: ['propertyName' => $propertyName, 'schema' => $schema->getId()]
		);
	}//end warnMetadataNamed()

	/**
	 * A property value as its column stores it.
	 *
	 * Booleans become 0/1, because some drivers turn PHP's false into an empty string.
	 * Arrays and objects become JSON. An empty string becomes NULL at the final
	 * insert/update stage, not here.
	 *
	 * @param array{name: int|string, config: mixed} $property The property's name and schema definition.
	 * @param mixed                                  $value    The value.
	 *
	 * @return mixed
	 */
	private function storedValue(array $property, mixed $value): mixed {
		$value = (new MagicUnprocessedUploads(logger: $this->logger))->without(property: $property, value: $value);
		$value = (new MagicPropertyDate(container: $this->container))->stored(value: $value, format: ($property['config']['format'] ?? null));

		if (is_bool($value) === true) {
			return (int)$value;
		}

		if (is_array($value) === true || is_object($value) === true) {
			return json_encode($value);
		}

		return $value;
	}//end storedValue()
}//end class
