<?php

/**
 * OpenRegister BulkRelationScanner
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObjects
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

namespace OCA\OpenRegister\Service\Object\SaveObjects;

use OCA\OpenRegister\Service\Object\RelationDetectionTrait;

/**
 * Finds the relations in the object data of a bulk save, as dot paths to uuids and urls.
 *
 * A string in an array of objects always counts; any other string counts when the
 * schema declares it as a reference or when it looks like one. The rules differ from the
 * single save's RelationScanner: this one lets a schema read fail loudly, and reads the
 * schema's stored properties.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class BulkRelationScanner {
	use RelationDetectionTrait;

	/**
	 * The relations in one level of object data.
	 *
	 * @param array           $data The object data.
	 * @param BulkRelationRun $run  The scan of this level.
	 *
	 * @return array The relations, by dot path.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function scan(array $data, BulkRelationRun $run): array {
		$run->schemaProperties = $run->schema?->getProperties();
		foreach ($data as $key => $value) {
			if (is_string($key) === true && empty($key) === false) {
				$run->moveTo(key: $key);
				$run->relations = array_merge($run->relations, $this->propertyRelations(value: $value, run: $run));
			}
		}

		return $run->relations;
	}//end scan()

	/**
	 * The relations of one property value.
	 *
	 * @param mixed           $value The value.
	 * @param BulkRelationRun $run   The scan, on the property.
	 *
	 * @return array The relations found.
	 */
	private function propertyRelations(mixed $value, BulkRelationRun $run): array {
		if (is_array($value) === true) {
			return $this->arrayRelations(value: $value, run: $run);
		}

		if (is_string($value) === true && empty($value) === false && trim($value) !== '') {
			return $this->stringRelation(value: $value, run: $run);
		}

		return [];
	}//end propertyRelations()

	/**
	 * The relations in the items of an array value.
	 *
	 * @param array           $value The array.
	 * @param BulkRelationRun $run   The scan, on the property.
	 *
	 * @return array The relations found.
	 */
	private function arrayRelations(array $value, BulkRelationRun $run): array {
		$config = ($run->schemaProperties[$run->key] ?? null);
		$run->itemsAreObjects = ($config['type'] ?? '') === 'array' && ($config['items']['type'] ?? null) === 'object';

		$relations = [];
		foreach ($value as $index => $item) {
			$run->itemPath = $run->path . '.' . $index;
			$relations = array_merge($relations, $this->itemRelations(item: $item, run: $run));
		}

		return $relations;
	}//end arrayRelations()

	/**
	 * The relations of one array item: nested data is scanned, a string may be a relation itself.
	 *
	 * @param mixed           $item The item.
	 * @param BulkRelationRun $run  The scan, on the item.
	 *
	 * @return array The relations found.
	 */
	private function itemRelations(mixed $item, BulkRelationRun $run): array {
		if (is_array($item) === true) {
			return $this->scan(data: $item, run: new BulkRelationRun(prefix: $run->itemPath, schema: $run->schema));
		}

		if (is_string($item) === false || empty($item) === true) {
			return [];
		}

		if ($this->isRelationItem(item: $item, run: $run) === true) {
			return [$run->itemPath => $item];
		}

		return [];
	}//end itemRelations()

	/**
	 * Whether a string item is a relation: always in an array of objects, else when it looks like one.
	 *
	 * @param string          $item The item.
	 * @param BulkRelationRun $run  The scan, on the item.
	 *
	 * @return bool True when it is.
	 */
	private function isRelationItem(string $item, BulkRelationRun $run): bool {
		return $run->itemsAreObjects === true || $this->isRecordableReference(value: $item) === true;
	}//end isRelationItem()

	/**
	 * The relation of a string property, by its schema config or its own shape.
	 *
	 * @param string          $value The value.
	 * @param BulkRelationRun $run   The scan, on the property.
	 *
	 * @return array The relation, or nothing.
	 */
	private function stringRelation(string $value, BulkRelationRun $run): array {
		$config = ($run->schemaProperties[$run->key] ?? null);
		if ($this->isRecordableReference(value: $value, propertyConfig: $config) === true) {
			return [$run->path => $value];
		}

		return [];
	}//end stringRelation()
}//end class
