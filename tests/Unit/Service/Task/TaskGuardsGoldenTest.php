<?php

/**
 * Golden outcomes of the two task guards: which blockers a task may name
 * (TaskBlockerGuard) and who may decide a sequence position
 * (TaskSequenceDecisionGuard), allowed and refused.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use OCA\OpenRegister\Exception\TaskSeparationOfDutiesException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Task\TaskBlockerGuard;
use OCA\OpenRegister\Service\Task\TaskSequenceDecisionGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Pins allowed (no exception) and refused (class + message) outcomes.
 */
class TaskGuardsGoldenTest extends TestCase {

	private const UNKNOWN = "blockedBy names task 'b', and no task has that uuid.";

	private const LOOP = "blockedBy 'b' would make a loop: that task already waits, through its chain, on this one.";

	private const SELF_DECIDES = "Separation of duties: 'rita' requested this approval and may not decide it. This is not an authorization failure.";

	private const DELEGATED = "Separation of duties: this decision acts on behalf of 'rita', who requested the approval. A delegated self-decision is refused on the same grounds.";

	/**
	 * The outcome of a call: 'ok', or the exception class and message.
	 */
	private function outcome(callable $call): string {
		try {
			$call();

			return 'ok';
		} catch (Throwable $refused) {
			return $refused::class . ': ' . $refused->getMessage();
		}
	}//end outcome()

	/**
	 * A blocker guard over stored tasks uuid => their own blockedBy.
	 *
	 * @param array<string, string|null> $chain
	 */
	private function blockerGuard(array $chain): TaskBlockerGuard {
		$tasks = $this->createMock(TaskMapper::class);
		$tasks->method('findByUuid')->willReturnCallback(
			static function (string $uuid) use ($chain): Task {
				if (array_key_exists($uuid, $chain) === false) {
					throw new DoesNotExistException('none');
				}

				$task = new Task();
				$task->setUuid($uuid);
				$task->setBlockedBy($chain[$uuid]);

				return $task;
			}
		);

		return new TaskBlockerGuard(tasks: $tasks);
	}//end blockerGuard()

	private function waiting(?string $blockedBy): Task {
		$task = new Task();
		$task->setUuid('own');
		$task->setBlockedBy($blockedBy);

		return $task;
	}//end waiting()

	/**
	 * Blocker declarations and their outcome.
	 *
	 * @return array<string, array{0: string|null, 1: array<string, string|null>, 2: callable|null, 3: string}>
	 */
	public static function blockers(): array {
		$v = TaskValidationException::class . ': ';
		$deny = static fn (Task $task): bool => false;
		$truthy = static fn (Task $task): int => 1;
		$onlyB = static fn (Task $task): bool => $task->getUuid() === 'b';
		$longChain = [];
		for ($i = 0; $i < 60; $i++) {
			$longChain['c' . $i] = 'c' . ($i + 1);
		}

		$longChain['b'] = 'c0';
		$longChain['c48'] = 'own';
		$longerChain = $longChain;
		$longerChain['c48'] = 'c49';
		$longerChain['c49'] = 'own';

		return [
			'none' => [null, [], null, 'ok'],
			'empty' => ['', [], null, 'ok'],
			'itself' => ['own', [], null, $v . 'A task cannot wait on itself.'],
			'unknown' => ['b', [], null, $v . self::UNKNOWN],
			'open blocker' => ['b', ['b' => null], null, 'ok'],
			'chain ends' => ['b', ['b' => 'c', 'c' => ''], null, 'ok'],
			'removed ancestor' => ['b', ['b' => 'c'], null, 'ok'],
			'direct loop' => ['b', ['b' => 'own'], null, $v . self::LOOP],
			'deep loop' => ['b', ['b' => 'c', 'c' => 'd', 'd' => 'own'], null, $v . self::LOOP],
			'unreadable named' => ['b', ['b' => null], $deny, $v . self::UNKNOWN],
			'truthy is not true' => ['b', ['b' => null], $truthy, $v . self::UNKNOWN],
			'unreadable ancestor is fine' => ['b', ['b' => 'c', 'c' => null], $onlyB, 'ok'],
			'unreadable named and missing ancestor' => ['b', ['b' => 'c'], $deny, $v . self::UNKNOWN],
			'loop at step 50 is found' => ['b', $longChain, null, $v . self::LOOP],
			'loop past step 50 is not walked' => ['b', $longerChain, null, 'ok'],
		];
	}//end blockers()

	/**
	 * @dataProvider blockers
	 *
	 * @param array<string, string|null> $chain
	 */
	public function testABlockerDeclaration(?string $blockedBy, array $chain, ?callable $mayRead, string $expected): void {
		$guard = $this->blockerGuard($chain);

		$this->assertSame($expected, $this->outcome(fn () => $guard->assertDeclarable($this->waiting($blockedBy), $mayRead)));
	}//end testABlockerDeclaration()

	/**
	 * Decisions and their outcome.
	 *
	 * @return array<string, array{0: string|null, 1: string|null|false, 2: mixed, 3: string|null, 4: string|null, 5: string}>
	 */
	public static function decisions(): array {
		$s = TaskSeparationOfDutiesException::class . ': ';

		return [
			'no sequence' => [null, 'rita', null, null, 'rita', 'ok'],
			'blank sequence' => ['  ', 'rita', null, null, 'rita', 'ok'],
			'dangling sequence' => ['seq', false, null, null, 'rita', 'ok'],
			'requester decides' => ['seq', 'rita', null, null, 'rita', $s . self::SELF_DECIDES],
			'requester padded' => ['seq', ' rita ', null, null, 'rita ', $s . self::SELF_DECIDES],
			'policy on explicitly' => ['seq', 'rita', true, null, 'rita', $s . self::SELF_DECIDES],
			'policy string false is on' => ['seq', 'rita', 'false', null, 'rita', $s . self::SELF_DECIDES],
			'policy off' => ['seq', 'rita', false, null, 'rita', 'ok'],
			'no requester' => ['seq', null, null, null, 'rita', 'ok'],
			'blank requester' => ['seq', '  ', null, null, '', 'ok'],
			'other decider' => ['seq', 'rita', null, null, 'bob', 'ok'],
			'null actor' => ['seq', 'rita', null, null, null, 'ok'],
			'delegated self decision' => ['seq', 'rita', null, ' rita', 'bob', $s . self::DELEGATED],
			'delegated for another' => ['seq', 'rita', null, 'olga', 'bob', 'ok'],
			'self wins over delegation' => ['seq', 'rita', null, 'rita', 'rita', $s . self::SELF_DECIDES],
			'delegation with policy off' => ['seq', 'rita', false, 'rita', 'bob', 'ok'],
		];
	}//end decisions()

	/**
	 * @dataProvider decisions
	 *
	 * @param string|null|false $requester False when the sequence does not exist.
	 */
	public function testADecision(?string $sequenceUuid, string|null|false $requester, mixed $policy, ?string $onBehalfOf, ?string $actor, string $expected): void {
		$sequences = $this->createMock(TaskSequenceMapper::class);
		if ($requester === false) {
			$sequences->method('findByUuid')->willThrowException(new DoesNotExistException('none'));
		} else {
			$sequence = new TaskSequence();
			$sequence->setRequesterId($requester);
			$snapshot = [];
			if ($policy !== null) {
				$snapshot['separationOfDuties'] = $policy;
			}

			$sequence->setTemplateSnapshot($snapshot);
			$sequences->method('findByUuid')->willReturn($sequence);
		}

		$task = new Task();
		$task->setSequenceUuid($sequenceUuid);
		$task->setOnBehalfOf($onBehalfOf);
		$guard = new TaskSequenceDecisionGuard(sequences: $sequences);

		$this->assertSame($expected, $this->outcome(fn () => $guard->assertDecidable($task, $actor)));
	}//end testADecision()
}//end class
