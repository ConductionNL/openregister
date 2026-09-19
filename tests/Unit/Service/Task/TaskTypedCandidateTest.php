<?php

/**
 * The task pool guard and the run guard must answer the same question the same way.
 *
 * 🔴 TWO GUARDS OVER ONE QUESTION IS HOW THEY COME TO DISAGREE, and a
 * disagreement here means a task somebody can complete and cannot see, or the
 * reverse. The typed branch runs AFTER the three legacy ones, so nothing they
 * already admit can be narrowed by it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
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

namespace Unit\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Service\Flow\Principal\IPrincipalResolver;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCA\OpenRegister\Service\Flow\Principal\RegisterPrincipalResolversEvent;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A resolver holding a fixed roster.
 */
class PoolResolver implements IPrincipalResolver {

	/**
	 * Constructor.
	 *
	 * @param string                            $type    The type it answers for.
	 * @param array<string, array<int, string>> $holders Who holds each id.
	 */
	public function __construct(
		private readonly string $type,
		private readonly array $holders = [],
	) {

	}//end __construct()

	/**
	 * The type.
	 *
	 * @return string The type.
	 */
	public function type(): string {
		return $this->type;
	}//end type()

	/**
	 * Who holds it.
	 *
	 * @param string $id The id.
	 *
	 * @return array<int, string> The uids.
	 */
	public function resolve(string $id): array {
		return ($this->holders[$id] ?? []);
	}//end resolve()
}//end class

/**
 * The typed half of {@see TaskAuthorizationService}.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskAuthorizationService
 * @uses \OCA\OpenRegister\Service\Flow\Principal\PrincipalReference
 * @uses \OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry
 * @uses \OCA\OpenRegister\Service\Flow\Principal\RegisterPrincipalResolversEvent
 */
final class TaskTypedCandidateTest extends TestCase {

	/**
	 * A guard that understands `position`.
	 *
	 * @param array<string, array<int, string>> $holders Who holds each position.
	 * @param boolean                           $withRegistry Whether to give it a registry at all.
	 *
	 * @return TaskAuthorizationService The guard.
	 */
	private function guard(array $holders, bool $withRegistry = true): TaskAuthorizationService {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturn(false);

		if ($withRegistry === false) {
			return new TaskAuthorizationService($groups);
		}

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($holders): void {
				if (($event instanceof RegisterPrincipalResolversEvent) === true) {
					$event->registerResolver(new PoolResolver('position', $holders));
				}
			}
		);

		return new TaskAuthorizationService(
			$groups,
			new PrincipalResolverRegistry($dispatcher, $this->createMock(LoggerInterface::class))
		);
	}//end guard()

	/**
	 * A task whose candidate users field holds these values.
	 *
	 * @param mixed $candidateUsers What the node wrote.
	 *
	 * @return Task The task.
	 */
	private function taskWithCandidates(mixed $candidateUsers): Task {
		$task = new Task();
		// Without a performer type the guard cannot determine authorization at
		// all and denies before reaching the pool — a denial that says nothing
		// about candidates.
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setCandidateUsers($candidateUsers);

		return $task;
	}//end taskWithCandidates()

	/**
	 * Whether the guard admits this caller.
	 *
	 * @param TaskAuthorizationService $guard The guard.
	 * @param Task                     $task  The task.
	 * @param string                   $uid   The caller.
	 *
	 * @return boolean Whether the verb was allowed.
	 */
	private function admits(TaskAuthorizationService $guard, Task $task, string $uid): bool {
		try {
			// `claim` is the verb whose rule is the candidate POOL; `complete`
			// asks about the assignee, which is a different question.
			$guard->assertMay(verb: 'claim', task: $task, uid: $uid);

			return true;
		} catch (TaskAccessDeniedException) {
			return false;
		}
	}//end admits()

	/**
	 * 🔴 A TYPED CANDIDATE ADMITS WHOEVER HOLDS IT, THROUGH THE SAME REGISTRY.
	 *
	 * @return void
	 */
	public function testATypedCandidateAdmitsWhoeverHoldsIt(): void {
		$guard = $this->guard(['chair' => ['alice']]);
		$task = $this->taskWithCandidates([['type' => 'position', 'id' => 'chair']]);

		$this->assertTrue($this->admits($guard, $task, 'alice'));
		$this->assertFalse($this->admits($guard, $task, 'bob'));
	}//end testATypedCandidateAdmitsWhoeverHoldsIt()

	/**
	 * A bare candidate keeps working, unchanged.
	 *
	 * Every stored task on every instance names its pool this way.
	 *
	 * @return void
	 */
	public function testABareCandidateStillAdmits(): void {
		$guard = $this->guard([]);

		$this->assertTrue($this->admits($guard, $this->taskWithCandidates(['alice']), 'alice'));
		$this->assertFalse($this->admits($guard, $this->taskWithCandidates(['alice']), 'bob'));
	}//end testABareCandidateStillAdmits()

	/**
	 * Without a registry, a typed candidate is not affirmed.
	 *
	 * Fail closed, like every other branch here.
	 *
	 * @return void
	 */
	public function testWithoutARegistryATypedCandidateIsNotAffirmed(): void {
		$guard = $this->guard([], withRegistry: false);
		$task = $this->taskWithCandidates([['type' => 'position', 'id' => 'chair']]);

		$this->assertFalse($this->admits($guard, $task, 'alice'));
	}//end testWithoutARegistryATypedCandidateIsNotAffirmed()

	/**
	 * A type nothing on this instance resolves does not admit.
	 *
	 * @return void
	 */
	public function testAnUnresolvableTypeDoesNotAdmit(): void {
		$guard = $this->guard(['chair' => ['alice']]);
		$task = $this->taskWithCandidates([['type' => 'gremium', 'id' => 'bezwaar']]);

		$this->assertFalse($this->admits($guard, $task, 'alice'));
	}//end testAnUnresolvableTypeDoesNotAdmit()
}//end class
