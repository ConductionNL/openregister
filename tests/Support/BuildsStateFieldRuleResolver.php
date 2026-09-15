<?php

/**
 * Build a real StateFieldRuleResolver for a unit test.
 *
 * WHY THIS EXISTS: `PropertyRbacHandler` now asks the resolver what the
 * object's lifecycle state hides, and the resolver's own collaborators are
 * `final` classes PHPUnit cannot double. A mocked resolver would return null
 * from a method typed `: StateFieldRules` and fail with a TypeError, and a
 * resolver stubbed to answer "nothing hidden" would be a test that cannot
 * fail: it would pass against a handler that ignored state rules entirely.
 *
 * So the suites build the real thing. Its whole dependency chain is two value
 * services over the session, which costs nothing, and a schema with no
 * `x-openregister-lifecycle.states` block resolves to the empty rule set —
 * exactly the behaviour the write-only and key-order suites are asserting
 * around.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Support
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

namespace OCA\OpenRegister\Tests\Support;

use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * One builder for the resolver every PropertyRbacHandler test now needs.
 */
trait BuildsStateFieldRuleResolver {

	/**
	 * A real resolver over the given session and group manager.
	 *
	 * @param IUserSession $userSession The session naming the user.
	 * @param IGroupManager $groupManager The group memberships.
	 *
	 * @return StateFieldRuleResolver The resolver.
	 */
	protected static function stateFieldRuleResolver(
		IUserSession $userSession,
		IGroupManager $groupManager,
	): StateFieldRuleResolver {
		return new StateFieldRuleResolver(
			$userSession,
			$groupManager,
			new ConditionDialect(new CalculationEvaluator(new PlaceholderResolver($userSession)))
		);
	}//end stateFieldRuleResolver()
}//end trait
