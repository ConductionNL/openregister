<?php

/**
 * OpenRegister Handoff Kind Binding
 *
 * One entry of a schema's `handoffContract` block: the kind URI, the declared
 * `{contractField: ownProperty}` map, and the schema's own properties it must
 * bind into. Split out of {@see HandoffContractBindingValidator} so the
 * save-time error report and the engine's completeness check read the same
 * map the same way.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Handoff;

/**
 * A single kind's field map inside a `handoffContract` block.
 */
final class HandoffKindBinding {

	/**
	 * Constructor.
	 *
	 * @param string               $kindUri    The kind URI being bound.
	 * @param mixed                $fieldMap   The declared `{contractField: ownProperty}` map, as found.
	 * @param array<string, mixed> $properties The schema's own properties map.
	 */
	public function __construct(
		public readonly string $kindUri,
		private readonly mixed $fieldMap,
		private readonly array $properties,
	) {

	}//end __construct()

	/**
	 * Whether the field map is a non-empty map.
	 *
	 * @return bool True when there is something to check.
	 */
	public function hasFields(): bool {
		return is_array($this->fieldMap) === true && $this->fieldMap !== [];
	}//end hasFields()

	/**
	 * The contract field names the map declares, in declaration order.
	 *
	 * @return array<int, string> The declared contract fields.
	 */
	public function contractFields(): array {
		if ($this->hasFields() === false) {
			return [];
		}

		return array_map('strval', array_keys($this->fieldMap));
	}//end contractFields()

	/**
	 * The own property a contract field is bound to, as declared.
	 *
	 * @param string $contractField The contract field name.
	 *
	 * @return mixed The declared target, or null when the field is not bound.
	 */
	public function target(string $contractField): mixed {
		if ($this->hasFields() === false) {
			return null;
		}

		return ($this->fieldMap[$contractField] ?? null);
	}//end target()

	/**
	 * Whether a declared target names an existing own property.
	 *
	 * @param mixed $ownProperty The declared target.
	 *
	 * @return bool True when it is a string key of the schema's properties.
	 */
	public function namesOwnProperty(mixed $ownProperty): bool {
		return is_string($ownProperty) === true && array_key_exists($ownProperty, $this->properties) === true;
	}//end namesOwnProperty()

	/**
	 * Whether every given contract field is bound to an existing own property.
	 *
	 * @param array<int, string> $contractFields The fields that must be bound.
	 *
	 * @return bool True when all of them are.
	 */
	public function bindsAllOf(array $contractFields): bool {
		foreach ($contractFields as $contractField) {
			if ($this->namesOwnProperty(ownProperty: $this->target(contractField: $contractField)) === false) {
				return false;
			}
		}

		return true;
	}//end bindsAllOf()

	/**
	 * Whether every declared field is one of `$allowed` and binds an existing own property.
	 *
	 * @param array<int, string> $allowed The kind's contract fields.
	 *
	 * @return bool True when nothing foreign or dangling is bound.
	 */
	public function bindsOnlyWithin(array $allowed): bool {
		foreach ($this->contractFields() as $contractField) {
			if (in_array($contractField, $allowed, true) === false) {
				return false;
			}
		}

		return $this->bindsAllOf(contractFields: $this->contractFields());
	}//end bindsOnlyWithin()

	/**
	 * The given contract fields the map does not declare at all.
	 *
	 * @param array<int, string> $mandatory The fields that must be present.
	 *
	 * @return array<int, string> The absent ones, in the given order.
	 */
	public function missingOf(array $mandatory): array {
		$declared = $this->contractFields();

		return array_values(
			array_filter(
				$mandatory,
				static fn (string $field): bool => in_array($field, $declared, true) === false
			)
		);
	}//end missingOf()
}//end class
