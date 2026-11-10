<?php

/**
 * OpenRegister DefinitionWriteRights
 *
 * Who may write a schema or register definition: an administrator, or for an
 * existing definition a member of a group its `manage` rule names.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/mcp-discovery/spec.md#requirement-a-definition-tool-holds-the-caller-to-the-rights-of-the-rest-routes
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Answers the admin and manage questions for the signed-in user.
 *
 * @spec openspec/specs/mcp-discovery/spec.md#requirement-a-definition-tool-holds-the-caller-to-the-rights-of-the-rest-routes
 */
class DefinitionWriteRights implements DefinitionWriteRightsInterface {

	/**
	 * Constructor.
	 *
	 * @param IUserSession  $userSession  The session of the caller.
	 * @param IGroupManager $groupManager Resolves admin status and group membership.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Whether the signed-in user is an administrator.
	 *
	 * @return bool False without a signed-in user.
	 *
	 * @spec openspec/specs/mcp-discovery/spec.md#requirement-a-definition-tool-holds-the-caller-to-the-rights-of-the-rest-routes
	 */
	public function isAdmin(): bool {
		$user = $this->userSession->getUser();

		return $user !== null && $this->groupManager->isAdmin($user->getUID()) === true;
	}//end isAdmin()

	/**
	 * Whether the signed-in user may manage a definition with this authorization.
	 *
	 * Without a `manage` rule only an administrator may. An entry is a group id
	 * or an array with a `group`.
	 *
	 * @param array|null $authorization The authorization block of the definition.
	 *
	 * @return bool True for an administrator or a member of a named group.
	 *
	 * @spec openspec/specs/mcp-discovery/spec.md#requirement-a-definition-tool-holds-the-caller-to-the-rights-of-the-rest-routes
	 */
	public function mayManage(?array $authorization): bool {
		if ($this->isAdmin() === true) {
			return true;
		}

		$user  = $this->userSession->getUser();
		$rules = ($authorization['manage'] ?? null);
		if ($user === null || is_array($rules) === false) {
			return false;
		}

		$named = [];
		foreach ($rules as $entry) {
			if (is_array($entry) === true) {
				$entry = ($entry['group'] ?? null);
			}

			$named[] = $entry;
		}

		return array_intersect($this->groupManager->getUserGroupIds($user), array_filter($named, 'is_string')) !== [];
	}//end mayManage()
}//end class
