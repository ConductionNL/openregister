<?php

/**
 * TransitionAuthorizationInterface: Decides whether a caller may drive a lifecycle transition.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;

/**
 * The per-transition authorization gate of PermissionHandler.
 *
 * Callers that need only this role depend on it, not on the whole handler.
 */
interface TransitionAuthorizationInterface {

	/**
	 * Decide whether a caller may perform a lifecycle transition that
	 * declares a per-transition `authorization` list.
	 *
	 * This is the declarative counterpart to the `requires` PHP-guard seam on
	 * `x-openregister-lifecycle` transitions: a schema author lists the
	 * Nextcloud group ids (and/or register-named roles) permitted to drive a
	 * given transition, and OpenRegister enforces it on the standard
	 * `saveObject()` path WITHOUT the author having to ship a guard class.
	 *
	 * Resolution rules (reusing the SAME `IGroupManager` membership check that
	 * every other RBAC verdict in this handler trusts):
	 *  - The `admin` group always passes (mirrors {@see hasGroupPermission()}).
	 *  - A bare string entry is a literal NC group id; the caller passes when
	 *    {@see IGroupManager::isInGroup()} is true for it.
	 *  - An entry shaped `{ "role": "<name>" }` is expanded to the NC group ids
	 *    assigned to that register-named role via the schema's
	 *    `authorization.roles` map (the same `roles: {name: [groups]}` syntax
	 *    {@see expandRoles()} consumes), then matched the same way.
	 *
	 * Fail-closed posture (CWE-863): an EMPTY list authorises nobody; an
	 * anonymous caller (null/unknown uid) is denied; an entry that cannot be
	 * resolved to a concrete group is skipped (never widens access). A
	 * transition WITHOUT an `authorization` list never reaches this method —
	 * the listener only calls it when the key is present — so existing
	 * transitions are completely unaffected (additive).
	 *
	 * @param array<int, mixed> $authorizationList Group ids / `{role}` entries permitted to perform the transition.
	 * @param string|null $userId Caller uid, or null for an anonymous principal.
	 * @param Schema $schema Schema the transition belongs to (for named-role expansion).
	 *
	 * @return bool True when the caller is authorised; false (fail-closed) otherwise.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-declarative-per-transition-authorization-gate
	 */
	public function isTransitionAuthorized(array $authorizationList, ?string $userId, Schema $schema): bool;
}//end interface
