<?php

/**
 * SchemaPropertyInheritance
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use Exception;

/**
 * Merges a child schema's properties over its resolved parent's.
 *
 * A child may override metadata freely and add constraints, but may not relax
 * a constraint of its parent (Liskov substitution). Each instance reports
 * violations for one schema, at one property path.
 *
 * @spec exclude Internal helper of SchemaMapper schema extension; behaviour pinned by SchemaPropertyInheritanceGoldenTest.
 */
final class SchemaPropertyInheritance {

	/**
	 * Fields a child may override freely.
	 */
	private const METADATA_FIELDS = [
		'title',
		'description',
		'order',
		'icon',
		'placeholder',
		'help',
		'example',
		'examples',
		'$comment',
		'deprecated',
		'readOnly',
		'writeOnly',
		'default',
		'x-order',
		'x-display',
		'x-tabName',
		'x-section',
		'ui:order',
		'ui:widget',
		'ui:options',
	];

	/**
	 * Fields whose change is checked against the parent's value.
	 */
	private const VALIDATION_FIELDS = [
		'type',
		'format',
		'pattern',
		'enum',
		'const',
		'minimum',
		'maximum',
		'exclusiveMinimum',
		'exclusiveMaximum',
		'minLength',
		'maxLength',
		'minItems',
		'maxItems',
		'minProperties',
		'maxProperties',
		'multipleOf',
		'uniqueItems',
		'required',
		'additionalProperties',
		'patternProperties',
		'dependencies',
		'if',
		'then',
		'else',
	];

	/**
	 * Constraints a child may not change once the parent sets them, with their violation message.
	 */
	private const FIXED_VALUE_MESSAGES = [
		'format' => "Schema '%s': Property '%s' cannot change format from '%s' to '%s'",
		'pattern' => "Schema '%s': Property '%s' pattern cannot be changed from '%s' to '%s'",
	];

	/**
	 * Numeric bounds, with the direction in which a child would relax them and its comparison sign.
	 */
	private const BOUND_RELAXATIONS = [
		'minimum' => ['decreased', -1],
		'minLength' => ['decreased', -1],
		'minItems' => ['decreased', -1],
		'minProperties' => ['decreased', -1],
		'maximum' => ['increased', 1],
		'maxLength' => ['increased', 1],
		'maxItems' => ['increased', 1],
		'maxProperties' => ['increased', 1],
	];

	/**
	 * Constructor.
	 *
	 * @param string $schemaId The child schema, for violation messages.
	 * @param string $path     The property path being merged, for violation messages.
	 *
	 * @spec exclude Internal helper of SchemaMapper schema extension, pinned by SchemaPropertyInheritanceGoldenTest.
	 */
	public function __construct(
		private readonly string $schemaId,
		private readonly string $path = '',
	) {
	}//end __construct()

	/**
	 * Merge the child's properties over the parent's.
	 *
	 * @param array $parentProperties The parent's resolved properties.
	 * @param array $childProperties  The child's own properties.
	 *
	 * @throws Exception If the child relaxes a constraint of the parent.
	 *
	 * @return array The merged properties.
	 *
	 * @spec exclude Internal helper of SchemaMapper schema extension, pinned by SchemaPropertyInheritanceGoldenTest.
	 */
	public function mergeProperties(array $parentProperties, array $childProperties): array {
		$merged = $parentProperties;

		foreach ($childProperties as $propertyName => $childProperty) {
			if (isset($merged[$propertyName]) === false) {
				$merged[$propertyName] = $childProperty;
				continue;
			}

			$parentProperty = $merged[$propertyName];
			if (is_array($parentProperty) === false || is_array($childProperty) === false) {
				$this->atPath(path: (string) $propertyName)->assertNotRemoved(pair: [$parentProperty, $childProperty]);
				$merged[$propertyName] = $childProperty;
				continue;
			}

			$merged[$propertyName] = $this->atPath(path: (string) $propertyName)->mergeProperty(
				parentProperty: $parentProperty,
				childProperty: $childProperty
			);
		}

		return $merged;
	}//end mergeProperties()

	/**
	 * The same merge, at another property path.
	 *
	 * @param string $path The property path.
	 *
	 * @return self The merge at that path.
	 */
	private function atPath(string $path): self {
		return new self(schemaId: $this->schemaId, path: $path);
	}//end at()

	/**
	 * Merge one child property definition over the parent's definition at this path.
	 *
	 * @param array $parentProperty The parent's definition.
	 * @param array $childProperty  The child's definition.
	 *
	 * @throws Exception If the child relaxes a constraint of the parent.
	 *
	 * @return array The merged definition.
	 */
	private function mergeProperty(array $parentProperty, array $childProperty): array {
		$merged = $parentProperty;

		foreach ($childProperty as $key => $childValue) {
			$merged[$key] = $childValue;
			if (isset($parentProperty[$key]) === true) {
				$merged[$key] = $this->mergeValue(key: $key, pair: [$parentProperty[$key], $childValue]);
			}
		}

		return $merged;
	}//end mergeProperty()

	/**
	 * Merge one key of a definition that both parent and child set.
	 *
	 * @param int|string $key  The definition key.
	 * @param array      $pair The parent's value and the child's value.
	 *
	 * @throws Exception If the child relaxes a constraint of the parent.
	 *
	 * @return mixed The merged value.
	 */
	private function mergeValue(int|string $key, array $pair): mixed {
		[$parentValue, $childValue] = $pair;

		if (in_array($key, self::METADATA_FIELDS) === true) {
			return $childValue;
		}

		$bothArrays = is_array($parentValue) === true && is_array($childValue) === true;
		if (in_array($key, ['properties', 'items'], true) === true && $bothArrays === true) {
			return $this->atPath(path: "{$this->path}.{$key}")->mergeNested(parentValue: $parentValue, childValue: $childValue);
		}

		if (in_array($key, self::VALIDATION_FIELDS) === true) {
			$this->assertNotRelaxed(constraint: (string) $key, pair: $pair);
			return $childValue;
		}

		if ($bothArrays === false) {
			return $childValue;
		}

		return $this->atPath(path: "{$this->path}.{$key}")->mergeProperty(parentProperty: $parentValue, childProperty: $childValue);
	}//end mergeValue()

	/**
	 * Merge nested property definitions (properties or items), keyed by name.
	 *
	 * @param array $parentValue The parent's nested definitions.
	 * @param array $childValue  The child's nested definitions.
	 *
	 * @throws Exception If the child relaxes a nested constraint.
	 *
	 * @return array The merged nested definitions.
	 */
	private function mergeNested(array $parentValue, array $childValue): array {
		$merged = [];
		foreach ($childValue as $nestedKey => $nestedChild) {
			if (($parentValue[$nestedKey] ?? null) === null) {
				$merged[$nestedKey] = $nestedChild;
				continue;
			}

			$merged[$nestedKey] = $this->atPath(path: "{$this->path}.{$nestedKey}")->mergeProperty(
				parentProperty: $parentValue[$nestedKey],
				childProperty: $nestedChild
			);
		}

		foreach ($parentValue as $nestedKey => $nestedParent) {
			if (isset($merged[$nestedKey]) === false) {
				$merged[$nestedKey] = $nestedParent;
			}
		}

		return $merged;
	}//end mergeNested()

	/**
	 * Reject a change of a validation field that relaxes the parent's constraint.
	 *
	 * @param string $constraint The validation field.
	 * @param array  $pair       The parent's value and the child's value.
	 *
	 * @throws Exception If the change relaxes the constraint.
	 *
	 * @return void
	 */
	private function assertNotRelaxed(string $constraint, array $pair): void {
		if ($constraint === 'type') {
			$this->assertTypeNotWidened(pair: $pair);
			return;
		}

		if ($constraint === 'enum') {
			$this->assertEnumNotWidened(pair: $pair);
			return;
		}

		if (isset(self::FIXED_VALUE_MESSAGES[$constraint]) === true) {
			$this->assertFixedValueKept(constraint: $constraint, pair: $pair);
			return;
		}

		if (isset(self::BOUND_RELAXATIONS[$constraint]) === true) {
			$this->assertBoundNotRelaxed(constraint: $constraint, pair: $pair);
		}
	}//end assertNotRelaxed()

	/**
	 * Reject a type change that admits types the parent does not.
	 *
	 * @param array $pair The parent's type and the child's type.
	 *
	 * @throws Exception If the child's type is not the parent's or a subset of it.
	 *
	 * @return void
	 */
	private function assertTypeNotWidened(array $pair): void {
		[$parentValue, $childValue] = $pair;
		if ($parentValue === $childValue) {
			return;
		}

		if (is_array($parentValue) === true && is_array($childValue) === true) {
			if (count(array_diff($childValue, $parentValue)) > 0) {
				throw new Exception(
					sprintf(
						"Schema '%s': Property '%s' cannot change type from %s to %s (adds types not in parent)",
						$this->schemaId,
						$this->path,
						json_encode($parentValue),
						json_encode($childValue)
					)
				);
			}

			return;
		}

		if (is_array($parentValue) === false && is_array($childValue) === false) {
			throw new Exception(
				sprintf("Schema '%s': Property '%s' cannot change type from '%s' to '%s'", $this->schemaId, $this->path, $parentValue, $childValue)
			);
		}

		throw new Exception("Schema '{$this->schemaId}': Property '{$this->path}' type change is not compatible");
	}//end assertTypeNotWidened()

	/**
	 * Reject an enum that adds values the parent does not allow.
	 *
	 * @param array $pair The parent's enum and the child's enum.
	 *
	 * @throws Exception If the child's enum adds values.
	 *
	 * @return void
	 */
	private function assertEnumNotWidened(array $pair): void {
		[$parentValue, $childValue] = $pair;
		if (is_array($parentValue) === false || is_array($childValue) === false) {
			return;
		}

		$diff = array_diff($childValue, $parentValue);
		if (count($diff) > 0) {
			throw new Exception(
				sprintf(
					"Schema '%s': Property '%s' enum cannot add values not in parent (added: %s)",
					$this->schemaId,
					$this->path,
					json_encode($diff)
				)
			);
		}
	}//end assertEnumNotWidened()

	/**
	 * Reject a change of a format or pattern the parent sets.
	 *
	 * @param string $constraint The constraint, a key of FIXED_VALUE_MESSAGES.
	 * @param array  $pair       The parent's value and the child's value.
	 *
	 * @throws Exception If the child changes the parent's value.
	 *
	 * @return void
	 */
	private function assertFixedValueKept(string $constraint, array $pair): void {
		[$parentValue, $childValue] = $pair;
		if ($parentValue !== null && $parentValue !== $childValue) {
			throw new Exception(
				sprintf(self::FIXED_VALUE_MESSAGES[$constraint], $this->schemaId, $this->path, $parentValue, $childValue)
			);
		}
	}//end assertFixedValueKept()

	/**
	 * Reject a numeric bound moved in the direction that relaxes it.
	 *
	 * @param string $constraint The bound, a key of BOUND_RELAXATIONS.
	 * @param array  $pair       The parent's bound and the child's bound.
	 *
	 * @throws Exception If the child relaxes the bound.
	 *
	 * @return void
	 */
	private function assertBoundNotRelaxed(string $constraint, array $pair): void {
		[$parentValue, $childValue] = $pair;
		if (is_numeric($parentValue) === false || is_numeric($childValue) === false) {
			return;
		}

		[$direction, $sign] = self::BOUND_RELAXATIONS[$constraint];
		if (($childValue <=> $parentValue) === $sign) {
			throw new Exception(
				sprintf(
					"Schema '%s': Property '%s' %s cannot be %s from %s to %s (relaxes constraint)",
					$this->schemaId,
					$this->path,
					$constraint,
					$direction,
					$parentValue,
					$childValue
				)
			);
		}
	}//end assertBoundNotRelaxed()

	/**
	 * Reject a child that empties a scalar property the parent sets.
	 *
	 * @param array $pair The parent's value and the child's value.
	 *
	 * @throws Exception If the parent has a value and the child is empty.
	 *
	 * @return void
	 */
	private function assertNotRemoved(array $pair): void {
		[$parentValue, $childValue] = $pair;
		if (empty($parentValue) === false && empty($childValue) === true) {
			throw new Exception(
				sprintf(
					"Schema '%s': Property '%s' cannot remove constraints (parent had value, child is empty)",
					$this->schemaId,
					$this->path
				)
			);
		}
	}//end assertNotRemoved()
}//end class
