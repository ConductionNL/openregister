<?php

/**
 * ConfidentialityAnnotationValidator: finds what is wrong with a confidentiality block.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Confidentiality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Confidentiality;

/**
 * Finds the faults in an `x-openregister-confidentiality` block.
 *
 * A block with faults is not ignored: ConfidentialityRule treats it as fail
 * closed, so every object carrying a tier is hidden until the block is fixed.
 *
 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md#requirement-schemas-must-be-able-to-declare-a-confidentiality-classification-via-x-openregister-confidentiality
 */
final class ConfidentialityAnnotationValidator {

	/**
	 * The schema configuration key holding the block.
	 */
	public const ANNOTATION = 'x-openregister-confidentiality';

	/**
	 * The faults in a block, empty when it can be honoured.
	 *
	 * @param mixed                $block      The block as written.
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md#requirement-schemas-must-be-able-to-declare-a-confidentiality-classification-via-x-openregister-confidentiality
	 */
	public function validate(mixed $block, array $properties): array {
		if (is_array($block) === false) {
			return ['must be an object with tiers, tierProperty and clearance'];
		}

		$errors = array_merge(
			$this->tierErrors(tiers: ($block['tiers'] ?? null)),
			$this->propertyErrors(property: ($block['tierProperty'] ?? null), properties: $properties, key: 'tierProperty', required: true)
		);
		if ($errors !== []) {
			return $errors;
		}

		return array_merge(
			$this->clearanceErrors(block: $block),
			$this->propertyErrors(property: ($block['groundProperty'] ?? null), properties: $properties, key: 'groundProperty', required: false),
			$this->releaseErrors(property: ($block['releaseAtProperty'] ?? null), properties: $properties)
		);
	}//end validate()

	/**
	 * A non-empty list of distinct, non-empty string tiers.
	 *
	 * @param mixed $tiers The `tiers` entry.
	 *
	 * @return array<int, string>
	 */
	private function tierErrors(mixed $tiers): array {
		if (is_array($tiers) === false || $tiers === [] || array_is_list($tiers) === false) {
			return ['tiers must list at least one tier, least restrictive first'];
		}

		$errors = [];
		$seen   = [];
		foreach ($tiers as $tier) {
			if (is_string($tier) === false || trim($tier) === '') {
				$errors[] = 'tiers must be non-empty strings';
				continue;
			}

			if (isset($seen[$tier]) === true) {
				$errors[] = 'tiers names "' . $tier . '" twice';
			}

			$seen[$tier] = true;
		}

		return array_values(array_unique($errors));
	}//end tierErrors()

	/**
	 * A property name the schema declares.
	 *
	 * @param mixed                $property   The property name as written.
	 * @param array<string, mixed> $properties The schema's properties.
	 * @param string               $key        Where the name was written, for the message.
	 * @param bool                 $required   Whether the entry must be present.
	 *
	 * @return array<int, string>
	 */
	private function propertyErrors(mixed $property, array $properties, string $key, bool $required): array {
		if ($property === null && $required === false) {
			return [];
		}

		if (is_string($property) === false || $property === '') {
			return [$key . ' must name a property'];
		}

		if (array_key_exists($property, $properties) === false) {
			return [$key . ' "' . $property . '" is not a property of this schema'];
		}

		return [];
	}//end propertyErrors()

	/**
	 * A clearance map whose keys are tiers and whose values are group names.
	 *
	 * @param array<string, mixed> $block The block, its tiers already valid.
	 *
	 * @return array<int, string>
	 */
	private function clearanceErrors(array $block): array {
		$tiers     = $block['tiers'];
		$clearance = ($block['clearance'] ?? null);
		if ($clearance === null || $clearance === []) {
			if (count($tiers) === 1) {
				return [];
			}

			return ['clearance must name the group required for at least one tier'];
		}

		if (is_array($clearance) === false || array_is_list($clearance) === true) {
			return ['clearance must map tiers to group names'];
		}

		$errors = [];
		foreach ($clearance as $tier => $group) {
			if (in_array((string)$tier, $tiers, true) === false) {
				$errors[] = 'clearance names tier "' . $tier . '", which is not one of tiers';
				continue;
			}

			if (is_string($group) === false || trim($group) === '') {
				$errors[] = 'clearance for tier "' . $tier . '" must be a group name';
			}
		}

		return $errors;
	}//end clearanceErrors()

	/**
	 * A release property the schema declares with format date-time.
	 *
	 * @param mixed                $property   The property name as written.
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return array<int, string>
	 */
	private function releaseErrors(mixed $property, array $properties): array {
		$errors = $this->propertyErrors(property: $property, properties: $properties, key: 'releaseAtProperty', required: false);
		if ($errors !== [] || $property === null) {
			return $errors;
		}

		$definition = $properties[$property];
		if (is_array($definition) === false || ($definition['format'] ?? null) !== 'date-time') {
			return ['releaseAtProperty "' . $property . '" must be a property of format date-time'];
		}

		return [];
	}//end releaseErrors()
}//end class
