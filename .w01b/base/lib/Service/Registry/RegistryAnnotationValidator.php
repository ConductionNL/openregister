<?php

/**
 * OpenRegister RegistryAnnotationValidator
 *
 * Schema-save validation for the `x-openregister-registry` annotation.
 * Returns a list of errors; empty = valid.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Registry
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Registry;

/**
 * Validates the `x-openregister-registry` schema annotation shape.
 *
 * The annotation names an external registry (`brp`, `kvk`, or another id),
 * the property that carries the identity value the registry looks the
 * object up by (`bsn`, `kvkNumber`), and the properties the registry is
 * allowed to write on an inbound update:
 *
 *   { registry: string, identity: string, owned: string[] }
 *
 * `identity` and every entry of `owned` MUST be declared in the schema's
 * own `properties`. Schema save refuses the annotation otherwise (per
 * `registry-subscriptions` REQ 1) — this validator's errors are treated as
 * BLOCKING by the caller (`SchemaMapper::validateRegistryAnnotation()`),
 * unlike most other `x-openregister-*` validators, which only warn.
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md
 */
final class RegistryAnnotationValidator {

	/**
	 * Validate the registry annotation.
	 *
	 * @param array<string, mixed> $schema Full schema definition, including
	 *                                     `properties` and
	 *                                     `x-openregister-registry`.
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-a-schema-declares-which-registry-owns-which-properties
	 */
	public function validate(array $schema): array {
		if (isset($schema['x-openregister-registry']) === false) {
			return [];
		}

		$annotation = $schema['x-openregister-registry'];
		if (is_array($annotation) === false) {
			return [
				[
					'code' => 'registry-malformed',
					'message' => 'x-openregister-registry must be an object.',
				],
			];
		}

		$properties = $schema['properties'] ?? [];
		if (is_array($properties) === false) {
			$properties = [];
		}

		return array_merge(
			$this->validateRegistryId(annotation: $annotation),
			$this->validateIdentity(annotation: $annotation, properties: $properties),
			$this->validateOwned(annotation: $annotation, properties: $properties)
		);
	}//end validate()

	/**
	 * Validate `x-openregister-registry.registry`.
	 *
	 * @param array<string, mixed> $annotation The annotation block.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateRegistryId(array $annotation): array {
		if ((string)($annotation['registry'] ?? '') !== '') {
			return [];
		}

		return [
			[
				'code' => 'registry-id-missing',
				'message' => 'x-openregister-registry.registry must be a non-empty string.',
			],
		];
	}//end validateRegistryId()

	/**
	 * Validate `x-openregister-registry.identity` is non-empty and declared
	 * in the schema's own properties.
	 *
	 * @param array<string, mixed> $annotation The annotation block.
	 * @param array<string, mixed> $properties The schema's own properties.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateIdentity(array $annotation, array $properties): array {
		$identity = (string)($annotation['identity'] ?? '');
		if ($identity === '') {
			return [
				[
					'code' => 'registry-identity-missing',
					'message' => 'x-openregister-registry.identity must be a non-empty string.',
				],
			];
		}

		if (array_key_exists($identity, $properties) === true) {
			return [];
		}

		return [
			[
				'code' => 'registry-identity-undeclared',
				'message' => sprintf(
					'x-openregister-registry.identity "%s" is not declared in this schema\'s properties.',
					$identity
				),
			],
		];
	}//end validateIdentity()

	/**
	 * Validate `x-openregister-registry.owned`: a non-empty array of
	 * non-empty strings, each declared in the schema's own properties.
	 *
	 * @param array<string, mixed> $annotation The annotation block.
	 * @param array<string, mixed> $properties The schema's own properties.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateOwned(array $annotation, array $properties): array {
		$owned = $annotation['owned'] ?? null;
		if (is_array($owned) === false || count($owned) === 0) {
			return [
				[
					'code' => 'registry-owned-missing',
					'message' => 'x-openregister-registry.owned must be a non-empty array of property names.',
				],
			];
		}

		$errors = [];
		foreach ($owned as $index => $property) {
			if (is_string($property) === false || $property === '') {
				$errors[] = [
					'code' => 'registry-owned-malformed',
					'message' => sprintf('x-openregister-registry.owned[%s] must be a non-empty string.', (string)$index),
				];
				continue;
			}

			if (array_key_exists($property, $properties) === false) {
				$errors[] = [
					'code' => 'registry-owned-undeclared',
					'message' => sprintf(
						'x-openregister-registry.owned property "%s" is not declared in this schema\'s properties.',
						$property
					),
				];
			}
		}

		return $errors;
	}//end validateOwned()
}//end class
