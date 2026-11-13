<?php

/**
 * Golden test for TaskSequenceService.
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
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use OCA\OpenRegister\Event\TaskSequenceCompletedEvent;
use OCA\OpenRegister\Event\TaskSequenceUndecidedEvent;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\ResolvedChain;
use OCA\OpenRegister\Service\Task\SequenceAnchor;
use OCA\OpenRegister\Service\Task\SequenceProvenance;
use OCA\OpenRegister\Service\Task\TaskSequenceService;
use OCA\OpenRegister\Service\Task\TaskSequenceServices;
use OCA\OpenRegister\Service\Task\TaskTrigger;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test pinning every payload provision() imports, the sequence row it
 * inserts, and the exact calls onTaskTerminal() makes per scenario. Compared
 * with fixtures/task-sequence-service-golden.json; set UPDATE_GOLDEN=1 to
 * rewrite the fixture. The random sequence uuid is masked as `<seq>`.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskSequenceService
 * @uses \OCA\OpenRegister\Service\Task\ApprovingOutcomeSet
 * @uses \OCA\OpenRegister\Service\Task\ChainPosition
 * @uses \OCA\OpenRegister\Service\Task\PositionAdvance
 * @uses \OCA\OpenRegister\Service\Task\ResolvedChain
 * @uses \OCA\OpenRegister\Service\Task\SequenceAnchor
 * @uses \OCA\OpenRegister\Service\Task\SequenceClosure
 * @uses \OCA\OpenRegister\Service\Task\TaskSequenceServices
 * @uses \OCA\OpenRegister\Service\Task\TaskState
 * @uses \OCA\OpenRegister\Service\Task\TaskTrigger
 * @uses \OCA\OpenRegister\Service\Task\SequenceProvenance
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Db\TaskSequence
 * @uses \OCA\OpenRegister\Db\TypedFieldsJson
 * @uses \OCA\OpenRegister\Event\AbstractTaskSequenceEvent
 * @uses \OCA\OpenRegister\Event\TaskSequenceCompletedEvent
 * @uses \OCA\OpenRegister\Event\TaskSequenceUndecidedEvent
 */
class TaskSequenceServiceGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/task-sequence-service-golden.json';

	/** @var array<int, array<int, mixed>> */
	private array $calls = [];

	/** @var array<int, Task> */
	private array $siblings = [];

	/** @var TaskSequence|null The row findByUuid() answers with; null throws. */
	private ?TaskSequence $stored = null;

	/** @var array<int, string> Task uuids whose termination throws. */
	private array $failing = [];

	private function service(): TaskSequenceService {
		$sequences = $this->createMock(TaskSequenceMapper::class);
		$sequences->method('insert')->willReturnCallback(
			function (TaskSequence $sequence): TaskSequence {
				$this->calls[] = ['insert', self::row($sequence)];
				return $sequence;
			}
		);
		$sequences->method('update')->willReturnCallback(
			function (TaskSequence $sequence): TaskSequence {
				$this->calls[] = ['update', self::row($sequence)];
				return $sequence;
			}
		);
		$sequences->method('findByUuid')->willReturnCallback(
			function (string $uuid): TaskSequence {
				$this->calls[] = ['findByUuid', $uuid];
				if ($this->stored === null) {
					throw new DoesNotExistException('no sequence ' . $uuid);
				}

				return $this->stored;
			}
		);

		$reader = $this->createMock(TaskMapper::class);
		$reader->method('findBySequence')->willReturnCallback(
			function (string $sequenceUuid): array {
				$this->calls[] = ['findBySequence', $sequenceUuid];
				return $this->siblings;
			}
		);

		$tasks = $this->createMock(TaskService::class);
		$tasks->method('import')->willReturnCallback(
			function (array $data, ?string $actor): Task {
				$this->calls[] = ['import', self::masked($data), self::masked($actor)];
				return new Task();
			}
		);
		$tasks->method('enable')->willReturnCallback(
			function (string $uuid, TaskTrigger $trigger): Task {
				$this->calls[] = ['enable', $uuid, $trigger->source, $trigger->reason];
				return new Task();
			}
		);
		$tasks->method('terminateAsMoot')->willReturnCallback(
			function (string $uuid, TaskTrigger $trigger): Task {
				$reason = $trigger->reason;
				$source = $trigger->source;
				$this->calls[] = ['terminateAsMoot', $uuid, $reason, $source];
				if (in_array($uuid, $this->failing, true) === true) {
					throw new RuntimeException('cannot terminate ' . $uuid);
				}

				return new Task();
			}
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$entry = ['dispatch', $event::class];
				if ($event instanceof TaskSequenceCompletedEvent) {
					$entry[] = [self::row($event->getSequence()), $event->getFinalTask()->getUuid(), $event->getDecider(), $event->getStatusOnApprove()];
				}

				if ($event instanceof TaskSequenceUndecidedEvent) {
					$entry[] = [self::row($event->getSequence()), $event->getTask()->getUuid()];
				}

				$this->calls[] = $entry;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->calls[] = ['log:' . $level, $message, array_keys($context)];
				}
			);
		}

		return new TaskSequenceService(
			services: new TaskSequenceServices(sequences: $sequences, taskReader: $reader, tasks: $tasks, dispatcher: $dispatcher),
			logger: $logger
		);
	}

	/**
	 * @param TaskSequence $sequence The sequence.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(TaskSequence $sequence): array {
		$row = $sequence->jsonSerialize();
		unset($row['openedAt'], $row['closedAt'], $row['id']);
		$row['opened'] = ($sequence->getOpenedAt() !== null);
		$row['closed'] = ($sequence->getClosedAt() !== null);

		return self::masked($row);
	}

	private static function masked(mixed $value): mixed {
		$json = (string)json_encode($value);
		$json = (string)preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}/', '<seq>', $json);

		return json_decode($json, true);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function template(): array {
		return [
			'templateId' => 'tpl-1',
			'templateVersion' => 3,
			'name' => 'submit-approval',
			'schemaId' => '5',
			'positions' => [
				['order' => 1, 'role' => ' clerks '],
				['order' => 2, 'role' => 'managers', 'statusOnApprove' => 'published', 'statusOnReject' => 'returned-to-draft'],
				['role' => 'directors'],
			],
		];
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provisions(): array {
		return [
			'minimal' => ['minimal'],
			'everything' => ['everything'],
			'tier' => ['tier'],
			'approving set' => ['approving'],
			'empty template' => ['empty'],
			'no requester' => ['no-requester'],
		];
	}

	#[DataProvider('provisions')]
	public function testProvisionMatchesGolden(string $variant): void {
		$service = $this->service();
		$template = self::template();
		$sequence = match ($variant) {
			'minimal' => $service->provision(chain: new ResolvedChain(template: $template), anchor: new SequenceAnchor(objectUuid: 'obj-1', requesterId: 'alice')),
			'everything' => $service->provision(chain: new ResolvedChain(template: $template, tierPositions: null), anchor: new SequenceAnchor(objectUuid: 'obj-1', requesterId: 'alice', registerId: 7, provenance: new SequenceProvenance(runUuid: 'run-1', nodeId: 'node-1'))),
			'tier' => $service->provision(chain: new ResolvedChain(template: $template, tierPositions: [['order' => 4, 'role' => 'board', 'statusOnApprove' => 'x']]), anchor: new SequenceAnchor(objectUuid: 'obj-1', requesterId: 'alice', registerId: 7)),
			'approving' => $service->provision(chain: new ResolvedChain(template: $template + ['approvingOutcomes' => [' Approved ', 'ok', 3]]), anchor: new SequenceAnchor(objectUuid: 'obj-1', requesterId: 'alice')),
			'empty' => $service->provision(chain: new ResolvedChain(template: []), anchor: new SequenceAnchor(objectUuid: 'obj-2', requesterId: null)),
			'no-requester' => $service->provision(chain: new ResolvedChain(template: $template), anchor: new SequenceAnchor(objectUuid: 'obj-1', requesterId: null, provenance: new SequenceProvenance(runUuid: 'run-2'))),
		};

		$this->assertGolden('provision:' . $this->dataName(), ['calls' => $this->calls, 'returned' => self::row($sequence)]);
	}

	private static function position(int $position, string $state, ?string $outcome = null): Task {
		$task = new Task();
		$task->setUuid('task-' . $position);
		$task->setSequenceUuid('seq-1');
		$task->setSequencePosition($position);
		$task->setState($state);
		$task->setIsTerminal(in_array($state, Task::TERMINAL_STATES, true));
		$task->setOutcome($outcome);
		$task->setCompletedBy('decider-' . $position);

		return $task;
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function terminals(): array {
		return [
			'outside any sequence' => ['outside'],
			'dangling sequence' => ['dangling'],
			'terminal sequence' => ['terminal'],
			'cancelled position' => ['cancelled'],
			'rejected first position' => ['rejected-1'],
			'rejected declared position' => ['rejected-2'],
			'rejected without decider' => ['rejected-anon'],
			'approved first enables second' => ['approved-1'],
			'approved skips terminal later' => ['approved-skip'],
			'approved last completes' => ['approved-last'],
			'approved declared status on approve' => ['approved-declared'],
			'approved from tier' => ['approved-tier'],
			'undecided outcome' => ['undecided'],
			'declared approving set' => ['declared-set'],
			'termination failure is logged' => ['terminate-fails'],
		];
	}

	#[DataProvider('terminals')]
	public function testOnTaskTerminalMatchesGolden(string $scenario): void {
		$service = $this->service();
		$sequence = new TaskSequence();
		$sequence->setUuid('seq-1');
		$sequence->setTemplateSnapshot(self::template());
		$sequence->setStatus(TaskSequence::STATUS_RUNNING);
		$sequence->setPositionCursor(1);
		$this->stored = $sequence;
		$this->siblings = [
			self::position(1, Task::STATE_ENABLED),
			self::position(2, Task::STATE_AVAILABLE),
			self::position(3, Task::STATE_AVAILABLE),
		];

		$task = self::position(1, Task::STATE_COMPLETED, 'approved');
		switch ($scenario) {
			case 'outside':
				$task->setSequenceUuid('  ');
				break;
			case 'dangling':
				$this->stored = null;
				break;
			case 'terminal':
				$sequence->setStatus(TaskSequence::STATUS_REJECTED);
				break;
			case 'cancelled':
				$task = self::position(2, Task::STATE_DISABLED);
				break;
			case 'rejected-1':
				$task->setOutcome('rejected');
				break;
			case 'rejected-2':
				$task = self::position(2, Task::STATE_COMPLETED, 'rejected');
				break;
			case 'rejected-anon':
				$task->setOutcome('rejected');
				$task->setCompletedBy(null);
				break;
			case 'approved-1':
				break;
			case 'approved-skip':
				$this->siblings[1] = self::position(2, Task::STATE_TERMINATED);
				break;
			case 'approved-last':
				$task = self::position(3, Task::STATE_COMPLETED, 'done');
				break;
			case 'approved-declared':
				$task = self::position(2, Task::STATE_COMPLETED, 'approved');
				$this->siblings = [self::position(1, Task::STATE_COMPLETED), self::position(2, Task::STATE_COMPLETED)];
				break;
			case 'approved-tier':
				$sequence->setResolvedTier(['positions' => [['order' => 1, 'statusOnApprove' => ' tiered ']]]);
				$this->siblings = [self::position(1, Task::STATE_COMPLETED)];
				break;
			case 'undecided':
				$task->setOutcome('maybe');
				break;
			case 'declared-set':
				$sequence->setTemplateSnapshot(self::template() + ['approvingOutcomes' => ['ok']]);
				$task->setOutcome('ok');
				break;
			case 'terminate-fails':
				$task->setOutcome('rejected');
				$this->failing = ['task-2'];
				break;
		}

		$service->onTaskTerminal(task: $task);

		$this->assertGolden('terminal:' . $this->dataName(), ['calls' => $this->calls, 'sequence' => self::row($sequence)]);
	}

	/**
	 * @param string $name The recording's name.
	 * @param array<string, mixed> $actual The observed outcome.
	 *
	 * @return void
	 */
	private function assertGolden(string $name, array $actual): void {
		$actual = json_decode((string)json_encode($actual), true);
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}
}
