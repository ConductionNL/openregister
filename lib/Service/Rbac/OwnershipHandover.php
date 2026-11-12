<?php

/**
 * OpenRegister OwnershipHandover.
 *
 * The state of one ownership transfer as it walks a record and its children:
 * the new owner, whether the change cascades, and the records already moved.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One transfer run of `ObjectOwnershipService`.
 *
 * @spec openspec/specs/object-ownership/spec.md
 */
final class OwnershipHandover {
	/**
	 * Records already moved by this transfer, by uuid.
	 *
	 * @var array<string, true>
	 */
	private array $visited = [];

	/**
	 * Constructor.
	 *
	 * @param string $newOwner The uid of the new owner.
	 * @param bool $cascade Whether the change carries to the records' children.
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function __construct(
		public readonly string $newOwner,
		public readonly bool $cascade,
	) {

	}//end __construct()

	/**
	 * Mark a record as moved by this transfer.
	 *
	 * @param string $uuid The record's uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function visit(string $uuid): void {
		$this->visited[$uuid] = true;

	}//end visit()

	/**
	 * Whether a child record moves with its parent.
	 *
	 * A child moves when it has a uuid, has not been moved by this transfer
	 * yet, and shares the owner its parent had.
	 *
	 * @param ObjectEntity $child The candidate child.
	 * @param string|null $previousOwner The parent's owner before the transfer.
	 *
	 * @return bool True when the child moves.
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function carries(ObjectEntity $child, ?string $previousOwner): bool {
		$childUuid = $child->getUuid();
		if ($childUuid === null || isset($this->visited[$childUuid]) === true) {
			return false;
		}

		return $child->getOwner() === $previousOwner;

	}//end carries()
}//end class
