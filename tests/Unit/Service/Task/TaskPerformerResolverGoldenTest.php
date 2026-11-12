<?php

/**
 * Golden test for TaskPerformerResolver.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskPerformerResolver;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden test for TaskPerformerResolver::expandPool() and ::resolveAssignee().
 *
 * One fixed group backend and one fixed load/recency table; every strategy
 * over several pools, with and without a fallback. The expected values were
 * recorded from the code as it stood before the refactor.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskPerformerResolver
 * @uses   \OCA\OpenRegister\Db\Task
 */
class TaskPerformerResolverGoldenTest extends TestCase {

	/**
	 * Mapper and group-backend calls recorded during a case.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	private function resolver(bool $withGroups): TaskPerformerResolver {
		$mapper = $this->createMock(TaskMapper::class);
		$mapper->method('countOpenAssigned')->willReturnCallback(
			function (array $uids): array {
				$this->calls[] = 'count(' . implode(',', $uids) . ')';
				return ['ann' => 3, 'bob' => 1, 'cas' => 1, 'dan' => 0, '42' => 1];
			}
		);
		$mapper->method('latestAssignedAt')->willReturnCallback(
			function (array $uids): array {
				$this->calls[] = 'latest(' . implode(',', $uids) . ')';
				return [
					'ann' => '2026-01-03 09:00:00',
					'bob' => '2026-01-01 09:00:00',
					'cas' => '2026-01-01 09:00:00',
					'42' => '2026-01-02 09:00:00',
				];
			}
		);

		if ($withGroups === false) {
			return new TaskPerformerResolver($mapper);
		}

		return new TaskPerformerResolver($mapper, $this->groupManager());
	}//end resolver()

	private function groupManager(): IGroupManager {
		$groups = [
			'clerks' => ['cas', 'ann', 'eve'],
			'leads' => ['bob'],
			'numbers' => ['42'],
			'empty' => [],
		];
		$manager = $this->createMock(IGroupManager::class);
		$manager->method('get')->willReturnCallback(
			function (string $gid) use ($groups): ?IGroup {
				$this->calls[] = 'group(' . $gid . ')';
				if ($gid === 'broken') {
					throw new RuntimeException('backend down');
				}

				if (array_key_exists($gid, $groups) === false) {
					return null;
				}

				return $this->group($groups[$gid]);
			}
		);
		return $manager;
	}//end groupManager()

	private function group(array $uids): IGroup {
		$users = [];
		foreach ($uids as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users['key-' . $uid] = $user;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		return $group;
	}//end group()

	private static function task(array $fields): Task {
		$task = new Task();
		$task->setCandidateUsers($fields['users'] ?? null);
		$task->setCandidateGroups($fields['groups'] ?? null);
		$task->setCandidateRole($fields['role'] ?? null);
		$task->setRoutingStrategy($fields['strategy'] ?? null);
		$task->setRoutingFallback($fields['fallback'] ?? null);
		return $task;
	}//end task()

	public function testExpandPool(): void {
		$cases = [
			'nothing' => [[], true, [], []],
			'users only' => [['users' => ['dan', 'ann', 'dan']], true, ['dan', 'ann'], []],
			'groups and role, ordered and deduplicated' => [
				['users' => ['ann'], 'groups' => ['leads', 'clerks', 'ghost', 'broken', ''], 'role' => ' numbers '],
				true,
				['ann', 'bob', 'cas', 'eve', '42'],
				['group(leads)', 'group(clerks)', 'group(ghost)', 'group(broken)', 'group(numbers)'],
			],
			'no backend' => [['users' => ['ann'], 'groups' => ['leads'], 'role' => 'clerks'], false, ['ann'], []],
			'blank role' => [['groups' => ['empty'], 'role' => '   '], true, [], ['group(empty)']],
		];
		foreach ($cases as $name => [$fields, $withGroups, $pool, $calls]) {
			$this->calls = [];
			$this->assertSame($pool, $this->resolver($withGroups)->expandPool(self::task($fields)), $name);
			$this->assertSame($calls, $this->calls, $name . ' calls');
		}
	}//end testExpandPool()

	public function testResolveAssignee(): void {
		$pools = [
			'empty' => [],
			'one' => ['users' => ['dan']],
			'many' => ['users' => ['ann', 'bob'], 'groups' => ['clerks']],
			'numeric' => ['users' => ['42', 'cas'], 'groups' => ['leads']],
		];
		$strategies = ['single-role', 'or-set', 'hierarchical', 'round-robin', 'least-loaded', '', 'bogus', ' least-loaded '];
		$expected = [
			'single-role' => ['empty' => null, 'one' => 'dan', 'many' => null, 'numeric' => null],
			'or-set' => ['empty' => null, 'one' => null, 'many' => null, 'numeric' => null],
			'hierarchical' => ['empty' => null, 'one' => 'dan', 'many' => 'ann', 'numeric' => '42'],
			'round-robin' => ['empty' => null, 'one' => 'dan', 'many' => 'eve', 'numeric' => 'bob'],
			'least-loaded' => ['empty' => null, 'one' => 'dan', 'many' => 'eve', 'numeric' => '42'],
			'' => ['empty' => null, 'one' => null, 'many' => null, 'numeric' => null],
			'bogus' => ['empty' => null, 'one' => null, 'many' => null, 'numeric' => null],
			' least-loaded ' => ['empty' => null, 'one' => 'dan', 'many' => 'eve', 'numeric' => '42'],
		];
		foreach ($strategies as $strategy) {
			foreach ($pools as $poolName => $fields) {
				$label = $strategy . '/' . $poolName;
				$fields['strategy'] = $strategy;
				$this->assertSame($expected[$strategy][$poolName], $this->resolve($fields), $label);

				$fields['fallback'] = '  sam ';
				$this->assertSame($expected[$strategy][$poolName] ?? 'sam', $this->resolve($fields), $label . ' with fallback');

				$fields['fallback'] = '   ';
				$this->assertSame($expected[$strategy][$poolName], $this->resolve($fields), $label . ' with blank fallback');
			}
		}
	}//end testResolveAssignee()

	public function testRankingReadsAndTieBreaks(): void {
		$this->calls = [];
		$resolver = $this->resolver(true);
		$this->assertSame('bob', $resolver->resolveAssignee(self::task(['users' => ['cas', 'bob', 'ann'], 'strategy' => 'round-robin'])));
		$this->assertSame('bob', $resolver->resolveAssignee(self::task(['users' => ['cas', 'bob', 'ann'], 'strategy' => 'least-loaded'])));
		$this->assertSame(
			['latest(cas,bob,ann)', 'count(cas,bob,ann)'],
			$this->calls
		);

		$this->calls = [];
		$this->assertNull($resolver->resolveAssignee(self::task(['strategy' => 'least-loaded'])));
		$this->assertNull($resolver->resolveAssignee(self::task(['strategy' => 'round-robin'])));
		$this->assertSame([], $this->calls);
	}//end testRankingReadsAndTieBreaks()

	private function resolve(array $fields): ?string {
		return $this->resolver(true)->resolveAssignee(self::task($fields));
	}//end resolve()
}//end class
