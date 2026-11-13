<?php

/**
 * ClassificationLimits: the scope ceilings and the write floor of a classification block.
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
 * What a classification block refuses outright, rather than narrows.
 *
 * A ceiling names a scope, not a feature: `{scope: public, atOrAbove:
 * vertrouwelijk}` keeps such an object from a share link, an export and an
 * anonymous read alike, whatever a grant says. The floor is the least
 * restrictive level a write may set; raising is always allowed.
 *
 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-a-ceiling-refuses-a-scope-outright
 */
final class ClassificationLimits {

	/**
	 * Constructor.
	 *
	 * @param ClassificationScale                                   $scale    The scale the levels are placed on.
	 * @param array<int, array{scope: string, atOrAbove: string}>   $ceilings Scopes refused at or above a level.
	 * @param array{property: string, sourceRelation?: string}|null $floor    Where the least restrictive allowed level is read.
	 */
	public function __construct(
		private readonly ClassificationScale $scale,
		private readonly array $ceilings=[],
		private readonly ?array $floor=null,
	) {
	}//end __construct()

	/**
	 * Read the limits of a validated block.
	 *
	 * @param ClassificationScale  $scale The block's scale.
	 * @param array<string, mixed> $block The `x-openregister-classification` block.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-a-ceiling-refuses-a-scope-outright
	 */
	public static function fromBlock(ClassificationScale $scale, array $block): self {
		$floor = null;
		if (is_array($block['floor'] ?? null) === true) {
			$floor = $block['floor'];
		}

		return new self(
			scale: $scale,
			ceilings: array_values(array_filter((array)($block['ceilings'] ?? []), 'is_array')),
			floor: $floor
		);
	}//end fromBlock()

	/**
	 * The ceiling that refuses a scope an object carrying a label, if any.
	 *
	 * @param string $scope The principal scope (`public`, ...).
	 * @param mixed  $label The object's label.
	 *
	 * @return array{scope: string, atOrAbove: string}|null
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-a-ceiling-refuses-a-scope-outright
	 */
	public function ceilingRefusing(string $scope, mixed $label): ?array {
		$rank = $this->scale->rankOf(label: $label);
		foreach ($this->ceilings as $ceiling) {
			if (($ceiling['scope'] ?? null) === $scope && $rank >= $this->scale->rankOf(label: ($ceiling['atOrAbove'] ?? null))) {
				return $ceiling;
			}
		}

		return null;
	}//end ceilingRefusing()

	/**
	 * Where the floor is read: the property, and the reference it sits behind if any.
	 *
	 * @return array{property: string, sourceRelation?: string}|null
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-a-write-may-raise-a-classification-and-not-lower-it-below-its-floor
	 */
	public function floor(): ?array {
		return $this->floor;
	}//end floor()

	/**
	 * Why a requested label is below the floor, or null when it is not.
	 *
	 * An unknown floor places nothing and refuses nothing; an unknown request
	 * ranks most restrictive and so is never below a floor.
	 *
	 * @param mixed $requested The label the write sets.
	 * @param mixed $floor     The least restrictive label allowed.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-a-write-may-raise-a-classification-and-not-lower-it-below-its-floor
	 */
	public function belowFloor(mixed $requested, mixed $floor): ?string {
		if ($this->scale->isKnown(label: $floor) === false
			|| $this->scale->rankOf(label: $requested) >= $this->scale->rankOf(label: $floor)
		) {
			return null;
		}

		return 'Classification "' . (string)$requested . '" is below the floor "' . (string)$floor . '" for this object';
	}//end belowFloor()
}//end class
