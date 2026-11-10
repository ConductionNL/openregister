<?php

/**
 * What a bulk save reads from a schema once, before it prepares any object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObjects;

use OCA\OpenRegister\Db\Schema;

/**
 * The metadata field map and the inverse relation properties of one schema.
 */
final class BulkSchemaAnalysis {

	/**
	 * The schema configuration key behind each metadata field.
	 */
	private const METADATA_FIELD_KEYS = [
		'name' => 'objectNameField',
		'description' => 'objectDescriptionField',
		'summary' => 'objectSummaryField',
		'image' => 'objectImageField',
		'slug' => 'objectSlugField',
	];

	/**
	 * Analyse a schema.
	 *
	 * @param Schema $schema The schema to analyse.
	 *
	 * @return array{metadataFields: array, inverseProperties: array, validationRequired: mixed, properties: mixed, configuration: mixed}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function analyse(Schema $schema): array {
		$config = $schema->getConfiguration();
		$properties = $schema->getProperties();

		return [
			'metadataFields' => $this->metadataFields(config: $config),
			'inverseProperties' => $this->inverseProperties(properties: $properties),
			'validationRequired' => $schema->getHardValidation(),
			'properties' => $properties,
			'configuration' => $config,
		];
	}//end analyse()

	/**
	 * The metadata fields the schema configures with a non-empty source field.
	 *
	 * @param array|null $config The schema configuration.
	 *
	 * @return array<string, mixed> Metadata field name to source property.
	 */
	private function metadataFields(?array $config): array {
		$fields = [];
		foreach (self::METADATA_FIELD_KEYS as $field => $configKey) {
			$fields[$field] = $config[$configKey] ?? null;
		}

		return array_filter($fields, static fn ($source): bool => empty($source) === false);
	}//end metadataFields()

	/**
	 * The properties that carry an inverse relation, by property name.
	 *
	 * @param array|null $properties The schema properties.
	 *
	 * @return array<string, array{inversedBy: mixed, writeBack: bool, isArray: bool}>
	 */
	private function inverseProperties(?array $properties): array {
		$inverse = [];
		foreach ($properties ?? [] as $name => $definition) {
			$relation = $this->inverseProperty(definition: $definition);
			if ($relation !== null) {
				$inverse[$name] = $relation;
			}
		}

		return $inverse;
	}//end inverseProperties()

	/**
	 * The inverse relation of one property, read from the property or else from its items.
	 *
	 * @param mixed $definition The property definition; anything but an array has no relation.
	 *
	 * @return array{inversedBy: mixed, writeBack: bool, isArray: bool}|null Null when the property has no inverse relation.
	 */
	private function inverseProperty(mixed $definition): ?array {
		if (is_array($definition) === false) {
			return null;
		}

		$relation = $this->ownRelation(definition: $definition) ?? $this->itemsRelation(definition: $definition);
		if ($relation === null) {
			return null;
		}

		$relation['isArray'] = ($definition['type'] ?? null) === 'array';
		return $relation;
	}//end inverseProperty()

	/**
	 * The inverse relation the property declares itself.
	 *
	 * @param array $definition The property definition.
	 *
	 * @return array{inversedBy: mixed, writeBack: bool}|null Null when the property declares none.
	 */
	private function ownRelation(array $definition): ?array {
		if (isset($definition['inversedBy']) === false) {
			return null;
		}

		return ['inversedBy' => $definition['inversedBy'], 'writeBack' => self::writeBack(source: $definition)];
	}//end ownRelation()

	/**
	 * The inverse relation the property's items declare.
	 *
	 * A property-level writeBack that is true survives an items-level inversedBy.
	 *
	 * @param array $definition The property definition.
	 *
	 * @return array{inversedBy: mixed, writeBack: bool}|null Null when the items declare none.
	 */
	private function itemsRelation(array $definition): ?array {
		$items = $definition['items'] ?? [];
		if (isset($items['inversedBy']) === false) {
			return null;
		}

		$writeBack = self::writeBack(source: $definition) || self::writeBack(source: $items);
		return ['inversedBy' => $items['inversedBy'], 'writeBack' => $writeBack];
	}//end itemsRelation()

	/**
	 * The writeBack flag of a property or items definition.
	 *
	 * @param array $source The definition.
	 *
	 * @return bool The flag, false when absent.
	 */
	private static function writeBack(array $source): bool {
		return self::toBoolean(value: $source['writeBack'] ?? false);
	}//end writeBack()

	/**
	 * Read a schema flag as a boolean: a string is true only when it reads "true".
	 *
	 * @param mixed $value The flag value.
	 *
	 * @return bool The flag.
	 */
	private static function toBoolean(mixed $value): bool {
		if (is_string($value) === true) {
			return strtolower(trim($value)) === 'true';
		}

		return (bool) $value;
	}//end toBoolean()
}//end class
