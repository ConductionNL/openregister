<?php

/**
 * Where one definition node is placed in a case plan's tree.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

/**
 * A normalised node, the stage it sits under and its order among its siblings.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
 */
final readonly class CasePlanPlacement {
	/**
	 * Place a node.
	 *
	 * @param array<string, mixed> $node     The normalised node.
	 * @param int|null             $parentId The containing stage's row id, or null for the root.
	 * @param int                  $position Order among siblings.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function __construct(
		public array $node,
		public ?int $parentId,
		public int $position,
	) {
	}//end __construct()
}//end class
