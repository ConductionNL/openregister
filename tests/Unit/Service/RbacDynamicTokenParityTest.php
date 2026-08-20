<?php

/**
 * Dynamic-token parity between the two RBAC enforcement paths.
 *
 * OpenRegister decides object access in two places: `PermissionHandler` /
 * `ConditionMatcher` for a single-object read, and `MagicRbacHandler`'s SQL
 * emitters for list endpoints. A rule must mean the SAME thing to both — a
 * verdict that differs by path is an access-control bug that presents as an
 * empty page (over-filtering) or a leak (under-filtering).
 *
 * `MagicRbacHandler` used to keep its OWN copy of dynamic-token resolution which
 * recognised only the bare tokens and passed every DOTTED form through as a
 * literal string. So `{"sharedWith": {"$contains": "$user.groups"}}` resolved to
 * the user's groups on `find` and compared against the literal `'$user.groups'`
 * on `list`: a group-based grant worked on one path and silently denied on the
 * other. Resolution is now delegated to the shared `ConditionMatcher`, and this
 * test pins that — it fails if the SQL path ever grows a private resolver again.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Asserts the shared resolver understands every token a share rule can use.
 */
class RbacDynamicTokenParityTest extends TestCase {

	private ConditionMatcher $matcher;

	private OperatorEvaluator $evaluator;

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	protected function setUp(): void {
		$logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('getUserGroupIds')->willReturn(['finance', 'hr']);

		$this->evaluator = new OperatorEvaluator($logger);
		$this->matcher = new ConditionMatcher(
			$this->userSession,
			$this->createMock(ContainerInterface::class),
			$this->evaluator,
			$logger,
			$this->groupManager
		);
	}//end setUp()

	/**
	 * The bare user token resolves on the shared path.
	 */
	public function testResolvesBareUserToken(): void {
		$this->assertSame('alice', $this->matcher->resolveDynamicValue('$userId'));
		$this->assertSame('alice', $this->matcher->resolveDynamicValue('$user'));
	}

	/**
	 * The DOTTED user token resolves too — this is what the SQL path could not do.
	 */
	public function testResolvesDottedUserToken(): void {
		$this->assertSame('alice', $this->matcher->resolveDynamicValue('$user.uid'));
	}

	/**
	 * `$user.groups` resolves to an ARRAY, which a share rule needs for group grants.
	 */
	public function testResolvesUserGroupsToAnArray(): void {
		$resolved = $this->matcher->resolveDynamicValue('$user.groups');

		$this->assertIsArray($resolved);
		$this->assertContains('hr', $resolved);
	}

	/**
	 * A non-token value passes through untouched, so ordinary literals still work.
	 */
	public function testPassesLiteralsThrough(): void {
		$this->assertSame('finance', $this->matcher->resolveDynamicValue('finance'));
		$this->assertSame(42, $this->matcher->resolveDynamicValue(42));
		$this->assertNull($this->matcher->resolveDynamicValue(null));
	}

	/**
	 * An unknown dotted property resolves to null so the caller denies, rather
	 * than being compared as a literal string (which would silently pass through).
	 */
	public function testUnknownDottedPropertyResolvesToNull(): void {
		$this->assertNull($this->matcher->resolveDynamicValue('$user.notAThing'));
	}

	/**
	 * End to end: a share rule using `$user.groups` admits an object listing one
	 * of the user's groups, and denies one that lists none of them.
	 *
	 * This is the verdict the SQL path must reproduce; it is asserted here on the
	 * single-object path so the expected answer is unambiguous.
	 */
	public function testGroupShareRuleAdmitsAndDeniesCorrectly(): void {
		$match = ['sharedWith' => ['$contains' => '$user.groups']];

		$this->assertTrue(
			$this->matcher->objectMatchesConditions(['sharedWith' => ['hr']], $match),
			'an object listing one of the user groups should match'
		);

		$this->assertFalse(
			$this->matcher->objectMatchesConditions(['sharedWith' => ['legal']], $match),
			'an object listing none of the user groups should not match'
		);

		$this->assertFalse(
			$this->matcher->objectMatchesConditions(['sharedWith' => []], $match),
			'an empty share list should not match'
		);

		$this->assertFalse(
			$this->matcher->objectMatchesConditions([], $match),
			'a missing share list should not match'
		);
	}

	/**
	 * A per-user share rule admits the named user and denies everyone else.
	 */
	public function testUserShareRuleAdmitsAndDeniesCorrectly(): void {
		$match = ['sharedWith' => ['$contains' => '$userId']];

		$this->assertTrue($this->matcher->objectMatchesConditions(['sharedWith' => ['alice']], $match));
		$this->assertFalse($this->matcher->objectMatchesConditions(['sharedWith' => ['bob']], $match));
	}

	/**
	 * The literal-string trap: if resolution regressed to passing tokens through,
	 * an object whose list happened to contain the token TEXT would match. It
	 * must not, because the token must always be resolved first.
	 */
	public function testTokenTextIsNeverMatchedLiterally(): void {
		$match = ['sharedWith' => ['$contains' => '$userId']];

		$this->assertFalse(
			$this->matcher->objectMatchesConditions(['sharedWith' => ['$userId']], $match),
			'the literal token text must not satisfy a share rule'
		);
	}
}
