<?php

/**
 * OpenRegister MovedObjectLookupInterface
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-the-old-address-keeps-answering
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Where an object asked for at an address it has left now lives.
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-the-old-address-keeps-answering
 */
interface MovedObjectLookupInterface {
	/**
	 * The address an object moved to from the given address, or null.
	 *
	 * Null unless the object is readable to the caller somewhere else AND its
	 * audit trail holds a `moved` entry from exactly this register and schema
	 * within the last year. A wrong address that was never the object's home
	 * stays a miss.
	 *
	 * @param string     $identifier The id the caller asked for.
	 * @param int|string $register   The register id of the address asked for.
	 * @param int|string $schema     The schema id of the address asked for.
	 * @param bool       $scoped     Whether the caller's RBAC and organisation apply.
	 *
	 * @return array{register: string, schema: string, uuid: string, movedAt: string}|null The new address.
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-the-old-address-keeps-answering
	 */
	public function movedFrom(string $identifier, int|string $register, int|string $schema, bool $scoped): ?array;
}//end interface
