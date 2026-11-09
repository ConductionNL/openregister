<?php

/**
 * OpenRegister LifecycleRuleCaller
 *
 * Who a lifecycle rule is evaluated for: the caller's user id and group ids,
 * the `user` key of the document a lifecycle rule reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Describes the caller a lifecycle rule is evaluated for.
 *
 * `uid` is empty and `groups` is empty under `occ`, which has no session.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleRuleCaller {
	/**
	 * Constructor.
	 *
	 * @param IUserSession  $userSession  Current user session.
	 * @param IGroupManager $groupManager Resolves the caller's group ids.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * The caller's user id and group ids.
	 *
	 * @return array{uid: string, groups: array<int, string>}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function describe(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return ['uid' => '', 'groups' => []];
		}

		return ['uid' => $user->getUID(), 'groups' => $this->groupManager->getUserGroupIds($user)];
	}//end describe()
}//end class
