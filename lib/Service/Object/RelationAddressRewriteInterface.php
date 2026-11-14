<?php

/**
 * OpenRegister RelationAddressRewriteInterface
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
 * Point the stored relation rows of an object at the address it now lives at.
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-the-old-address-keeps-answering
 */
interface RelationAddressRewriteInterface {
	/**
	 * Rewrite the register and schema every relation row stores for an object.
	 *
	 * Both ends: a row hanging off the object gets its source address
	 * rewritten, a row pointing at it its target address. Rows already naming
	 * the new address are left alone.
	 *
	 * @param string $uuid     The object's uuid, which a move does not change.
	 * @param int    $register The register it now lives in.
	 * @param int    $schema   The schema it now lives in.
	 *
	 * @return int The number of rows rewritten.
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-the-old-address-keeps-answering
	 */
	public function rewrite(string $uuid, int $register, int $schema): int;
}//end interface
