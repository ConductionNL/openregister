<?php

/**
 * OpenRegister OwnershipCascade.
 *
 * One step of a handover carried down to a record's children: the parent that
 * just changed hands and the transfer run the children join.
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
 *
 * @spec openspec/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * A handover cascading from one parent record to its children.
 *
 * @spec openspec/specs/object-ownership/spec.md
 */
final class OwnershipCascade {

	/**
	 * Constructor.
	 *
	 * @param OwnedRecord       $parent   The record that just changed hands.
	 * @param OwnershipHandover $handover The transfer run its children join.
	 */
	public function __construct(
		public readonly OwnedRecord $parent,
		public readonly OwnershipHandover $handover,
	) {
	}//end __construct()

	/**
	 * The generation a child of the parent sits at.
	 *
	 * @return int One below the parent.
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function childDepth(): int {
		return ($this->parent->depth + 1);
	}//end childDepth()
}//end class
