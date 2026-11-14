<?php

/**
 * A declared change gate (`gate.change`) of an approval chain, normalised.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-an-approval-chain-can-hold-a-change-until-it-is-approved
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

/**
 * The gated properties, operations and exempt groups of a chain entry that
 * holds a change instead of a transition, declared as
 * `gate: {change: {properties: [...], on: ["update", "create"], exempt: [groups]}}`.
 * An empty or absent property list gates any change; an absent `on` gates
 * updates only. A declaration that cannot be read is malformed rather than
 * silently narrowed, so the chain that carries it fails closed.
 *
 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-an-approval-chain-can-hold-a-change-until-it-is-approved
 */
final class ChangeGateDeclaration {

	/**
	 * The operation that creates a record.
	 *
	 * @var string
	 */
	public const ON_CREATE = 'create';

	/**
	 * The operation that changes a stored record.
	 *
	 * @var string
	 */
	public const ON_UPDATE = 'update';

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $properties   The gated properties; empty means any.
	 * @param array<int, string> $operations   The gated operations.
	 * @param array<int, string> $exemptGroups The groups whose writes are not held.
	 */
	private function __construct(
		public readonly array $properties,
		public readonly array $operations,
		public readonly array $exemptGroups,
	) {

	}//end __construct()

	/**
	 * Whether a chain entry declares a change gate at all.
	 *
	 * @param array<string, mixed> $chain The declared chain entry.
	 *
	 * @return bool True when `gate.change` is present, well-formed or not.
	 *
	 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-an-approval-chain-can-hold-a-change-until-it-is-approved
	 */
	public static function declaredIn(array $chain): bool {
		$gate = ($chain['gate'] ?? null);

		return is_array($gate) === true && array_key_exists('change', $gate) === true;

	}//end declaredIn()

	/**
	 * Read the change gate of a chain entry.
	 *
	 * @param array<string, mixed> $chain The declared chain entry.
	 *
	 * @return self|null The gate, or null when none is declared or it is malformed.
	 *
	 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-an-approval-chain-can-hold-a-change-until-it-is-approved
	 */
	public static function fromChain(array $chain): ?self {
		if (self::declaredIn(chain: $chain) === false) {
			return null;
		}

		$change = $chain['gate']['change'];
		if (is_array($change) === false) {
			return null;
		}

		$properties = self::names(value: ($change['properties'] ?? []));
		$operations = self::names(value: ($change['on'] ?? [self::ON_UPDATE]));
		$exempt     = self::names(value: ($change['exempt'] ?? []));
		if ($properties === null || $operations === null || $exempt === null) {
			return null;
		}

		$known = [self::ON_CREATE, self::ON_UPDATE];
		if ($operations === [] || array_diff($operations, $known) !== []) {
			return null;
		}

		return new self(
			properties: $properties,
			operations: array_values(array_intersect($known, $operations)),
			exemptGroups: $exempt
		);

	}//end fromChain()

	/**
	 * Whether the gate holds the given operation.
	 *
	 * @param string $operation `create` or `update`.
	 *
	 * @return bool True when that operation is gated.
	 *
	 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-a-gated-new-record-does-not-exist-until-it-is-approved
	 */
	public function gates(string $operation): bool {
		return in_array($operation, $this->operations, true);

	}//end gates()

	/**
	 * Whether a change to the given property is held.
	 *
	 * @param string $property The property name.
	 *
	 * @return bool True when the property is listed, or when no property is listed.
	 *
	 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-an-approval-chain-can-hold-a-change-until-it-is-approved
	 */
	public function coversProperty(string $property): bool {
		return $this->properties === [] || in_array($property, $this->properties, true);

	}//end coversProperty()

	/**
	 * Whether a caller in the given groups writes without approval.
	 *
	 * @param array<int, string> $groups The caller's group ids.
	 *
	 * @return bool True when one of them is declared exempt.
	 *
	 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-an-approval-chain-can-hold-a-change-until-it-is-approved
	 */
	public function isExempt(array $groups): bool {
		return array_intersect($this->exemptGroups, $groups) !== [];

	}//end isExempt()

	/**
	 * The gate as it is compiled onto a chain's template.
	 *
	 * @return array{properties: array<int, string>, on: array<int, string>, exempt: array<int, string>}
	 *
	 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-an-approval-chain-can-hold-a-change-until-it-is-approved
	 */
	public function toArray(): array {
		return [
			'properties' => $this->properties,
			'on' => $this->operations,
			'exempt' => $this->exemptGroups,
		];

	}//end toArray()

	/**
	 * A list of distinct non-empty trimmed names.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return array<int, string>|null The names, or null when the value is not a list of non-empty strings.
	 */
	private static function names(mixed $value): ?array {
		if (is_array($value) === false || array_is_list($value) === false) {
			return null;
		}

		$names = [];
		foreach ($value as $entry) {
			if (is_string($entry) === false || trim($entry) === '') {
				return null;
			}

			$names[] = trim($entry);
		}

		return array_values(array_unique($names));

	}//end names()
}//end class
