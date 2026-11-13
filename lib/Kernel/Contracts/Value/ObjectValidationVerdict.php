<?php

/**
 * ObjectValidationVerdict: whether a sample record would be accepted, and on which properties not.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

use JsonSerializable;

/**
 * The answer of a validate-only call: valid exactly when no rule refuses the
 * sample, with one entry per refused property naming the rule.
 *
 * Rules: `schema` (the schema validator), `unique`, `field-type` (an extended
 * field type), `write-rule` (declared bounds, repeating groups, geometry,
 * read-only and lens rules, which name no single property),
 * `coded-value`, `concept-shape`, `dependent-value`. A property of null
 * means the refusal names no single property.
 *
 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
 */
final class ObjectValidationVerdict implements JsonSerializable {

	/**
	 * Constructor.
	 *
	 * @param array<int, array{property: string|null, message: string, rule: string}> $errors The refusals.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
	 */
	public function __construct(
		public readonly array $errors=[],
	) {
	}//end __construct()

	/**
	 * Whether the sample would be accepted.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
	 */
	public function isValid(): bool {
		return $this->errors === [];
	}//end isValid()

	/**
	 * The verdict as the API answers it.
	 *
	 * @return array{valid: bool, errors: array<int, array{property: string|null, message: string, rule: string}>}
	 *
	 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
	 */
	public function jsonSerialize(): array {
		return ['valid' => $this->isValid(), 'errors' => $this->errors];
	}//end jsonSerialize()
}//end class
