<?php

/**
 * OpenRegister RelationScanner
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Formats\UuidFormat;
use OCP\IURLGenerator;

/**
 * Finds the relations (UUIDs and URLs) in object data, by dot path.
 *
 * A string is a relation when its schema property says so: type 'text' with format
 * uuid, uri or url, type 'object', or an item of an array of objects. Any other string
 * is one when it looks like a reference.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class RelationScanner {

	/**
	 * Strings that have the shape of an identifier and are ordinary words.
	 */
	private const COMMON_WORDS = ['applicatie', 'systeemsoftware', 'open-source', 'closed-source'];

	/**
	 * @param IURLGenerator $urls Builds the schema object.
	 */
	public function __construct(private readonly IURLGenerator $urls) {
	}//end __construct()

	/**
	 * The relations in one level of data and below it.
	 *
	 * A failure keeps the relations found so far.
	 *
	 * @param array           $data The data.
	 * @param RelationScanRun $scan The scan, carrying its schema and prefix.
	 *
	 * @return array The relations, by dot path.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function scan(array $data, RelationScanRun $scan): array {
		try {
			$scan->schemaProperties = $this->schemaProperties(schema: $scan->schema);
			foreach ($data as $key => $value) {
				if (is_string($key) === true && empty($key) === false) {
					$scan->moveTo(key: $key);
					$this->scanValue(scan: $scan, value: $value);
				}
			}
		} catch (Exception $e) {
			return $scan->relations;
		}

		return $scan->relations;
	}//end scan()

	/**
	 * Whether a string looks like a reference to another object.
	 *
	 * @param string $value The string.
	 *
	 * @return bool True for a UUID in any of its forms, a numeric id, a URL or an identifier.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public static function isReference(string $value): bool {
		$value = trim($value);
		if (empty($value) === true) {
			return false;
		}

		return self::isUuidShaped(value: $value)
			|| preg_match('/^[0-9]+$/', $value) === 1
			|| filter_var($value, FILTER_VALIDATE_URL) !== false
			|| self::isIdentifier(value: $value);
	}//end isReference()

	/**
	 * The property definitions a scan reads, or null without a (readable) schema.
	 *
	 * @param Schema|null $schema The schema, if any.
	 *
	 * @return array|null The property definitions.
	 */
	private function schemaProperties(?Schema $schema): ?array {
		if ($schema === null) {
			return null;
		}

		try {
			$schemaObject = json_decode(json_encode($schema->getSchemaObject($this->urls)), associative: true);
			return $schemaObject['properties'] ?? [];
		} catch (Exception $e) {
			return null;
		}
	}//end schemaProperties()

	/**
	 * Scan the value of the key a scan is on.
	 *
	 * @param RelationScanRun $scan  The scan, on one key.
	 * @param mixed           $value The value.
	 *
	 * @return void
	 */
	private function scanValue(RelationScanRun $scan, mixed $value): void {
		if (empty($value) === true) {
			return;
		}

		if (is_array($value) === true) {
			$this->scanList(scan: $scan, value: $value);
			return;
		}

		if (is_string($value) === true && trim($value) !== '') {
			self::scanString(scan: $scan, value: $value);
		}
	}//end scanValue()

	/**
	 * Scan a list value: nested objects recursively, strings by the property kind.
	 *
	 * @param RelationScanRun $scan  The scan, on one key.
	 * @param array           $value The list.
	 *
	 * @return void
	 */
	private function scanList(RelationScanRun $scan, array $value): void {
		$ofObjects = self::isArrayOfObjects(propertyConfig: ($scan->schemaProperties[$scan->key] ?? null));

		foreach ($value as $index => $item) {
			$itemPath = $scan->path . '.' . $index;
			if (is_array($item) === true) {
				$nested = $this->scan(data: $item, scan: new RelationScanRun(prefix: $itemPath, schema: $scan->schema));
				$scan->relations = array_merge($scan->relations, $nested);
			} elseif (self::itemIsRelation(item: $item, ofObjects: $ofObjects) === true) {
				$scan->relations[$itemPath] = $item;
			}
		}
	}//end scanList()

	/**
	 * Whether a list item that is no array is a relation.
	 *
	 * In an array of objects every non-empty string is; elsewhere only reference-like ones.
	 *
	 * @param mixed $item      The item.
	 * @param bool  $ofObjects Whether the property is an array of objects.
	 *
	 * @return bool True for a relation.
	 */
	private static function itemIsRelation(mixed $item, bool $ofObjects): bool {
		if (is_string($item) === false || empty($item) === true) {
			return false;
		}

		return $ofObjects === true || (trim($item) !== '' && self::isReference(value: $item) === true);
	}//end itemIsRelation()

	/**
	 * Whether a property definition is an array of objects.
	 *
	 * @param mixed $propertyConfig The property definition, or null.
	 *
	 * @return bool True for an array whose items are objects.
	 */
	private static function isArrayOfObjects(mixed $propertyConfig): bool {
		return $propertyConfig
			&& ($propertyConfig['type'] ?? '') === 'array'
			&& isset($propertyConfig['items']['type'])
			&& $propertyConfig['items']['type'] === 'object';
	}//end isArrayOfObjects()

	/**
	 * Record a string as a relation when its property declares one or it looks like a reference.
	 *
	 * @param RelationScanRun $scan  The scan, on one key.
	 * @param string          $value The value.
	 *
	 * @return void
	 */
	private static function scanString(RelationScanRun $scan, string $value): void {
		$property = ($scan->schemaProperties[$scan->key] ?? null);
		if (self::declaresRelation(property: $property) === true || self::isReference(value: $value) === true) {
			$scan->relations[$scan->path] = $value;
		}
	}//end scanString()

	/**
	 * Whether a property definition makes every string in it a relation.
	 *
	 * @param mixed $property The property definition, or null.
	 *
	 * @return bool True for text with format uuid, uri or url, or an object.
	 */
	private static function declaresRelation(mixed $property): bool {
		if ($property === null) {
			return false;
		}

		$type = ($property['type'] ?? '');

		return $type === 'object'
			|| ($type === 'text' && in_array(($property['format'] ?? ''), ['uuid', 'uri', 'url']) === true);
	}//end declaresRelation()

	/**
	 * Whether a string is a UUID: canonical, undashed, or behind a word prefix.
	 *
	 * @param string $value The trimmed string.
	 *
	 * @return bool True for a UUID.
	 */
	private static function isUuidShaped(string $value): bool {
		return UuidFormat::isCanonical(value: $value) === true
			|| UuidFormat::isHex32(value: $value) === true
			|| UuidFormat::isPrefixed(value: $value) === true
			|| preg_match('/^[a-z]+-[0-9a-f]{32}$/i', $value) === 1;
	}//end isUuidShaped()

	/**
	 * Whether a string has the shape of an identifier and is no ordinary word.
	 *
	 * @param string $value The trimmed string.
	 *
	 * @return bool True for a hyphenated or underscored id of at least 8 characters.
	 */
	private static function isIdentifier(string $value): bool {
		return preg_match('/^[a-z0-9][a-z0-9_-]{7,}$/i', $value) === 1
			&& (str_contains($value, '-') === true || str_contains($value, '_') === true)
			&& in_array(strtolower($value), self::COMMON_WORDS, true) === false;
	}//end isIdentifier()
}//end class
