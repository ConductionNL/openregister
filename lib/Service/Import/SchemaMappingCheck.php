<?php

/**
 * Checks a saved column mapping against the schema it is applied to.
 *
 * {@see \OCA\OpenRegister\Service\MigrationPack\PackDefinitionValidator}
 * deliberately knows nothing about a particular schema: the same mapping is
 * reusable against any schema whose property names match, so it can only
 * check the document's shape. That leaves one gap an operator meets in
 * practice: a mapping authored last month against a schema that has since
 * lost a property. Applied silently, the value lands on a property the schema
 * does not have.
 *
 * This is where that mapping is refused, naming the property, at the moment
 * it is bound to a schema.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use OCA\OpenRegister\Db\Schema;

/**
 * Names the mapping targets a schema does not have.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
final class SchemaMappingCheck {

	/**
	 * Targets that are never schema properties: the object's own identifier
	 * and anything in the `@self` box, which the save path owns.
	 *
	 * @var array<int, string>
	 */
	private const RESERVED_TARGETS = ['id', 'uuid'];

	/**
	 * Every target a mapping names that the schema does not have.
	 *
	 * @param array<string, mixed> $definition The mapping definition.
	 * @param Schema $schema The schema it is applied against.
	 *
	 * @return array<int, string> The unknown property names, in the order found.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public static function unknownTargets(array $definition, Schema $schema): array {
		$properties = $schema->getProperties();

		// A schema carrying no property list makes no claim about what exists,
		// so nothing can be called unknown against it.
		if (is_array($properties) === false || $properties === []) {
			return [];
		}

		$targets = self::targets(definition: $definition);
		$unknown = [];

		foreach ($targets as $target) {
			if (self::isUnknown(target: $target, properties: $properties) === true) {
				$unknown[] = $target;
			}
		}

		return array_values(array_unique($unknown));
	}//end unknownTargets()

	/**
	 * Whether a target names a property the schema does not have.
	 *
	 * @param string $target The target name.
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return bool True when the target is unknown.
	 */
	private static function isUnknown(string $target, array $properties): bool {
		if (in_array($target, self::RESERVED_TARGETS, true) === true || str_starts_with($target, '@') === true) {
			return false;
		}

		// A nested target names the property it lands in.
		$root = explode('.', $target)[0];

		return array_key_exists($root, $properties) === false;
	}//end isUnknown()

	/**
	 * Every target a mapping names, from its field mappings and its defaults.
	 *
	 * @param array<string, mixed> $definition The mapping definition.
	 *
	 * @return array<int, string> The target names.
	 */
	private static function targets(array $definition): array {
		return array_merge(
			self::mappingTargets(fieldMappings: ($definition['fieldMappings'] ?? [])),
			self::defaultTargets(defaults: ($definition['defaults'] ?? []))
		);
	}//end targets()

	/**
	 * Every target the field mappings name.
	 *
	 * @param mixed $fieldMappings The definition's field mappings.
	 *
	 * @return array<int, string> The target names, in order.
	 */
	private static function mappingTargets(mixed $fieldMappings): array {
		$targets = [];

		foreach ($fieldMappings as $mapping) {
			if (is_array($mapping) === false) {
				continue;
			}

			$target = ($mapping['target'] ?? null);

			if (self::isTargetName(target: $target) === true) {
				$targets[] = $target;
			}
		}

		return $targets;
	}//end mappingTargets()

	/**
	 * Every target the defaults name.
	 *
	 * @param mixed $defaults The definition's defaults.
	 *
	 * @return array<int, string> The target names, in order.
	 */
	private static function defaultTargets(mixed $defaults): array {
		if (is_array($defaults) === false) {
			return [];
		}

		$targets = [];
		foreach (array_keys($defaults) as $target) {
			if (self::isTargetName(target: $target) === true) {
				$targets[] = $target;
			}
		}

		return $targets;
	}//end defaultTargets()

	/**
	 * Whether a value is a usable target name: a non-empty string.
	 *
	 * @param mixed $target The value.
	 *
	 * @return bool True when it names a target.
	 */
	private static function isTargetName(mixed $target): bool {
		return is_string($target) === true && $target !== '';
	}//end isTargetName()
}//end class
