<?php

/**
 * ConditionUser
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The `user` key of a condition document: the session user's uid and group ids.
 *
 * Shared by the transition condition evaluator and the state field rule
 * resolver, which both evaluate conditions against the current user.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
final class ConditionUser {

	/**
	 * The session user's uid and group ids; an empty uid and no groups without a user.
	 *
	 * @param IUserSession  $userSession  The session.
	 * @param IGroupManager $groupManager Resolves the user's group ids.
	 *
	 * @return array{uid: string, groups: array<int, string>} The `user` key.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public static function of(IUserSession $userSession, IGroupManager $groupManager): array {
		$user = $userSession->getUser();
		$uid = '';
		$groups = [];
		if ($user !== null) {
			$uid = $user->getUID();
			$groups = $groupManager->getUserGroupIds($user);
		}

		return ['uid' => $uid, 'groups' => $groups];
	}//end of()
}//end class
