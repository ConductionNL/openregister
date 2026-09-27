<?php

/**
 * Tests for the store install action authorizer.
 *
 * 🔴 EVERY TEST HERE IS ABOUT REFUSING RATHER THAN NO-OPPING.
 *
 * This is a duck-typed lookup by convention, and the fleet has been bitten
 * repeatedly by exactly that shape: `isInstalled('docudesk')`,
 * `class_exists('OCA\DocuDesk\…')` — a runtime lookup pointed at a name
 * nothing answers to becomes a SILENT NO-OP rather than an error. A no-op here
 * is an install that skipped its authorization check and reported success, so
 * every way of failing to resolve is asserted to refuse.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
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

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCA\OpenRegister\AppHost\Store\StoreActionAuthorizer;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A leaf ActionAuthService that answers.
 */
class FakeActionAuthService {
	/**
	 * @param bool $answer What can() returns.
	 */
	public function __construct(private readonly bool $answer) {
	}

	/**
	 * @param IUser  $user   The user.
	 * @param string $action The action.
	 *
	 * @return bool
	 */
	public function can(IUser $user, string $action): bool {
		return $this->answer;
	}
}

/**
 * A leaf service whose matrix throws.
 */
class ThrowingActionAuthService {
	/**
	 * @param IUser  $user   The user.
	 * @param string $action The action.
	 *
	 * @return bool
	 */
	public function can(IUser $user, string $action): bool {
		throw new RuntimeException('matrix unavailable');
	}
}

/**
 * A leaf service that exists but has no can().
 */
class ShapelessActionAuthService {
}

/**
 * @covers \OCA\OpenRegister\AppHost\Store\StoreActionAuthorizer
 *
 * The publish check reads the descriptor's group list, so the canPublish
 * cases execute StoreDescriptor. `beStrictAboutCoverageMetadata` marks that
 * risky unless declared, and the coverage guard then drops those tests'
 * coverage; `@uses`, as GenericStoreControllerTest does for the same reason.
 *
 * @uses \OCA\OpenRegister\AppHost\Service\StoreDescriptor
 */
class StoreActionAuthorizerTest extends TestCase {
	/**
	 * Build an authorizer whose container yields the given service.
	 *
	 * @param mixed $service What the container returns, or a Throwable to throw.
	 *
	 * @return StoreActionAuthorizer
	 */
	private function authorizer(mixed $service): StoreActionAuthorizer {
		$container = $this->createMock(ContainerInterface::class);
		if ($service instanceof \Throwable) {
			$container->method('get')->willThrowException($service);
		} else {
			$container->method('get')->willReturn($service);
		}

		return new StoreActionAuthorizer(
			$container,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IGroupManager::class)
		);
	}

	/**
	 * The leaf matrix says yes.
	 *
	 * @return void
	 */
	public function testItPermitsWhenTheLeafMatrixSaysYes(): void {
		$authorizer = $this->authorizer(new FakeActionAuthService(true));

		$this->assertTrue(
			$authorizer->can('integriq', 'catalog.instantiate', $this->createMock(IUser::class))
		);
	}

	/**
	 * The leaf matrix says no.
	 *
	 * @return void
	 */
	public function testItRefusesWhenTheLeafMatrixSaysNo(): void {
		$authorizer = $this->authorizer(new FakeActionAuthService(false));

		$this->assertFalse(
			$authorizer->can('integriq', 'catalog.instantiate', $this->createMock(IUser::class))
		);
	}

	/**
	 * 🔴 An absent service refuses. It must never read as "no objection".
	 *
	 * @return void
	 */
	public function testAnUnresolvableServiceRefuses(): void {
		$authorizer = $this->authorizer(new RuntimeException('not found'));

		$this->assertFalse(
			$authorizer->can('integriq', 'catalog.instantiate', $this->createMock(IUser::class)),
			'An unresolvable authorizer must refuse, never silently permit.'
		);
	}

	/**
	 * 🔴 A service without can() refuses rather than being assumed permissive.
	 *
	 * This is the shape a RENAME produces: the class still resolves, the
	 * method is gone, and a lookup that only checked existence would sail past.
	 *
	 * @return void
	 */
	public function testAServiceWithoutCanRefuses(): void {
		$authorizer = $this->authorizer(new ShapelessActionAuthService());

		$this->assertFalse(
			$authorizer->can('integriq', 'catalog.instantiate', $this->createMock(IUser::class))
		);
	}

	/**
	 * 🔴 A throwing matrix is a refusal, not a pass.
	 *
	 * ADR-023's own requireAction() throws to DENY, so anything propagating
	 * out of can() must not be read as consent.
	 *
	 * @return void
	 */
	public function testAThrowingMatrixRefuses(): void {
		$authorizer = $this->authorizer(new ThrowingActionAuthService());

		$this->assertFalse(
			$authorizer->can('integriq', 'catalog.instantiate', $this->createMock(IUser::class))
		);
	}

	/**
	 * Every refusal is logged at ERROR with the reason.
	 *
	 * A silent refusal is nearly as bad as a silent pass: somebody has to be
	 * able to find out why the store stopped installing.
	 *
	 * @return void
	 */
	public function testARefusalIsLoggedWithItsReason(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not found'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with($this->stringContains('catalog.instantiate'), $this->anything());

		$authorizer = new StoreActionAuthorizer($container, $logger, $this->createMock(IGroupManager::class));
		$authorizer->can('integriq', 'catalog.instantiate', $this->createMock(IUser::class));
	}

	/**
	 * A descriptor that names the given publish groups.
	 *
	 * @param array<int, string> $groups The publish groups.
	 *
	 * @return StoreDescriptor
	 */
	private function publishing(array $groups): StoreDescriptor {
		return new StoreDescriptor(
			appId: 'learniq',
			schema: 'shared-course-package',
			defaultRegister: 'learniq',
			publishFields: ['title'],
			publishGroups: $groups
		);
	}

	/**
	 * A user with the given uid.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	/**
	 * A group manager with the given groups and memberships.
	 *
	 * @param array<string, array<int, string>> $members Group id => member uids.
	 * @param array<int, string>                $admins  Administrator uids.
	 *
	 * @return IGroupManager
	 */
	private function groupManager(array $members, array $admins = []): IGroupManager {
		$manager = $this->createMock(IGroupManager::class);
		$manager->method('groupExists')->willReturnCallback(
			static fn (string $gid): bool => array_key_exists($gid, $members)
		);
		$manager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => in_array($uid, ($members[$gid] ?? []), true)
		);
		$manager->method('isAdmin')->willReturnCallback(
			static fn (string $uid): bool => in_array($uid, $admins, true)
		);
		return $manager;
	}

	/**
	 * An authorizer over the given group manager and logger.
	 *
	 * @param IGroupManager        $groups The group manager.
	 * @param LoggerInterface|null $logger The logger, or a silent mock.
	 *
	 * @return StoreActionAuthorizer
	 */
	private function publishAuthorizer(IGroupManager $groups, ?LoggerInterface $logger = null): StoreActionAuthorizer {
		return new StoreActionAuthorizer(
			$this->createMock(ContainerInterface::class),
			($logger ?? $this->createMock(LoggerInterface::class)),
			$groups
		);
	}

	/**
	 * A member of a named group may publish.
	 *
	 * @return void
	 */
	public function testCanPublishPermitsAMemberOfANamedGroup(): void {
		$authorizer = $this->publishAuthorizer($this->groupManager(['instructors' => ['teacher']]));

		$this->assertTrue($authorizer->canPublish($this->publishing(['instructors']), $this->user('teacher')));
	}

	/**
	 * A user outside every named group is refused.
	 *
	 * @return void
	 */
	public function testCanPublishRefusesANonMember(): void {
		$authorizer = $this->publishAuthorizer(
			$this->groupManager(['instructors' => ['teacher'], 'learners' => ['pupil']])
		);

		$this->assertFalse($authorizer->canPublish($this->publishing(['instructors']), $this->user('pupil')));
	}

	/**
	 * 🔴 No named group refuses everybody, administrators included, and says why.
	 *
	 * @return void
	 */
	public function testCanPublishRefusesWhenNoGroupIsNamed(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))
			->method('error')
			->with($this->stringContains('learniq'), $this->anything());
		$authorizer = $this->publishAuthorizer($this->groupManager(['admin' => ['root']], ['root']), $logger);

		$this->assertFalse($authorizer->canPublish($this->publishing([]), $this->user('root')));
		$this->assertFalse(
			$authorizer->canPublish($this->publishing(['  ']), $this->user('root')),
			'A blank group name names nobody.'
		);
	}

	/**
	 * A named group that does not exist admits nobody, and is logged.
	 *
	 * @return void
	 */
	public function testCanPublishLogsAGroupThatDoesNotExist(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with($this->stringContains('instrutcors'), $this->anything());
		$authorizer = $this->publishAuthorizer($this->groupManager(['instructors' => ['teacher']]), $logger);

		$this->assertFalse($authorizer->canPublish($this->publishing(['instrutcors']), $this->user('teacher')));
	}

	/**
	 * An administrator passes once a group is named, as ADR-023's matrix lets them.
	 *
	 * @return void
	 */
	public function testCanPublishAdmitsAnAdministratorOnlyWhenAGroupIsNamed(): void {
		$authorizer = $this->publishAuthorizer(
			$this->groupManager(['instructors' => ['teacher'], 'admin' => ['root']], ['root'])
		);

		$this->assertTrue($authorizer->canPublish($this->publishing(['instructors']), $this->user('root')));
		$this->assertFalse($authorizer->canPublish($this->publishing([]), $this->user('root')));
	}

	/**
	 * The ADR-023 EVERYONE entry admits any signed-in user.
	 *
	 * @return void
	 */
	public function testCanPublishHonoursEveryone(): void {
		$authorizer = $this->publishAuthorizer($this->groupManager([]));

		$this->assertTrue($authorizer->canPublish($this->publishing(['@authenticated']), $this->user('anybody')));
	}
}
