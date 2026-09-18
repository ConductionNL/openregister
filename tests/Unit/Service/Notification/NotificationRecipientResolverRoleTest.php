<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\NotificationRecipientResolver;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the `role` recipient kind and the report of what did not resolve.
 *
 * A group that no longer exists resolves to nobody, and so does a group that is
 * simply empty. Those look identical in a uid list, and only one of them is a
 * fault; the diagnostics are what separate them.
 */
class NotificationRecipientResolverRoleTest extends TestCase {
	private IUserManager&MockObject $userManager;
	private IGroupManager&MockObject $groupManager;
	private LoggerInterface&MockObject $logger;
	private NotificationRecipientResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->resolver = new NotificationRecipientResolver(
			$this->userManager,
			$this->groupManager,
			$this->logger
		);
	}

	/**
	 * Build a group whose members are these uids.
	 *
	 * @param array<int, string> $uids The members.
	 *
	 * @return IGroup&MockObject The group.
	 */
	private function groupWith(array $uids): IGroup&MockObject {
		$users = [];
		foreach ($uids as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);

		return $group;
	}

	/**
	 * A role resolves through the schema's assignment to that role's groups.
	 */
	public function testARoleResolvesToTheGroupsItIsAssigned(): void {
		$this->groupManager->method('get')->willReturnCallback(
			fn (string $gid): ?IGroup => ($gid === 'behandelaars' ? $this->groupWith(['anna', 'bram']) : null)
		);

		$uids = $this->resolver->resolve(
			recipientsSpec: [['kind' => 'role', 'role' => 'behandelaar']],
			data: [],
			object: null,
			context: [],
			roleGroups: ['behandelaar' => ['behandelaars']]
		);

		$this->assertSame(['anna', 'bram'], $uids);
	}

	/**
	 * Members are read at dispatch, so somebody added to the group after the
	 * rule was written is reached by it.
	 */
	public function testANewColleagueIsReached(): void {
		$members = ['anna'];
		$this->groupManager->method('get')->willReturnCallback(
			function (string $gid) use (&$members): ?IGroup {
				return ($gid === 'behandelaars' ? $this->groupWith($members) : null);
			}
		);

		$before = $this->resolver->resolve(
			recipientsSpec: [['kind' => 'role', 'role' => 'behandelaar']],
			data: [],
			object: null,
			context: [],
			roleGroups: ['behandelaar' => ['behandelaars']]
		);
		$members[] = 'chris';
		$after = $this->resolver->resolve(
			recipientsSpec: [['kind' => 'role', 'role' => 'behandelaar']],
			data: [],
			object: null,
			context: [],
			roleGroups: ['behandelaar' => ['behandelaars']]
		);

		$this->assertSame(['anna'], $before);
		$this->assertSame(['anna', 'chris'], $after);
	}

	/**
	 * A role the schema does not assign is reported, not silently skipped.
	 */
	public function testAnUnassignedRoleIsReported(): void {
		$resolved = $this->resolver->resolveWithDiagnostics(
			recipientsSpec: [['kind' => 'role', 'role' => 'toezichthouder']],
			data: [],
			object: null,
			context: [],
			roleGroups: ['behandelaar' => ['behandelaars']]
		);

		$this->assertSame([], $resolved['uids']);
		$this->assertCount(1, $resolved['unresolved']);
		$this->assertSame('role', $resolved['unresolved'][0]['kind']);
		$this->assertSame('toezichthouder', $resolved['unresolved'][0]['id']);
		$this->assertSame('role-not-assigned', $resolved['unresolved'][0]['reason']);
	}

	/**
	 * A group that no longer exists is reported, naming the group.
	 */
	public function testAVanishedGroupIsReported(): void {
		$this->groupManager->method('get')->willReturn(null);

		$resolved = $this->resolver->resolveWithDiagnostics(
			recipientsSpec: [['kind' => 'groups', 'groups' => ['opgeheven-team']]],
			data: []
		);

		$this->assertSame([], $resolved['uids']);
		$this->assertCount(1, $resolved['unresolved']);
		$this->assertSame('opgeheven-team', $resolved['unresolved'][0]['id']);
		$this->assertSame('group-not-found', $resolved['unresolved'][0]['reason']);
	}

	/**
	 * A real group with no members is NOT a fault. Without this the report
	 * would flag every quiet team, which is how a fault list stops being read.
	 */
	public function testAnEmptyGroupIsNotAFault(): void {
		$this->groupManager->method('get')->willReturn($this->groupWith([]));

		$resolved = $this->resolver->resolveWithDiagnostics(
			recipientsSpec: [['kind' => 'groups', 'groups' => ['stil-team']]],
			data: []
		);

		$this->assertSame([], $resolved['uids']);
		$this->assertSame([], $resolved['unresolved']);
	}

	/**
	 * A group lookup that throws is reported as a fault of its own, distinct
	 * from a group that is definitively absent.
	 */
	public function testAFailingGroupLookupIsReportedSeparately(): void {
		$this->groupManager->method('get')->willThrowException(new \RuntimeException('LDAP is down'));

		$resolved = $this->resolver->resolveWithDiagnostics(
			recipientsSpec: [['kind' => 'groups', 'groups' => ['behandelaars']]],
			data: []
		);

		$this->assertSame('group-lookup-failed', $resolved['unresolved'][0]['reason']);
	}

	/**
	 * The plain resolve() still answers only uids, so every existing caller is
	 * unaffected by the diagnostics.
	 */
	public function testResolveStillAnswersOnlyUids(): void {
		$this->groupManager->method('get')->willReturn($this->groupWith(['anna']));

		$uids = $this->resolver->resolve(
			recipientsSpec: [['kind' => 'groups', 'groups' => ['behandelaars']]],
			data: []
		);

		$this->assertSame(['anna'], $uids);
	}
}
