<?php

/**
 * ClassificationDeclarationValidator: refuses a classification block that cannot be honoured.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Finds what is wrong with an `x-openregister-classification` block.
 *
 * Every fault is an error, not a warning: the block decides who reads which
 * objects, and each way of getting it wrong is silent afterwards. A label
 * property the schema does not declare would leave every object unlabelled,
 * and so hidden from everyone below the top level.
 *
 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-a-schema-declares-its-own-ordered-classification-vocabulary
 */
final class ClassificationDeclarationValidator {

	/**
	 * The faults in a block, empty when it can be honoured.
	 *
	 * @param mixed               $block      The block as written.
	 * @param array<string,mixed> $properties The schema's properties.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-a-schema-declares-its-own-ordered-classification-vocabulary
	 */
	public function validate(mixed $block, array $properties): array {
		if (is_array($block) === false) {
			return ['must be an object with property and levels'];
		}

		$errors = $this->levelErrors(levels: ($block['levels'] ?? null));
		$errors = array_merge($errors, $this->propertyErrors(property: ($block['property'] ?? null), properties: $properties, key: 'property'));
		if ($errors !== []) {
			return $errors;
		}

		$levels = array_values($block['levels']);

		return array_merge(
			$this->defaultErrors(block: $block, levels: $levels),
			$this->clearanceErrors(clearances: ($block['clearances'] ?? []), levels: $levels),
			$this->ceilingErrors(ceilings: ($block['ceilings'] ?? []), levels: $levels),
			$this->floorErrors(floor: ($block['floor'] ?? null), properties: $properties)
		);
	}//end validate()

	/**
	 * At least two distinct, non-empty string levels.
	 *
	 * @param mixed $levels The `levels` entry.
	 *
	 * @return array<int, string>
	 */
	private function levelErrors(mixed $levels): array {
		if (is_array($levels) === false || count($levels) < 2) {
			return ['levels must list at least two labels'];
		}

		$errors = [];
		$seen   = [];
		foreach ($levels as $level) {
			if (is_string($level) === false || trim($level) === '') {
				$errors[] = 'levels must be non-empty strings';
				continue;
			}

			if (isset($seen[$level]) === true) {
				$errors[] = 'levels names "' . $level . '" twice';
			}

			$seen[$level] = true;
		}

		return array_values(array_unique($errors));
	}//end levelErrors()

	/**
	 * A property name the schema declares.
	 *
	 * @param mixed               $property   The property name as written.
	 * @param array<string,mixed> $properties The schema's properties.
	 * @param string              $key        Where the name was written, for the message.
	 *
	 * @return array<int, string>
	 */
	private function propertyErrors(mixed $property, array $properties, string $key): array {
		if (is_string($property) === false || $property === '') {
			return [$key . ' must name a property'];
		}

		if (array_key_exists($property, $properties) === false) {
			return [$key . ' "' . $property . '" is not a property of this schema'];
		}

		return [];
	}//end propertyErrors()

	/**
	 * A declared `default`, when one is written.
	 *
	 * @param array<string,mixed> $block  The block.
	 * @param array<int, string>  $levels The declared levels.
	 *
	 * @return array<int, string>
	 */
	private function defaultErrors(array $block, array $levels): array {
		if (array_key_exists('default', $block) === false || $block['default'] === null) {
			return [];
		}

		return $this->levelReference(value: $block['default'], levels: $levels, where: 'default');
	}//end defaultErrors()

	/**
	 * Each clearance a `{group, level}` naming a declared level.
	 *
	 * @param mixed              $clearances The `clearances` entry.
	 * @param array<int, string> $levels     The declared levels.
	 *
	 * @return array<int, string>
	 */
	private function clearanceErrors(mixed $clearances, array $levels): array {
		if (is_array($clearances) === false) {
			return ['clearances must be a list of {group, level}'];
		}

		$errors = [];
		foreach ($clearances as $index => $entry) {
			$where = 'clearances[' . $index . ']';
			if (is_array($entry) === false || is_string($entry['group'] ?? null) === false || $entry['group'] === '') {
				$errors[] = $where . ' must name a group';
				continue;
			}

			$errors = array_merge($errors, $this->levelReference(value: ($entry['level'] ?? null), levels: $levels, where: $where . '.level'));
		}

		return $errors;
	}//end clearanceErrors()

	/**
	 * Each ceiling a `{scope, atOrAbove}` naming a declared level.
	 *
	 * @param mixed              $ceilings The `ceilings` entry.
	 * @param array<int, string> $levels   The declared levels.
	 *
	 * @return array<int, string>
	 */
	private function ceilingErrors(mixed $ceilings, array $levels): array {
		if (is_array($ceilings) === false) {
			return ['ceilings must be a list of {scope, atOrAbove}'];
		}

		$errors = [];
		foreach ($ceilings as $index => $entry) {
			$where = 'ceilings[' . $index . ']';
			if (is_array($entry) === false || is_string($entry['scope'] ?? null) === false || $entry['scope'] === '') {
				$errors[] = $where . ' must name a scope';
				continue;
			}

			$errors = array_merge($errors, $this->levelReference(value: ($entry['atOrAbove'] ?? null), levels: $levels, where: $where . '.atOrAbove'));
		}

		return $errors;
	}//end ceilingErrors()

	/**
	 * A floor read from a declared property, directly or behind one declared reference.
	 *
	 * Behind a reference only the reference is checked here: the property sits
	 * on the referenced schema, which this save cannot see.
	 *
	 * @param mixed               $floor      The `floor` entry.
	 * @param array<string,mixed> $properties The schema's properties.
	 *
	 * @return array<int, string>
	 */
	private function floorErrors(mixed $floor, array $properties): array {
		if ($floor === null) {
			return [];
		}

		if (is_array($floor) === false || is_string($floor['property'] ?? null) === false || $floor['property'] === '') {
			return ['floor must name a property'];
		}

		if (array_key_exists('sourceRelation', $floor) === true) {
			return $this->propertyErrors(property: $floor['sourceRelation'], properties: $properties, key: 'floor.sourceRelation');
		}

		return $this->propertyErrors(property: $floor['property'], properties: $properties, key: 'floor.property');
	}//end floorErrors()

	/**
	 * A value naming one of the declared levels.
	 *
	 * @param mixed              $value  The value.
	 * @param array<int, string> $levels The declared levels.
	 * @param string             $where  Where it was written.
	 *
	 * @return array<int, string>
	 */
	private function levelReference(mixed $value, array $levels, string $where): array {
		if (is_string($value) === true && in_array($value, $levels, true) === true) {
			return [];
		}

		return [$where . ' must name a declared level'];
	}//end levelReference()
}//end class
