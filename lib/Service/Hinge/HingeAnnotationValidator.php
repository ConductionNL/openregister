<?php

/**
 * HingeAnnotationValidator — schema-save checks for the lens, list and
 * geographic-inheritance annotations.
 *
 * Each of the three names properties on the schema it is declared on. A name
 * that matches nothing renders as empty, which is indistinguishable from a
 * property that simply has no value — the silent no-op this validator exists to
 * turn into a warning at save time, where the author can still see it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Hinge
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hinge;

/**
 * Pure validation of the three hinge annotations. No I/O, no framework.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hinge
 */
class HingeAnnotationValidator {

	/**
	 * Validate the lens, list and geographic-inheritance blocks of one schema.
	 *
	 * @param array $schema The schema shape: `properties` plus the three annotations.
	 *
	 * @return array<int, array{code: string, message: string}> The findings, empty when all is well.
	 *
	 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
	 */
	public function validate(array $schema): array {
		$properties = ($schema['properties'] ?? []);
		$propertyNames = [];
		if (is_array($properties) === true) {
			$propertyNames = array_map('strval', array_keys($properties));
		}

		return array_merge(
			$this->validateLenses(
				lenses: ($schema['x-openregister-lenses'] ?? null),
				propertyNames: $propertyNames
			),
			$this->validateList(
				list: ($schema['x-openregister-list'] ?? null),
				propertyNames: $propertyNames
			),
			$this->validateGeoInheritance(
				geo: ($schema['x-openregister-geo-inheritance'] ?? null),
				propertyNames: $propertyNames
			)
		);
	}//end validate()

	/**
	 * Check every lens names a reference property that exists and a field to read.
	 *
	 * @param mixed              $lenses        The declared lens map.
	 * @param array<int, string> $propertyNames The schema's own property names.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 */
	private function validateLenses(mixed $lenses, array $propertyNames): array {
		if (is_array($lenses) === false) {
			return [];
		}

		$errors = [];
		foreach ($lenses as $name => $spec) {
			$errors = array_merge(
				$errors,
				$this->validateOneLens(name: (string)$name, spec: $spec, propertyNames: $propertyNames)
			);
		}

		return $errors;
	}//end validateLenses()

	/**
	 * Check one lens: its two halves, and that it does not shadow a stored property.
	 *
	 * @param string             $name          The property name the lens renders as.
	 * @param mixed              $spec          The declared lens.
	 * @param array<int, string> $propertyNames The schema's own property names.
	 *
	 * @return array<int, array{code: string, message: string}> The findings for this lens.
	 */
	private function validateOneLens(string $name, mixed $spec, array $propertyNames): array {
		if (is_array($spec) === false) {
			return [
				[
					'code' => 'lens-malformed',
					'message' => sprintf('Lens "%s" must be an object declaring through and property.', $name),
				],
			];
		}

		$errors = [];

		$through = ($spec['through'] ?? null);
		if (is_string($through) === false || $through === '') {
			$errors[] = [
				'code' => 'lens-missing-through',
				'message' => sprintf('Lens "%s" does not name the reference property to look through.', $name),
			];
		} elseif (in_array($through, $propertyNames, true) === false) {
			$errors[] = [
				'code' => 'lens-unknown-through',
				'message' => sprintf('Lens "%s" looks through "%s", which this schema does not declare.', $name, $through),
			];
		}

		$property = ($spec['property'] ?? null);
		if (is_string($property) === false || $property === '') {
			$errors[] = [
				'code' => 'lens-missing-property',
				'message' => sprintf('Lens "%s" does not name the property to read.', $name),
			];
		}

		if (in_array($name, $propertyNames, true) === true) {
			$errors[] = [
				'code' => 'lens-shadows-property',
				'message' => sprintf('Lens "%s" has the name of a stored property, which it would overwrite on every read.', $name),
			];
		}

		return $errors;
	}//end validateOneLens()

	/**
	 * Check the declared columns and search fields name properties that exist.
	 *
	 * @param mixed              $list          The declared list block.
	 * @param array<int, string> $propertyNames The schema's own property names.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 */
	private function validateList(mixed $list, array $propertyNames): array {
		if (is_array($list) === false) {
			return [];
		}

		return array_merge(
			$this->validateListColumns(columns: ($list['columns'] ?? []), propertyNames: $propertyNames),
			$this->validateSearchFields(fields: ($list['searchFields'] ?? []), propertyNames: $propertyNames)
		);
	}//end validateList()

	/**
	 * Check every declared column names a property the schema has.
	 *
	 * @param mixed              $columns       The declared columns.
	 * @param array<int, string> $propertyNames The schema's own property names.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 */
	private function validateListColumns(mixed $columns, array $propertyNames): array {
		if (is_array($columns) === false) {
			return [];
		}

		$errors = [];
		foreach ($columns as $column) {
			$property = $column;
			if (is_array($column) === true) {
				$property = ($column['property'] ?? null);
			}

			if (is_string($property) === false || $property === '') {
				$errors[] = [
					'code' => 'list-column-unnamed',
					'message' => 'A declared list column names no property.',
				];
				continue;
			}

			if ($this->isKnown(name: $property, propertyNames: $propertyNames) === false) {
				$errors[] = [
					'code' => 'list-column-unknown',
					'message' => sprintf('List column "%s" is not a property of this schema.', $property),
				];
			}
		}

		return $errors;
	}//end validateListColumns()

	/**
	 * Check every declared search field names a property the schema has.
	 *
	 * @param mixed              $fields        The declared search fields.
	 * @param array<int, string> $propertyNames The schema's own property names.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 */
	private function validateSearchFields(mixed $fields, array $propertyNames): array {
		if (is_array($fields) === false) {
			return [];
		}

		$errors = [];
		foreach ($fields as $field) {
			if (is_string($field) === false || $field === '') {
				$errors[] = [
					'code' => 'list-search-field-unnamed',
					'message' => 'A declared search field names no property.',
				];
				continue;
			}

			if ($this->isKnown(name: $field, propertyNames: $propertyNames) === false) {
				$errors[] = [
					'code' => 'list-search-field-unknown',
					'message' => sprintf('Search field "%s" is not a property of this schema.', $field),
				];
			}
		}

		return $errors;
	}//end validateSearchFields()

	/**
	 * Check every inherited-geography source names a reference property.
	 *
	 * @param mixed              $geo           The declared geographic-inheritance block.
	 * @param array<int, string> $propertyNames The schema's own property names.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 */
	private function validateGeoInheritance(mixed $geo, array $propertyNames): array {
		if (is_array($geo) === false) {
			return [];
		}

		$sources = ($geo['from'] ?? null);
		if (is_array($sources) === false) {
			return [
				[
					'code' => 'geo-inheritance-empty',
					'message' => 'x-openregister-geo-inheritance must declare `from`: the reference properties to collect features through.',
				],
			];
		}

		$errors = [];
		foreach ($sources as $source) {
			$through = $source;
			if (is_array($source) === true) {
				$through = ($source['through'] ?? null);
			}

			if (is_string($through) === false || $through === '') {
				$errors[] = [
					'code' => 'geo-inheritance-unnamed',
					'message' => 'A geographic-inheritance source names no reference property.',
				];
				continue;
			}

			if (in_array($through, $propertyNames, true) === false) {
				$errors[] = [
					'code' => 'geo-inheritance-unknown',
					'message' => sprintf('Geographic inheritance reads through "%s", which this schema does not declare.', $through),
				];
			}
		}

		return $errors;
	}//end validateGeoInheritance()

	/**
	 * Whether a name reaches a declared property, allowing a dot path's root.
	 *
	 * @param string             $name          The declared name.
	 * @param array<int, string> $propertyNames The schema's own property names.
	 *
	 * @return bool True when the name reaches a declared property.
	 */
	private function isKnown(string $name, array $propertyNames): bool {
		if (in_array($name, $propertyNames, true) === true) {
			return true;
		}

		$root = explode('.', $name)[0];

		return in_array($root, $propertyNames, true);
	}//end isKnown()
}//end class
