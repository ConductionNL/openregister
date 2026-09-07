<?php

/**
 * A group is whoever is in it, right now.
 *
 * 🔴 READ EVERY TIME, NEVER FROZEN. This is the resolver the whole design was
 * shaped around: a task assigned to the bezwaarcommissie in March is answerable
 * by whoever sits on it in June. Somebody who joins the group after the task
 * was raised can answer it; somebody who leaves can no longer answer it, even
 * though they are named in the task's own record of who was asked.
 *
 * That record is evidence, consulted by no guard. Confusing the two is how a
 * "who was asked" audit field quietly becomes an authorisation bypass.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Principal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Principal;

use OCP\IGroupManager;

/**
 * Resolves `{type: 'group'}` references.
 */
class GroupPrincipalResolver implements IPrincipalResolver {

	/**
	 * The type this resolver answers for.
	 *
	 * @var string
	 */
	public const TYPE = 'group';

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groups Nextcloud's group manager.
	 */
	public function __construct(private readonly IGroupManager $groups) {

	}//end __construct()

	/**
	 * The type.
	 *
	 * @return string `group`.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function type(): string {
		return self::TYPE;

	}//end type()

	/**
	 * The group's current members.
	 *
	 * A group that does not exist and a group with no members both resolve to
	 * nothing, deliberately: from the caller's side they are the same fact —
	 * there is nobody to ask — and the caller reports it the same way.
	 *
	 * @param string $id The group id.
	 *
	 * @return array<int, string> The members' uids.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function resolve(string $id): array {
		$gid = trim($id);
		if ($gid === '') {
			return [];
		}

		$group = $this->groups->get($gid);
		if ($group === null) {
			return [];
		}

		$uids = [];
		foreach ($group->getUsers() as $user) {
			$uids[] = $user->getUID();
		}

		return $uids;

	}//end resolve()
}//end class
