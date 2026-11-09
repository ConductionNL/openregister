<?php

/**
 * Golden characterization test for TaskPoolRecipientResolver.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Service\Notification\TaskPoolRecipientResolver;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Resolves one mixed candidate pool and pins the uids, their order, every
 * group and user lookup, and every warning.
 *
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\TaskPoolRecipientResolver
 */
final class TaskPoolRecipientResolverGoldenTest extends TestCase {

	/**
	 * Lookups and log lines in call order.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $calls = [];

	/**
	 * A group whose users have the given uids.
	 *
	 * @param array<int, string> $uids The member uids.
	 *
	 * @return IGroup
	 */
	private function group(array $uids): IGroup {
		$users = [];
		foreach ($uids as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		return $group;
	}//end group()

	/**
	 * Resolve a pool against fixed groups and users.
	 *
	 * @param array<string, mixed> $payload The adapter payload.
	 *
	 * @return array<int, string>
	 */
	private function resolve(array $payload): array {
		$groups = [
			'permits' => $this->group(['ann', 'bob']),
			'finance' => $this->group(['bob', 'cas']),
			'empty' => $this->group([]),
		];
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnCallback(
			function (string $gid) use ($groups): ?IGroup {
				$this->calls[] = ['group.get', $gid];
				if ($gid === 'broken') {
					throw new \RuntimeException('backend down');
				}

				return ($groups[$gid] ?? null);
			}
		);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('userExists')->willReturnCallback(
			function (string $uid): bool {
				$this->calls[] = ['user.exists', $uid];
				return in_array($uid, ['ann', 'dee', 'eve'], true);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message): void {
				$this->calls[] = ['log.warning', (string)$message];
			}
		);

		$object = new ObjectEntity();
		$object->setObject($payload);
		return (new TaskPoolRecipientResolver($groupManager, $userManager, $logger))->resolve($object, []);
	}//end resolve()

	/**
	 * A mixed pool resolves to the recorded uids, lookups and warnings.
	 *
	 * @return void
	 */
	public function testMixedPool(): void {
		$uids = $this->resolve(
			[
				'candidateGroups' => ['permits', 7, '  ', 'broken', 'missing', 'finance', 'empty', null],
				'candidateUsers' => ['dee', 'ann', 'zed', ' ', 3, 'eve', 'dee'],
			]
		);

		$this->assertSame(['ann', 'bob', 'cas', 'dee', 'eve'], $uids);
		$this->assertSame(
			[
				['group.get', 'permits'],
				['group.get', 'broken'],
				['log.warning', '[TaskPoolRecipientResolver] Group "broken" could not be resolved; nobody is notified for it: backend down'],
				['group.get', 'missing'],
				['log.warning', '[TaskPoolRecipientResolver] Group "missing" does not exist; nobody is notified for it.'],
				['group.get', 'finance'],
				['group.get', 'empty'],
				['user.exists', 'dee'],
				['user.exists', 'ann'],
				['user.exists', 'zed'],
				['user.exists', 'eve'],
				['user.exists', 'dee'],
			],
			$this->calls
		);
	}//end testMixedPool()

	/**
	 * A scalar pool is cast to a one-element list; a missing pool is empty.
	 *
	 * @return void
	 */
	public function testScalarAndMissingPools(): void {
		$this->assertSame(['ann', 'bob'], $this->resolve(['candidateGroups' => 'permits']));
		$this->assertSame([], $this->resolve([]));
		$this->assertSame([['group.get', 'permits']], $this->calls);
	}//end testScalarAndMissingPools()
}//end class
