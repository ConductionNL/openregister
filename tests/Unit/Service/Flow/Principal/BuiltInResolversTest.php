<?php

/**
 * The two principal kinds every Nextcloud has.
 *
 * 🔴 EXISTENCE IS THE POINT OF BOTH. A reference to a deleted account or a
 * removed group must resolve to NOTHING, not to its own id: returning the id
 * would authorise an identity nobody can log in as, and would make "resolved to
 * nobody" — the signal that fails a step loudly — unreachable for the commonest
 * way a performer goes away.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Principal
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

namespace Unit\Service\Flow\Principal;

use OCA\OpenRegister\Listener\PrincipalResolverRegistrationListener;
use OCA\OpenRegister\Service\Flow\Principal\AgentPrincipalResolver;
use OCA\OpenRegister\Service\Flow\Principal\GroupPrincipalResolver;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCA\OpenRegister\Service\Flow\Principal\RegisterPrincipalResolversEvent;
use OCA\OpenRegister\Service\Flow\Principal\UserPrincipalResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the built-in resolvers and their registration.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Principal\UserPrincipalResolver
 * @covers \OCA\OpenRegister\Service\Flow\Principal\GroupPrincipalResolver
 * @covers \OCA\OpenRegister\Service\Flow\Principal\AgentPrincipalResolver
 * @covers \OCA\OpenRegister\Listener\PrincipalResolverRegistrationListener
 * @covers \OCA\OpenRegister\Service\Flow\Principal\RegisterPrincipalResolversEvent
 * @uses \OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry
 * @uses \OCA\OpenRegister\Service\Flow\Principal\PrincipalReference
 */
final class BuiltInResolversTest extends TestCase {

	/**
	 * A user resolver over an instance holding the given uids.
	 *
	 * @param array<int, string> $existing The uids that exist.
	 *
	 * @return UserPrincipalResolver The resolver.
	 */
	private function userResolver(array $existing): UserPrincipalResolver {
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(
			static fn (string $uid): bool => in_array($uid, $existing, true)
		);

		return new UserPrincipalResolver($users);
	}//end userResolver()

	/**
	 * A group resolver over an instance holding the given memberships.
	 *
	 * @param array<string, array<int, string>> $groups Group id to member uids.
	 *
	 * @return GroupPrincipalResolver The resolver.
	 */
	private function groupResolver(array $groups): GroupPrincipalResolver {
		$manager = $this->createMock(IGroupManager::class);
		$manager->method('get')->willReturnCallback(
			function (string $gid) use ($groups): ?IGroup {
				if (array_key_exists($gid, $groups) === false) {
					return null;
				}

				$members = [];
				foreach ($groups[$gid] as $uid) {
					$user = $this->createMock(IUser::class);
					$user->method('getUID')->willReturn($uid);
					$members[] = $user;
				}

				$group = $this->createMock(IGroup::class);
				$group->method('getUsers')->willReturn($members);

				return $group;
			}
		);

		return new GroupPrincipalResolver($manager);
	}//end groupResolver()

	/**
	 * An agent resolver over an instance holding the given identities.
	 *
	 * @param array<int, string> $existing The agent identities that exist.
	 *
	 * @return AgentPrincipalResolver The resolver.
	 */
	private function agentResolver(array $existing): AgentPrincipalResolver {
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(
			static fn (string $uid): bool => in_array($uid, $existing, true)
		);

		return new AgentPrincipalResolver($users);
	}//end agentResolver()

	/**
	 * 🔴 AN AGENT RESOLVES TO EXACTLY ONE IDENTITY, NEVER THROUGH A GROUP.
	 *
	 * The whole safety property. If an agent reference expanded through group
	 * membership, an agent's step would become answerable by every human in
	 * that group — and a human's step by the agent. The two must not be able to
	 * answer for each other.
	 *
	 * Asserted by giving the resolver NO group manager at all: it cannot
	 * consult groups because it was never handed anything that could.
	 *
	 * @return void
	 */
	public function testAnAgentResolvesToOneIdentityAndNeverThroughAGroup(): void {
		$resolver = $this->agentResolver(['scribe']);

		$this->assertSame('agent', $resolver->type());
		$this->assertSame(['scribe'], $resolver->resolve(id: 'scribe'));

		// A group every human is in resolves to nothing here, because this
		// resolver has no notion of groups whatsoever.
		$this->assertSame([], $resolver->resolve(id: 'bezwaar'));
	}//end testAnAgentResolvesToOneIdentityAndNeverThroughAGroup()

	/**
	 * An agent with no identity resolves to nobody.
	 *
	 * An agent completes its turn as a REAL identity or the step fails loudly,
	 * rather than raising a task addressed to something that cannot log in.
	 *
	 * @return void
	 */
	public function testAnAgentWithNoIdentityResolvesToNobody(): void {
		$resolver = $this->agentResolver(['scribe']);

		$this->assertSame([], $resolver->resolve(id: 'no-such-agent'));
		$this->assertSame([], $resolver->resolve(id: '  '));
	}//end testAnAgentWithNoIdentityResolvesToNobody()

	/**
	 * A user that exists resolves to itself.
	 *
	 * @return void
	 */
	public function testAUserThatExistsResolvesToItself(): void {
		$resolver = $this->userResolver(['alice']);

		$this->assertSame('user', $resolver->type());
		$this->assertSame(['alice'], $resolver->resolve(id: 'alice'));
	}//end testAUserThatExistsResolvesToItself()

	/**
	 * 🔴 A DELETED ACCOUNT RESOLVES TO NOBODY, NOT TO ITS OWN UID.
	 *
	 * Returning the uid would authorise an identity nobody can log in as, and
	 * would hide the commonest way a performer goes away behind a resolution
	 * that looks successful.
	 *
	 * @return void
	 */
	public function testAUserThatDoesNotExistResolvesToNobody(): void {
		$resolver = $this->userResolver(['alice']);

		$this->assertSame([], $resolver->resolve(id: 'ghost'));
		$this->assertSame([], $resolver->resolve(id: '   '));
	}//end testAUserThatDoesNotExistResolvesToNobody()

	/**
	 * A group resolves to its current members.
	 *
	 * @return void
	 */
	public function testAGroupResolvesToItsMembers(): void {
		$resolver = $this->groupResolver(['bezwaar' => ['alice', 'bob']]);

		$this->assertSame('group', $resolver->type());
		$this->assertSame(['alice', 'bob'], $resolver->resolve(id: 'bezwaar'));
	}//end testAGroupResolvesToItsMembers()

	/**
	 * A group that does not exist and an empty one are the same fact.
	 *
	 * From the caller's side both mean there is nobody to ask, and the caller
	 * reports them the same way.
	 *
	 * @return void
	 */
	public function testAMissingGroupAndAnEmptyGroupBothResolveToNobody(): void {
		$resolver = $this->groupResolver(['empty' => []]);

		$this->assertSame([], $resolver->resolve(id: 'empty'));
		$this->assertSame([], $resolver->resolve(id: 'never-existed'));
		$this->assertSame([], $resolver->resolve(id: ''));
	}//end testAMissingGroupAndAnEmptyGroupBothResolveToNobody()

	/**
	 * 🔑 THE BUILT-INS GO THROUGH THE SAME EVENT EVERY APP USES.
	 *
	 * Registering them any other way would leave the contribution path
	 * exercised only by consumers, where it can rot unnoticed.
	 *
	 * @return void
	 */
	public function testTheBuiltInsRegisterThroughTheContributionEvent(): void {
		$listener = new PrincipalResolverRegistrationListener(
			$this->userResolver(['alice']),
			$this->groupResolver(['bezwaar' => ['alice']]),
			$this->agentResolver(['scribe'])
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($listener): void {
				$listener->handle($event);
			}
		);

		$registry = new PrincipalResolverRegistry($dispatcher, $this->createMock(LoggerInterface::class));

		$this->assertSame(['agent', 'group', 'user'], $registry->types());
	}//end testTheBuiltInsRegisterThroughTheContributionEvent()

	/**
	 * The listener ignores an event that is not its own.
	 *
	 * @return void
	 */
	public function testTheListenerIgnoresAnUnrelatedEvent(): void {
		$this->expectNotToPerformAssertions();

		$listener = new PrincipalResolverRegistrationListener(
			$this->userResolver([]),
			$this->groupResolver([]),
			$this->agentResolver([])
		);

		// Would fatal if it tried to register on something that is not the
		// contribution event.
		$listener->handle(new Event());
	}//end testTheListenerIgnoresAnUnrelatedEvent()

	/**
	 * The event hands the resolver straight to the registry.
	 *
	 * @return void
	 */
	public function testTheEventRegistersOnTheRegistry(): void {
		$registry = new PrincipalResolverRegistry(
			$this->createMock(IEventDispatcher::class),
			$this->createMock(LoggerInterface::class)
		);

		(new RegisterPrincipalResolversEvent($registry))->registerResolver($this->userResolver([]));

		$this->assertTrue($registry->has(type: 'user'));
	}//end testTheEventRegistersOnTheRegistry()
}//end class
