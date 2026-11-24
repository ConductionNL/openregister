<?php
/**
 * ObjectUpdateRightsInterface: whether a user may update one object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The object-level `update` check, for an area that acts on objects it does not own.
 *
 * Core loads the object as a lookup (not a person opening it) under the current
 * identity's RBAC and asks its schema-level permission check for `update`. Processes
 * asks it before a flow step changes an object (the tag-object step).
 *
 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
 */
interface ObjectUpdateRightsInterface {

	/**
	 * Whether the user may update the object with this uuid.
	 *
	 * @param string $uuid   The object uuid.
	 * @param string $userId The user the check is for.
	 *
	 * @return bool True when the object loads and the user holds `update` on it; false when it does not load.
	 *
	 * @throws \Throwable When loading the object or its schema fails.
	 *
	 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
	 */
	public function mayUpdateObject(string $uuid, string $userId): bool;
}//end interface
