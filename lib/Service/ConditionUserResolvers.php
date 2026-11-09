<?php

/**
 * OpenRegister condition user resolvers
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Service\Rbac\MemberOfResolver;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Who the current user is, for the `$user`, `$user.groups` and `$memberOf`
 * tokens of a condition: the session, and optionally the group manager and
 * the memberOf resolver.
 *
 * @spec openspec/specs/rbac-scopes/spec.md#requirement-conditional-scopes-with-dynamic-variables
 */
final class ConditionUserResolvers {
	/**
	 * Bundle the resolvers.
	 *
	 * @param IUserSession          $userSession      User session for current user context
	 * @param IGroupManager|null    $groupManager     Group manager for resolving `$user.groups` (optional)
	 * @param MemberOfResolver|null $memberOfResolver Resolves `$memberOf` (optional; without it a
	 *                                                `$memberOf` rule admits nobody)
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly ?IGroupManager $groupManager = null,
		public readonly ?MemberOfResolver $memberOfResolver = null,
	) {
	}//end __construct()
}//end class
