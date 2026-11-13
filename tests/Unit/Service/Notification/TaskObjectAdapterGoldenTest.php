<?php

/**
 * Golden characterization test for TaskObjectAdapter.
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

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Notifications\Service\Notification\TaskNotificationRules;
use OCA\OpenRegister\Notifications\Service\Notification\TaskObjectAdapter;
use OCA\OpenRegister\Notifications\Service\Notification\TaskPayloadOptions;
use PHPUnit\Framework\TestCase;

/**
 * Builds adapters and payloads for fixed tasks, rows and extras and compares
 * the adapter's uuid, schema, name and payload with
 * `fixtures/task-object-adapter-golden.json`. Set `OR_GOLDEN_WRITE=1` to
 * regenerate the fixture.
 *
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\TaskObjectAdapter
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\TaskPayloadOptions
 */
final class TaskObjectAdapterGoldenTest extends TestCase {

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	private static function goldenPath(): string {
		return __DIR__.'/fixtures/task-object-adapter-golden.json';
	}//end goldenPath()

	/**
	 * A task with every payload field set.
	 *
	 * @return Task
	 */
	private static function fullTask(): Task {
		$task = new Task();
		$task->setUuid('00000000-0000-0000-0000-0000000000aa');
		$task->setTitle('  Approve the permit  ');
		$task->setDescription('Check the drawings');
		$task->setState('active');
		$task->setIsTerminal(true);
		$task->setLastAction('assign');
		$task->setOutcome('approved');
		$task->setPriority('high');
		$task->setPerformerType('user');
		$task->setAssignee('approver');
		$task->setCandidateUsers(['u1', 'u2']);
		$task->setCandidateGroups(['permits']);
		$task->setCandidateRole('reviewer');
		$task->setRequester('clerk');
		$task->setWatchers(['w1']);
		$task->setStartAt(new DateTime('2026-09-01T08:00:00+00:00'));
		$task->setDueAt(new DateTime('2026-09-10T12:00:00+02:00'));
		$task->setExpiresAt(new DateTime('2026-10-01T00:00:00+00:00'));
		$task->setObjectUuid('obj-1');
		$task->setRegisterId(3);
		$task->setSchemaId(5);
		$task->setAppId('procest');
		$task->setRunUuid('run-1');
		$task->setCompletedBy('approver');
		return $task;
	}//end fullTask()

	/**
	 * A task with only a uuid.
	 *
	 * @param string $title The task title.
	 *
	 * @return Task
	 */
	private static function bareTask(string $title): Task {
		$task = new Task();
		$task->setUuid('00000000-0000-0000-0000-0000000000bb');
		$task->setTitle($title);
		return $task;
	}//end bareTask()

	/**
	 * The scenarios: name => [task, row, extra].
	 *
	 * @return array<string, array{0: Task, 1: array<string, mixed>, 2: array<mixed>}>
	 */
	private static function scenarios(): array {
		return [
			'full-with-row-and-extra' => [
				self::fullTask(),
				['displayTitle' => '  Assign: Permit 42 ', 'subject' => ['title' => 'Permit 42'], 'overdue' => 1, 'daysUntilDue' => -2, 'daysOverdue' => 2],
				['previousAssignee' => 'former', 'writeBackActor' => 'actor', 'writeBackReason' => 'why', 'title' => 'Overridden', 'unknown' => 'x', 7 => 'int-key'],
			],
			'full-no-row' => [self::fullTask(), [], []],
			'blank-display-title-uses-task-title' => [self::fullTask(), ['displayTitle' => '   ', 'subject' => 'not-an-array'], []],
			'subject-without-title' => [self::fullTask(), ['subject' => ['other' => 1]], []],
			'blank-titles-use-uuid' => [self::bareTask(title: '  '), ['displayTitle' => ''], []],
			'bare-task' => [self::bareTask(title: 'Bare'), [], ['overdue' => true, 'daysOverdue' => 9]],
		];
	}//end scenarios()

	/**
	 * Case names for the data provider.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (array_keys(self::scenarios()) as $name) {
			$cases[$name] = [$name];
		}

		return $cases;
	}//end cases()

	/**
	 * Each scenario builds the recorded adapter and payload.
	 *
	 * @param string $name The scenario name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testAdapterMatchesGolden(string $name): void {
		[$task, $row, $extra] = self::scenarios()[$name];
		$adapter = new TaskObjectAdapter($task, $row, $extra);
		$actual = [
			'uuid' => $adapter->getUuid(),
			'schema' => $adapter->getSchema(),
			'register' => $adapter->getRegister(),
			'name' => $adapter->getName(),
			'object' => (new \ReflectionProperty($adapter, 'object'))->getValue($adapter),
			'payload' => TaskObjectAdapter::payload($task, new TaskPayloadOptions($row, $extra)),
		];

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$golden = [];
			if (is_file(self::goldenPath()) === true) {
				$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::goldenPath(), json_encode($golden, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
			$this->markTestSkipped('golden written');
		}

		$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end testAdapterMatchesGolden()

	/**
	 * A bare adapter, as the Entity base class builds it, carries no payload.
	 *
	 * @return void
	 */
	public function testBareAdapter(): void {
		$adapter = new TaskObjectAdapter();
		$this->assertSame(TaskNotificationRules::SLUG, $adapter->getSchema());
		$this->assertNull($adapter->getUuid());
		$this->assertNull($adapter->getName());
		$this->assertSame([], (new \ReflectionProperty($adapter, 'object'))->getValue($adapter));
	}//end testBareAdapter()
}//end class
