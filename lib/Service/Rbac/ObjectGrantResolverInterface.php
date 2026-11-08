<?php

/**
 * ObjectGrantResolverInterface: what callers outside the component use of ObjectGrantResolver.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Declares only what callers outside the component use of ObjectGrantResolver; the rest
 * of the class stays its own.
 */
interface ObjectGrantResolverInterface {

	/**
	 * Whether the caller holds any grant at all.
	 *
	 * The list emitters use this to decide whether the grant branch is worth
	 * emitting, and `MagicSearchHandler` uses it to force the organisation
	 * filter on — a grant must never widen the tenant edge (design D3c).
	 *
	 * @param string|null $userId The caller.
	 *
	 * @return bool True when at least one object is granted to this caller.
	 */
	public function hasAnyGrant(?string $userId): bool;

	/**
	 * The object UUIDs granted to a caller FOR ONE ACTION.
	 *
	 * A grant carries a permission, and until this existed every grant admitted
	 * every action — a read-only invitation silently allowed delete. The list
	 * emitters use this so the grant branch only ever names rows the caller may
	 * reach for the action being filtered.
	 *
	 * @param string|null $userId The caller.
	 * @param string $action The action being decided.
	 *
	 * @return array<string, int> Object UUID => permission bitmask.
	 */
	public function grantedObjectUuidsFor(?string $userId, string $action): array;

	/**
	 * Whether one object is granted to the caller for one action.
	 *
	 * @param string|null $userId The caller.
	 * @param string|null $objectUuid The object's UUID.
	 * @param string $action The action being decided.
	 *
	 * @return bool True when a grant exists that carries the action's permission.
	 */
	public function isGranted(?string $userId, ?string $objectUuid, string $action = 'read'): bool;
}//end interface
