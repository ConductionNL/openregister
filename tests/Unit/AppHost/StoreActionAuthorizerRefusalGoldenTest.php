<?php

/**
 * Golden test pinning StoreActionAuthorizer's allowed and refused outcomes
 * together with the exact refusal it logs.
 *
 * Written against the code before its refusal logging was restructured
 * (ADR-011), so the restructure has to reproduce every outcome and message.
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
use OCA\OpenRegister\AppHost\Service\StoreListing;
use OCA\OpenRegister\AppHost\Service\StoreSharing;
use OCA\OpenRegister\AppHost\Store\StoreActionAuthorizer;
use OCA\OpenRegister\AppHost\Store\StoreActionCheck;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A leaf matrix that answers a fixed bool.
 */
class GoldenAnsweringActionAuthService {
	/**
	 * @param bool $answer What can() returns.
	 */
	public function __construct(private readonly bool $answer) {
	}

	/**
	 * @param IUser  $user   The user.
	 * @param string $action The action.
	 * @return bool
	 */
	public function can(IUser $user, string $action): bool {
		return $this->answer;
	}
}

/**
 * A leaf matrix that throws.
 */
class GoldenThrowingActionAuthService {
	/**
	 * @param IUser  $user   The user.
	 * @param string $action The action.
	 * @return bool
	 */
	public function can(IUser $user, string $action): bool {
		throw new RuntimeException('matrix unavailable');
	}
}

/**
 * A leaf matrix that answers a truthy non-bool, which must not read as consent.
 */
class TruthyActionAuthService {
	/**
	 * @param IUser  $user   The user.
	 * @param string $action The action.
	 * @return int
	 */
	public function can(IUser $user, string $action): int {
		return 1;
	}
}

/**
 * @covers \OCA\OpenRegister\AppHost\Store\StoreActionAuthorizer
 * @uses   \OCA\OpenRegister\AppHost\Service\StoreDescriptor
 */
class StoreActionAuthorizerRefusalGoldenTest extends TestCase {
	/**
	 * Messages the logger received at ERROR.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * Build an authorizer over a container answer and a group manager.
	 *
	 * @param mixed         $service What the container returns, or a Throwable to throw.
	 * @param IGroupManager $groups  The group manager.
	 *
	 * @return StoreActionAuthorizer
	 */
	private function authorizer(mixed $service, IGroupManager $groups): StoreActionAuthorizer {
		$container = $this->createMock(ContainerInterface::class);
		if ($service instanceof \Throwable) {
			$container->method('get')->willThrowException($service);
		} else {
			$container->method('get')->willReturn($service);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		return new StoreActionAuthorizer($container, $logger, $groups);
	}

	/**
	 * A user double.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	/**
	 * A group manager where only `staff` exists, holding `alice`, and `root` is admin.
	 *
	 * @return IGroupManager
	 */
	private function groupManager(): IGroupManager {
		$manager = $this->createMock(IGroupManager::class);
		$manager->method('groupExists')->willReturnCallback(static fn (string $gid): bool => $gid === 'staff');
		$manager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => $gid === 'staff' && $uid === 'alice'
		);
		$manager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'root');
		return $manager;
	}

	/**
	 * A publishing descriptor naming the given groups.
	 *
	 * @param array<int, string> $groups The publish groups.
	 *
	 * @return StoreDescriptor
	 */
	private function publishing(array $groups): StoreDescriptor {
		return new StoreDescriptor(
			listing: new StoreListing(
				appId: 'learniq',
				schema: 'shared-course-package',
				defaultRegister: 'learniq'
			),
			sharing: new StoreSharing(
				publishFields: ['title'],
				publishGroups: $groups
			)
		);
	}

	/**
	 * Install-side outcomes and the exact refusal logged for each.
	 *
	 * @return array<string, array{0: mixed, 1: bool, 2: array<int, string>}>
	 */
	public static function installCases(): array {
		$prefix = '[AppHost\\Store] refusing install for my-app: action "catalog.instantiate" could not be authorised — ';
		return [
			'leaf says yes'        => [new GoldenAnsweringActionAuthService(true), true, []],
			'leaf says no'         => [new GoldenAnsweringActionAuthService(false), false, []],
			'truthy non-bool'      => [new TruthyActionAuthService(), false, []],
			'unresolvable service' => [
				new RuntimeException('nope'),
				false,
				[$prefix . 'OCA\\MyApp\\Service\\ActionAuthService is not resolvable (nope)'],
			],
			'no can() method'      => [
				new \stdClass(),
				false,
				[$prefix . 'OCA\\MyApp\\Service\\ActionAuthService has no can() method'],
			],
			'throwing matrix'      => [
				new GoldenThrowingActionAuthService(),
				false,
				[$prefix . 'can() threw: matrix unavailable'],
			],
		];
	}

	/**
	 * Pin can()'s outcome and logged refusal for every resolution path.
	 *
	 * @dataProvider installCases
	 *
	 * @param mixed              $service  What the container answers.
	 * @param bool               $expected The expected decision.
	 * @param array<int, string> $logged   The exact ERROR messages.
	 *
	 * @return void
	 */
	public function testInstallDecisionAndRefusalMessage(mixed $service, bool $expected, array $logged): void {
		$authorizer = $this->authorizer($service, $this->groupManager());

		$this->assertSame($expected, $authorizer->permits(new StoreActionCheck('my-app', 'catalog.instantiate', $this->user('alice'))));
		$this->assertSame($logged, $this->errors);
	}

	/**
	 * Publish-side outcomes and the exact refusal logged for each.
	 *
	 * @return array<string, array{0: array<int, string>, 1: string, 2: bool, 3: array<int, string>}>
	 */
	public static function publishCases(): array {
		$prefix = '[AppHost\\Store] refusing publish for learniq: action "publish" could not be authorised — ';
		$none   = $prefix . 'the descriptor names no publish group, so the app has not decided who may publish';
		return [
			'member of named group'    => [['staff'], 'alice', true, []],
			'non-member'               => [['staff'], 'bob', false, []],
			'admin with a group named' => [['staff'], 'root', true, []],
			'admin with no group'      => [[], 'root', false, [$none]],
			'everyone'                 => [['@authenticated'], 'bob', true, []],
			'missing group then member' => [
				['ghosts', 'staff'],
				'alice',
				true,
				[$prefix . 'publish group "ghosts" does not exist on this server'],
			],
			'missing group only'       => [
				['ghosts'],
				'alice',
				false,
				[$prefix . 'publish group "ghosts" does not exist on this server'],
			],
		];
	}

	/**
	 * Pin canPublish()'s outcome and logged refusal for every path.
	 *
	 * @dataProvider publishCases
	 *
	 * @param array<int, string> $groups   The descriptor's publish groups.
	 * @param string             $uid      The user.
	 * @param bool               $expected The expected decision.
	 * @param array<int, string> $logged   The exact ERROR messages.
	 *
	 * @return void
	 */
	public function testPublishDecisionAndRefusalMessage(array $groups, string $uid, bool $expected, array $logged): void {
		$authorizer = $this->authorizer(new GoldenAnsweringActionAuthService(true), $this->groupManager());

		$this->assertSame($expected, $authorizer->canPublish($this->publishing($groups), $this->user($uid)));
		$this->assertSame($logged, $this->errors);
	}
}
