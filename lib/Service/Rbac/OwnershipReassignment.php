<?php

/**
 * OpenRegister OwnershipReassignment.
 *
 * An administrator's reassignment of records to one named owner: who receives
 * them, and whether the change carries to each record's children.
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
 * One bulk reassignment: the new owner and the cascade flag every record shares.
 *
 * @spec openspec/specs/object-ownership/spec.md
 */
final class OwnershipReassignment {

	/**
	 * Constructor.
	 *
	 * @param string $newOwner The uid of the new owner.
	 * @param bool   $cascade  Whether the change carries to each record's children.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The cascade is a field of the reassignment, not a second behaviour.
	 */
	public function __construct(
		public readonly string $newOwner,
		public readonly bool $cascade,
	) {
	}//end __construct()

	/**
	 * A fresh transfer run for one record of the reassignment.
	 *
	 * Fresh per record, so the records a previous record's cascade visited do
	 * not stop this record's own cascade.
	 *
	 * @return OwnershipHandover The run.
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function handover(): OwnershipHandover {
		return new OwnershipHandover(newOwner: $this->newOwner, cascade: $this->cascade);
	}//end handover()
}//end class
