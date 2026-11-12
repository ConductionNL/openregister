<?php

/**
 * ConfigurationFields: the object name and description fields of a schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\SchemaMapper;

use Exception;
use OCA\OpenRegister\Db\Schema;

/**
 * Checks and fills the configuration fields that name an object's title and description.
 *
 * Not strict_types: a scalar field value coerces to a string, as it did inside SchemaMapper.
 *
 * @spec openspec/specs/schema-configuration-paths/spec.md#requirement-a-name-or-description-field-may-be-a-dot-notation-path-req-scp-001
 */
final class ConfigurationFields {

	private const CANDIDATES = [
		'objectNameField' => ['name', 'naam', 'title', 'titel'],
		'objectDescriptionField' => ['description', 'beschrijving', 'omschrijving', 'summary'],
	];

	/**
	 * Constructor.
	 *
	 * @param Schema $schema The schema being saved.
	 */
	public function __construct(
		private readonly Schema $schema,
	) {
	}//end __construct()

	/**
	 * Validate that configuration fields exist in properties.
	 *
	 * @return void
	 *
	 * @throws Exception If a field doesn't exist.
	 *
	 * @spec openspec/specs/schema-configuration-paths/spec.md#requirement-a-name-or-description-field-may-be-a-dot-notation-path-req-scp-001
	 */
	public function validate(): void {
		$configuration = ($this->schema->getConfiguration() ?? []);
		foreach (array_keys(self::CANDIDATES) as $fieldName) {
			$fieldValue = ($configuration[$fieldName] ?? '');
			if (empty($fieldValue) === false) {
				$this->validateField(fieldValue: $fieldValue, fieldName: $fieldName);
			}
		}
	}//end validate()

	/**
	 * Auto-populate configuration name/description fields if empty.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-configuration-paths/spec.md#requirement-a-name-or-description-field-may-be-a-dot-notation-path-req-scp-001
	 */
	public function populate(): void {
		$configuration = ($this->schema->getConfiguration() ?? []);
		foreach (self::CANDIDATES as $fieldName => $candidates) {
			$matchedKey = $this->firstMatch(candidates: $candidates);
			if (empty(($configuration[$fieldName] ?? '')) === true && $matchedKey !== null) {
				$configuration[$fieldName] = $matchedKey;
				$this->schema->setConfiguration($configuration);
			}
		}
	}//end populate()

	/**
	 * Validate a configuration field value against the property keys.
	 *
	 * Supports multiple formats:
	 * - Simple property names: "name"
	 * - Twig-style templates: "{{ voornaam }} {{ tussenvoegsel }} {{ achternaam }}"
	 * - Pipe-separated fallbacks: "name | identifier | type" (uses first available)
	 * - Dot-notation paths into an object property: "description.nl.longDescription",
	 *   valid when the first segment is a property (the reader walks the rest)
	 *
	 * @param string $fieldValue The field value to validate.
	 * @param string $fieldName  Name of the field for error messages.
	 *
	 * @throws Exception If the field references non-existent properties.
	 *
	 * @return void
	 */
	private function validateField(string $fieldValue, string $fieldName): void {
		if (strpos($fieldValue, '{{') !== false && strpos($fieldValue, '}}') !== false) {
			$this->validateTemplate(fieldValue: $fieldValue, fieldName: $fieldName);
			return;
		}

		if (strpos($fieldValue, '|') !== false) {
			$this->validateFallbacks(fieldValue: $fieldValue, fieldName: $fieldName);
			return;
		}

		if ($this->isPropertyPath(path: $fieldValue) === false) {
			throw new Exception("The value for {$fieldName} ('{$fieldValue}') does not exist as a property in the schema.");
		}
	}//end validateField()

	/**
	 * Every `{{ property }}` of a template must be a property.
	 *
	 * @param string $fieldValue The template.
	 * @param string $fieldName  Name of the field for error messages.
	 *
	 * @throws Exception If a template property does not exist.
	 *
	 * @return void
	 */
	private function validateTemplate(string $fieldValue, string $fieldName): void {
		preg_match_all('/\{\{\s*([a-zA-Z0-9_-]+)\s*\}\}/', $fieldValue, $matches);
		foreach ($matches[1] as $prop) {
			if (in_array($prop, $this->propertyKeys(), true) === false) {
				throw new Exception("The template property '{$prop}' in {$fieldName} does not exist.");
			}
		}
	}//end validateTemplate()

	/**
	 * At least one field of a pipe-separated fallback list must be a property.
	 *
	 * @param string $fieldValue The fallback list.
	 * @param string $fieldName  Name of the field for error messages.
	 *
	 * @throws Exception If none does.
	 *
	 * @return void
	 */
	private function validateFallbacks(string $fieldValue, string $fieldName): void {
		foreach (array_map('trim', explode('|', $fieldValue)) as $field) {
			if ($this->isPropertyPath(path: $field) === true) {
				return;
			}
		}

		throw new Exception("None of the fallback fields in {$fieldName} ('{$fieldValue}') exist as properties in the schema.");
	}//end validateFallbacks()

	/**
	 * Whether a configuration path names a property of the schema.
	 *
	 * A plain name must be a property; a dot-notation path must start with one,
	 * as MetadataHydrationHandler::getValueFromPath() reads it.
	 *
	 * @param string $path The property name or dot-notation path.
	 *
	 * @return bool
	 */
	private function isPropertyPath(string $path): bool {
		if (in_array($path, $this->propertyKeys(), true) === true) {
			return true;
		}

		$first = strstr($path, '.', true);

		return $first !== false && $first !== '' && in_array($first, $this->propertyKeys(), true) === true;
	}//end isPropertyPath()

	/**
	 * The first candidate that is a property of the schema.
	 *
	 * @param array<int, string> $candidates The candidate keys.
	 *
	 * @return string|null
	 */
	private function firstMatch(array $candidates): ?string {
		foreach ($candidates as $key) {
			if (in_array($key, $this->propertyKeys()) === true) {
				return $key;
			}
		}

		return null;
	}//end firstMatch()

	/**
	 * The schema's property names.
	 *
	 * @return array<int|string>
	 */
	private function propertyKeys(): array {
		return array_keys($this->schema->getProperties());
	}//end propertyKeys()
}//end class
