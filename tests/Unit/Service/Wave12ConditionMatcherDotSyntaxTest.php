<?php

/**
 * Wave-12 Fix 4 regression tests for ConditionMatcher dot-syntax resolution.
 *
 * Tests the new `$user.<property>` and `$organisation.<property>` resolution
 * paths added in Wave-12 to close the silent-deny gap documented at
 * `/tmp/wave11-or-engine-primitives.md` Section B4.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @covers \OCA\OpenRegister\Service\ConditionMatcher
 */
class Wave12ConditionMatcherDotSyntaxTest extends TestCase {

	private IUserSession&MockObject $userSession;

	private ContainerInterface&MockObject $container;

	private OperatorEvaluator&MockObject $operatorEvaluator;

	private LoggerInterface&MockObject $logger;

	private IGroupManager&MockObject $groupManager;

	private ConditionMatcher $matcher;

	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->operatorEvaluator = $this->createMock(OperatorEvaluator::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->matcher = new ConditionMatcher(
			$this->userSession,
			$this->container,
			$this->operatorEvaluator,
			$this->logger,
			$this->groupManager
		);
	}//end setUp()

	private function mockUser(string $uid, ?string $email = null, ?string $displayName = null): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn($email);
		$user->method('getDisplayName')->willReturn($displayName ?? $uid);
		$this->userSession->method('getUser')->willReturn($user);
		return $user;
	}//end mockUser()

	// === Supported $user.<property> dot tokens ===
	public function testUserUidDotPropertyResolves(): void {
		$this->mockUser('alice');

		$object = ['createdBy' => 'alice'];
		$match = ['createdBy' => '$user.uid'];

		$this->assertTrue($this->matcher->objectMatchesConditions($object, $match));
	}//end testUserUidDotPropertyResolves()

	public function testUserUidDotPropertyDoesNotMatchOtherUser(): void {
		$this->mockUser('alice');

		$object = ['createdBy' => 'bob'];
		$match = ['createdBy' => '$user.uid'];

		$this->assertFalse($this->matcher->objectMatchesConditions($object, $match));
	}//end testUserUidDotPropertyDoesNotMatchOtherUser()

	public function testUserEmailDotPropertyResolves(): void {
		$this->mockUser('alice', email: 'alice@example.com');

		$object = ['contactEmail' => 'alice@example.com'];
		$match = ['contactEmail' => '$user.email'];

		$this->assertTrue($this->matcher->objectMatchesConditions($object, $match));
	}//end testUserEmailDotPropertyResolves()

	public function testUserEmailDotPropertyNullEmailDeniesMatch(): void {
		// User has no email set — getEMailAddress returns null.
		$this->mockUser('alice', email: null);

		$object = ['contactEmail' => 'something@example.com'];
		$match = ['contactEmail' => '$user.email'];

		// Null resolution => deny.
		$this->assertFalse($this->matcher->objectMatchesConditions($object, $match));
	}//end testUserEmailDotPropertyNullEmailDeniesMatch()

	public function testUserDisplayNameDotPropertyResolves(): void {
		$this->mockUser('alice', displayName: 'Alice Wonderland');

		$object = ['lastEditor' => 'Alice Wonderland'];
		$match = ['lastEditor' => '$user.displayName'];

		$this->assertTrue($this->matcher->objectMatchesConditions($object, $match));
	}//end testUserDisplayNameDotPropertyResolves()

	public function testUserGroupsDotPropertyWithInOperator(): void {
		$user = $this->mockUser('alice');
		$this->groupManager->method('getUserGroupIds')->with($user)->willReturn(['users', 'editors']);

		// Mimic the realistic use-case:
		// {"role": {"$in": "$user.groups"}}
		// resolveDynamicValue recurses into the operator array, replacing
		// "$user.groups" with the resolved group-id array, and OperatorEvaluator
		// sees the actual array.
		$this->operatorEvaluator
			->expects($this->once())
			->method('valueMatchesOperator')
			->with('editors', ['$in' => ['users', 'editors']])
			->willReturn(true);

		$object = ['role' => 'editors'];
		$match = ['role' => ['$in' => '$user.groups']];

		$this->assertTrue($this->matcher->objectMatchesConditions($object, $match));
	}//end testUserGroupsDotPropertyWithInOperator()

	// === Anonymous principal ===
	public function testUserUidDotPropertyAnonymousDenies(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$object = ['createdBy' => 'alice'];
		$match = ['createdBy' => '$user.uid'];

		$this->assertFalse($this->matcher->objectMatchesConditions($object, $match));
	}//end testUserUidDotPropertyAnonymousDenies()

	// === Unknown $user.<property> tokens ===
	public function testUnknownUserDotPropertyLogsWarningAndDenies(): void {
		$this->mockUser('alice');

		// Unknown $user.<property> tokens MUST log a warning and resolve to
		// null, which the matcher then treats as a deny (closes the wave-11
		// silent-deny gap).
		$this->logger
			->expects($this->atLeastOnce())
			->method('warning')
			->with(
				$this->stringContains('Unknown $user.<property> dotted token'),
				$this->callback(fn (array $ctx): bool => ($ctx['token'] ?? '') === '$user.unknownThing')
			);

		$object = ['createdBy' => 'alice'];
		$match = ['createdBy' => '$user.unknownThing'];

		$this->assertFalse($this->matcher->objectMatchesConditions($object, $match));
	}//end testUnknownUserDotPropertyLogsWarningAndDenies()

	// === $organisation.<property> ===
	public function testOrganisationUuidDotPropertyResolves(): void {
		$this->mockUser('alice');

		// OrganisationService is consulted via the container.
		$orgService = new class {
			public function getActiveOrganisation(): object {
				return new class {
					public function getUuid(): string {
						return 'org-uuid-abc';
					}//end getUuid()
				};
			}//end getActiveOrganisation()
		};

		$this->container
			->method('get')
			->willReturnCallback(
				function (string $class) use ($orgService) {
					if ($class === 'OCA\OpenRegister\Service\OrganisationService') {
						return $orgService;
					}

					throw new \RuntimeException('Unknown class: ' . $class);
				}
			);

		$object = ['_organisation' => 'org-uuid-abc'];
		$match = ['_organisation' => '$organisation.uuid'];

		$this->assertTrue($this->matcher->objectMatchesConditions($object, $match));
	}//end testOrganisationUuidDotPropertyResolves()

	public function testUnknownOrganisationDotPropertyLogsWarningAndDenies(): void {
		$this->mockUser('alice');

		$this->logger
			->expects($this->atLeastOnce())
			->method('warning')
			->with(
				$this->stringContains('Unknown $organisation.<property> dotted token'),
				$this->callback(fn (array $ctx): bool => ($ctx['token'] ?? '') === '$organisation.bogus')
			);

		$object = ['_organisation' => 'whatever'];
		$match = ['_organisation' => '$organisation.bogus'];

		$this->assertFalse($this->matcher->objectMatchesConditions($object, $match));
	}//end testUnknownOrganisationDotPropertyLogsWarningAndDenies()

	// === BC: bare tokens still resolve unchanged ===
	public function testBareUserTokenStillResolves(): void {
		$this->mockUser('alice');

		$object = ['createdBy' => 'alice'];
		$match = ['createdBy' => '$user'];

		// Bare $user / $userId must keep working — this is the BC contract.
		$this->assertTrue($this->matcher->objectMatchesConditions($object, $match));
	}//end testBareUserTokenStillResolves()

	public function testBareUserIdTokenStillResolves(): void {
		$this->mockUser('alice');

		$object = ['createdBy' => 'alice'];
		$match = ['createdBy' => '$userId'];

		$this->assertTrue($this->matcher->objectMatchesConditions($object, $match));
	}//end testBareUserIdTokenStillResolves()
}//end class
