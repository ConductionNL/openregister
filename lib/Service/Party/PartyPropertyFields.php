<?php

/**
 * The property fields of one `x-openregister-party` annotation, checked
 * against the properties its schema declares.
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
 * @spec openspec/specs/party-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Party;

/**
 * Checks every property field of an annotation against one schema's declared
 * property names. Built per validation by PartyAnnotationValidator, which
 * owns the schema those names come from.
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
 */
final class PartyPropertyFields {

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
	 * Constructor.
	 *
	 * @param array<int, string> $declared The schema's declared property names.
	 */
	public function __construct(
		private readonly array $declared,
	) {
	}//end __construct()

	/**
	 * Every property field must be a string, and must name a property the
	 * schema declares. A field pointing at a property that is not there is
	 * the phantom-annotation case: it reads fine and finds nothing.
	 *
	 * @param array<string, mixed> $annotation The annotation.
	 *
	 * @return array<int, array{code: string, message: string}> Errors.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function errors(array $annotation): array {
		$errors = [];
		foreach (self::PROPERTY_FIELDS as $field) {
			if (array_key_exists($field, $annotation) === false) {
				continue;
			}

			$error = $this->fieldError(field: $field, value: $annotation[$field]);
			if ($error !== null) {
				$errors[] = $error;
			}
		}

		return $errors;
	}//end errors()

	/**
	 * The error for one property field: not a non-empty string, or naming a
	 * property the schema does not declare; null when it is fine.
	 *
	 * @param string $field The field.
	 * @param mixed $value Its value.
	 *
	 * @return array{code: string, message: string}|null The error.
	 */
	private function fieldError(string $field, mixed $value): ?array {
		if (is_string($value) === false || trim($value) === '') {
			return [
				'code' => 'party.field-not-string',
				'message' => sprintf('x-openregister-party "%s" must be a non-empty string.', $field),
			];
		}

		return $this->unknownPropertyError(field: $field, name: trim($value));
	}//end fieldError()

	/**
	 * The error for a property field naming a property the schema does not
	 * declare; null when it names a declared one.
	 *
	 * Skipped when the schema declares no properties at all: an entity built
	 * in isolation has nothing to check against, and failing there would
	 * reject a valid declaration for the wrong reason.
	 *
	 * @param string $field The field.
	 * @param string $name The property it names, trimmed.
	 *
	 * @return array{code: string, message: string}|null The error.
	 */
	private function unknownPropertyError(string $field, string $name): ?array {
		if ($this->declared === [] || in_array($name, $this->declared, true) === true) {
			return null;
		}

		return [
			'code' => 'party.unknown-property',
			'message' => sprintf(
				'x-openregister-party "%s" names "%s", which is not a declared property of this schema.',
				$field,
				$name
			),
		];
	}//end unknownPropertyError()
}//end class
