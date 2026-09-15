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
			if (in_array($target, self::RESERVED_TARGETS, true) === true) {
				continue;
			}

			if (str_starts_with($target, '@') === true) {
				continue;
			}

			// A nested target names the property it lands in.
			$root = explode('.', $target)[0];

			if (array_key_exists($root, $properties) === false) {
				$unknown[] = $target;
			}
		}

		return array_values(array_unique($unknown));
	}//end unknownTargets()

	/**
	 * Every target a mapping names, from its field mappings and its defaults.
	 *
	 * @param array<string, mixed> $definition The mapping definition.
	 *
	 * @return array<int, string> The target names.
	 */
	private static function targets(array $definition): array {
		$targets = [];

		foreach (($definition['fieldMappings'] ?? []) as $mapping) {
			if (is_array($mapping) === false) {
				continue;
			}

			$target = ($mapping['target'] ?? null);

			if (is_string($target) === true && $target !== '') {
				$targets[] = $target;
			}
		}

		$defaults = ($definition['defaults'] ?? []);

		if (is_array($defaults) === true) {
			foreach (array_keys($defaults) as $target) {
				if (is_string($target) === true && $target !== '') {
					$targets[] = $target;
				}
			}
		}

		return $targets;
	}//end targets()
}//end class
