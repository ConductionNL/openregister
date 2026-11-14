<?php

/**
 * ClassificationScale: a schema's ordered classification vocabulary and the comparisons over it.
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
 * The `x-openregister-classification` block of one schema, read.
 *
 * The order is the schema's: index 0 is the least restrictive level and the
 * last index the most restrictive. OpenRegister holds no built-in vocabulary,
 * so a ZGW confidentiality scale and a three-step internal scale are the same
 * mechanism configured differently.
 *
 * The ceilings and the floor are read by ClassificationLimits.
 *
 * Built from a block that ClassificationDeclarationValidator accepted. A label
 * this scale cannot place ranks at the most restrictive index: an unlabelled
 * object is maximally protected, never maximally exposed.
 *
 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md
 */
final class ClassificationScale {

	/**
	 * The schema configuration key holding the block.
	 */
	public const ANNOTATION = 'x-openregister-classification';

	/**
	 * Constructor.
	 *
	 * @param string                                          $property   The object property carrying the label.
	 * @param array<int, string>                              $levels     The labels, least restrictive first.
	 * @param array<int, array{group: string, level: string}> $clearances Group to level mappings.
	 * @param string|null                                     $default    The level of a principal no mapping names.
	 */
	public function __construct(
		private readonly string $property,
		private readonly array $levels,
		private readonly array $clearances=[],
		private readonly ?string $default=null,
	) {
	}//end __construct()

	/**
	 * Read a validated block.
	 *
	 * @param array<string, mixed> $block The `x-openregister-classification` block.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-a-schema-declares-its-own-ordered-classification-vocabulary
	 */
	public static function fromBlock(array $block): self {
		$default = null;
		if (is_string($block['default'] ?? null) === true) {
			$default = $block['default'];
		}

		return new self(
			property: (string)($block['property'] ?? ''),
			levels: array_values(array_map('strval', (array)($block['levels'] ?? []))),
			clearances: array_values(array_filter((array)($block['clearances'] ?? []), 'is_array')),
			default: $default
		);
	}//end fromBlock()

	/**
	 * The object property that carries the label.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-a-schema-declares-its-own-ordered-classification-vocabulary
	 */
	public function property(): string {
		return $this->property;
	}//end property()

	/**
	 * The labels, least restrictive first.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-a-schema-declares-its-own-ordered-classification-vocabulary
	 */
	public function levels(): array {
		return $this->levels;
	}//end levels()

	/**
	 * The group to level mappings.
	 *
	 * @return array<int, array{group: string, level: string}>
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-a-principals-clearance-comes-from-declared-group-mappings
	 */
	public function clearances(): array {
		return $this->clearances;
	}//end clearances()

	/**
	 * The declared default clearance, else the lowest level.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-a-principals-clearance-comes-from-declared-group-mappings
	 */
	public function defaultClearance(): string {
		if ($this->default !== null && $this->isKnown(label: $this->default) === true) {
			return $this->default;
		}

		return $this->levels[0];
	}//end defaultClearance()

	/**
	 * The most restrictive level.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-a-principals-clearance-comes-from-declared-group-mappings
	 */
	public function highest(): string {
		return $this->levels[count($this->levels) - 1];
	}//end highest()

	/**
	 * Whether a label is one of the declared levels.
	 *
	 * @param mixed $label The label as stored on an object.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-an-unknown-label-is-most-restrictive-and-an-unresolvable-clearance-is-the-default
	 */
	public function isKnown(mixed $label): bool {
		return is_string($label) === true && in_array($label, $this->levels, true) === true;
	}//end isKnown()

	/**
	 * The index of a label; an absent, empty or unknown label ranks most restrictive.
	 *
	 * @param mixed $label The label as stored on an object.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-an-unknown-label-is-most-restrictive-and-an-unresolvable-clearance-is-the-default
	 */
	public function rankOf(mixed $label): int {
		if ($this->isKnown(label: $label) === false) {
			return count($this->levels) - 1;
		}

		return (int)array_search($label, $this->levels, true);
	}//end rankOf()

	/**
	 * Whether a caller holding a clearance may read an object carrying a label.
	 *
	 * @param string $clearance The caller's resolved clearance.
	 * @param mixed  $label     The object's label.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-read-above-a-callers-clearance-is-refused-on-both-layers
	 */
	public function admits(string $clearance, mixed $label): bool {
		return $this->rankOf(label: $label) <= $this->clearanceRank(clearance: $clearance);
	}//end admits()

	/**
	 * The labels a clearance admits, as the list query's `IN` predicate needs them.
	 *
	 * Only declared labels: an unknown label ranks most restrictive, so it is
	 * admitted only to a caller cleared to the top, and that caller needs no
	 * predicate at all.
	 *
	 * @param string $clearance The caller's resolved clearance.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-read-above-a-callers-clearance-is-refused-on-both-layers
	 */
	public function admittedLabels(string $clearance): array {
		return array_slice($this->levels, 0, $this->clearanceRank(clearance: $clearance) + 1);
	}//end admittedLabels()

	/**
	 * The index of a clearance; one that cannot be placed is the default.
	 *
	 * @param string $clearance The caller's clearance.
	 *
	 * @return int
	 */
	private function clearanceRank(string $clearance): int {
		if ($this->isKnown(label: $clearance) === false) {
			$clearance = $this->defaultClearance();
		}

		return (int)array_search($clearance, $this->levels, true);
	}//end clearanceRank()
}//end class
