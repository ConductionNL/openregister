<?php

/**
 * Who is asking for a list of views, and how far they reach.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The caller a view list is answered for: their uid, their groups, and whether
 * they administer the instance.
 *
 * WHY THE THREE TRAVEL AS ONE. `ViewsController` reads all three from the same
 * place in one go, and then handed them to `ViewService::findAllFor()`, which
 * handed them to `ViewMapper::findAllFor()`. The last of the three was
 * `bool $isAdmin = false`, and a boolean flag on an authorization path is the
 * argument most easily dropped in the middle of a chain: the call still
 * compiles, the list still comes back, and it is quietly the narrow one. A
 * caller that cannot be built without saying so cannot be half-built.
 *
 * It also replaces the untyped `['groups' => ..., 'isAdmin' => ...]` array the
 * controller passed around, where a misspelt key read as "no groups" rather
 * than as an error.
 *
 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
 */
class ViewerReach {

	/**
	 * Constructor.
	 *
	 * None of the three has a default. The reach of a caller is not something
	 * a call site may leave to this class to guess.
	 *
	 * @param string             $userId  The caller's uid.
	 * @param array<int, string> $groups  The group ids the caller is a member of.
	 * @param boolean            $isAdmin Whether the caller administers the instance.
	 */
	public function __construct(
		public readonly string $userId,
		public readonly array $groups,
		public readonly bool $isAdmin,
	) {
	}//end __construct()

}//end class
