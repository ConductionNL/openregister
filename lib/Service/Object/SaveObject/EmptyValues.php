<?php

/**
 * OpenRegister EmptyValues
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
use OCP\IURLGenerator;

/**
 * What counts as empty in object data, and how empty values are written.
 *
 * Optional properties: an empty object or an empty scalar string becomes null, which
 * clears the field. Required properties keep the empty value, so validation reports it
 * clearly. An empty string in an object or array property, or in the items of an array,
 * becomes null.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class EmptyValues {

	/**
	 * @param IURLGenerator $urls Builds the schema object.
	 */
	public function __construct(
		private readonly IURLGenerator $urls,
	) {
	}//end __construct()

	/**
	 * Object data with empty values handled by the schema's property definitions.
	 *
	 * @param array  $data   The object data.
	 * @param Schema $schema The schema.
	 *
	 * @return array The sanitized data; the data as given when the schema cannot be read.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function sanitize(array $data, Schema $schema): array {
		$shape = $this->shape(schema: $schema);
		foreach ($shape['properties'] as $name => $definition) {
			if (isset($data[$name]) === true) {
				$required = in_array($name, $shape['required']) || ($definition['required'] ?? false);
				$data[$name] = self::sanitized(value: $data[$name], rule: ['type' => $definition['type'] ?? null, 'required' => $required]);
			}
		}

		return $data;
	}//end sanitize()

	/**
	 * The properties and required names of a schema; empty when it cannot be read.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array{properties: array, required: array}
	 */
	private function shape(Schema $schema): array {
		try {
			$decoded = json_decode(json_encode($schema->getSchemaObject($this->urls)), associative: true);
		} catch (Exception $e) {
			return ['properties' => [], 'required' => []];
		}

		return ['properties' => $decoded['properties'] ?? [], 'required' => $decoded['required'] ?? []];
	}//end shape()

	/**
	 * One property value after sanitizing.
	 *
	 * @param mixed                              $value The property value.
	 * @param array{type: mixed, required: bool} $rule  The property's type and whether it is required.
	 *
	 * @return mixed The value.
	 */
	private static function sanitized(mixed $value, array $rule): mixed {
		if ($rule['required'] === false && self::clearsOptional(value: $value, type: $rule['type']) === true) {
			return null;
		}

		return self::withoutEmptyStrings(value: $value, type: $rule['type']);
	}//end sanitized()

	/**
	 * Whether a value clears an optional property: an empty object, or an empty string for a scalar type.
	 *
	 * @param mixed $value The property value.
	 * @param mixed $type  The property type.
	 *
	 * @return bool True when the value becomes null.
	 */
	private static function clearsOptional(mixed $value, mixed $type): bool {
		if ($type === 'object') {
			return is_array($value) === true && empty($value) === true;
		}

		return $value === '' && in_array($type, ['string', 'number', 'integer', 'boolean']) === true;
	}//end clearsOptional()

	/**
	 * An object or array property value with empty strings turned into null: the value itself,
	 * or each item of an array.
	 *
	 * @param mixed $value The property value.
	 * @param mixed $type  The property type.
	 *
	 * @return mixed The value.
	 */
	private static function withoutEmptyStrings(mixed $value, mixed $type): mixed {
		if ($type !== 'object' && $type !== 'array') {
			return $value;
		}

		if ($type === 'array' && is_array($value) === true) {
			return array_map(fn ($item) => self::emptyToNull(value: $item), $value);
		}

		return self::emptyToNull(value: $value);
	}//end withoutEmptyStrings()

	/**
	 * Null for an empty string, else the value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The value.
	 */
	private static function emptyToNull(mixed $value): mixed {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end emptyToNull()

	/**
	 * Whether an object holds no meaningful data: only `@self`, ids and empty values.
	 *
	 * @param array $object The object data.
	 *
	 * @return bool True when there is nothing to cascade.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function isEmptyObject(array $object): bool {
		foreach (array_diff_key($object, array_flip(['@self', 'id', '_id'])) as $value) {
			if ($this->hasContent(value: $value) === true) {
				return false;
			}
		}

		return true;
	}//end isEmptyObject()

	/**
	 * Whether a value holds meaningful data.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool False for null, a blank string, or an array of only such values.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function hasContent(mixed $value): bool {
		if (is_array($value) === true) {
			return $this->arrayHasContent(value: $value);
		}

		if (is_string($value) === true) {
			return trim($value) !== '';
		}

		return $value !== null;
	}//end hasContent()

	/**
	 * Whether an array holds meaningful data: as an object when it has keys, else by any item.
	 *
	 * @param array $value The array.
	 *
	 * @return bool True when it does.
	 */
	private function arrayHasContent(array $value): bool {
		if (array_is_list($value) === false) {
			return $this->isEmptyObject(object: $value) === false;
		}

		foreach ($value as $item) {
			if ($this->hasContent(value: $item) === true) {
				return true;
			}
		}

		return false;
	}//end arrayHasContent()
}//end class
