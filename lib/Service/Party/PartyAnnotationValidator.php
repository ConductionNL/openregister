<?php

/**
 * Shape validator for the `x-openregister-party` annotation.
 *
 * A declaration that does not parse is worse than none: the schema saves,
 * it reads as a party schema to whoever wrote it, and the addresses the
 * notification engine looks for are never there. So every field is checked
 * at save time and the error names the field.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Party
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Party;

/**
 * Validates `x-openregister-party` in a schema shape.
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
 */
class PartyAnnotationValidator {

	/**
	 * The fields naming a property of the schema.
	 *
	 * @var array<int, string>
	 */
	private const PROPERTY_FIELDS = [
		'nameProperty',
		'addressesProperty',
		'indicatorsProperty',
		'parentProperty',
	];

	/**
	 * Validate the annotation in a schema shape.
	 *
	 * @param array<string, mixed> $schema Shape with `properties` and `x-openregister-party`.
	 *
	 * @return array<int, array{code: string, message: string}> Errors; empty when valid or absent.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function validate(array $schema): array {
		$annotation = ($schema['x-openregister-party'] ?? null);
		if ($annotation === null) {
			return [];
		}

		if (is_array($annotation) === false) {
			return [
				[
					'code' => 'party.not-object',
					'message' => 'x-openregister-party must be an object.',
				],
			];
		}

		$declared = [];
		if (is_array(($schema['properties'] ?? null)) === true) {
			$declared = array_map('strval', array_keys($schema['properties']));
		}

		$errors = $this->validateKind(annotation: $annotation);
		$errors = array_merge($errors, $this->validatePropertyFields(annotation: $annotation, declared: $declared));
		$errors = array_merge($errors, $this->validateMaxDepth(annotation: $annotation));

		return $errors;
	}//end validate()

	/**
	 * The kind must be a non-empty string of at most 64 characters, the width
	 * of the link table's `party_kind` column. A kind that does not fit is a
	 * kind the link row would truncate, and a truncated kind matches nothing.
	 *
	 * @param array<string, mixed> $annotation The annotation.
	 *
	 * @return array<int, array{code: string, message: string}> Errors.
	 */
	private function validateKind(array $annotation): array {
		if (array_key_exists('kind', $annotation) === false) {
			return [];
		}

		$kind = $annotation['kind'];
		if (is_string($kind) === false || trim($kind) === '' || strlen(trim($kind)) > 64) {
			return [
				[
					'code' => 'party.invalid-kind',
					'message' => 'x-openregister-party "kind" must be a non-empty string of at most 64 characters.',
				],
			];
		}

		return [];
	}//end validateKind()

	/**
	 * Every property field must be a string, and must name a property the
	 * schema declares. A field pointing at a property that is not there is
	 * the phantom-annotation case: it reads fine and finds nothing.
	 *
	 * @param array<string, mixed> $annotation The annotation.
	 * @param array<int, string> $declared The schema's declared property names.
	 *
	 * @return array<int, array{code: string, message: string}> Errors.
	 */
	private function validatePropertyFields(array $annotation, array $declared): array {
		$errors = [];
		foreach (self::PROPERTY_FIELDS as $field) {
			if (array_key_exists($field, $annotation) === false) {
				continue;
			}

			$value = $annotation[$field];
			if (is_string($value) === false || trim($value) === '') {
				$errors[] = [
					'code' => 'party.field-not-string',
					'message' => sprintf('x-openregister-party "%s" must be a non-empty string.', $field),
				];
				continue;
			}

			// Skipped when the schema declares no properties at all: an
			// entity built in isolation has nothing to check against, and
			// failing there would reject a valid declaration for the wrong
			// reason.
			if ($declared === [] || in_array(trim($value), $declared, true) === true) {
				continue;
			}

			$errors[] = [
				'code' => 'party.unknown-property',
				'message' => sprintf(
					'x-openregister-party "%s" names "%s", which is not a declared property of this schema.',
					$field,
					trim($value)
				),
			];
		}//end foreach

		return $errors;
	}//end validatePropertyFields()

	/**
	 * The depth bound must be a positive integer.
	 *
	 * @param array<string, mixed> $annotation The annotation.
	 *
	 * @return array<int, array{code: string, message: string}> Errors.
	 */
	private function validateMaxDepth(array $annotation): array {
		if (array_key_exists('maxDepth', $annotation) === false) {
			return [];
		}

		$depth = $annotation['maxDepth'];
		if (is_int($depth) === false || $depth <= 0) {
			return [
				[
					'code' => 'party.invalid-max-depth',
					'message' => 'x-openregister-party "maxDepth" must be a positive integer.',
				],
			];
		}

		return [];
	}//end validateMaxDepth()
}//end class
