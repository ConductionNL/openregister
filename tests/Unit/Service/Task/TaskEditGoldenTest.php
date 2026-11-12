<?php

/**
 * Golden test for TaskEdit.
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
 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-s-own-fields-can-be-edited-by-the-people-working-it
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Task\TaskEdit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for TaskEdit::apply().
 *
 * Each edit is applied to a task with fixed fields, once without and once
 * with an expiry. The changed fields and the task's resulting fields, or the
 * refusal, are compared with fixtures/task-edit-golden.json. Every date is a
 * fixed one, read in UTC, so the recording never depends on today. Set
 * UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskEdit
 * @uses   \OCA\OpenRegister\Service\Task\TaskPriority
 * @uses   \OCA\OpenRegister\Db\Task
 * @uses   \OCA\OpenRegister\Exception\TaskValidationException
 */
class TaskEditGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/task-edit-golden.json';

	/**
	 * The default timezone before the test, restored after it.
	 *
	 * @var string
	 */
	private string $timezone = 'UTC';

	protected function setUp(): void {
		$this->timezone = date_default_timezone_get();
		date_default_timezone_set('UTC');
	}//end setUp()

	protected function tearDown(): void {
		date_default_timezone_set($this->timezone);
	}//end tearDown()

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'nothing' => [[]],
			'unknown only' => [['status' => 'done']],
			'unknown with known' => [['title' => 'X', 'owner' => 'a', 'status' => 'b']],
			'numeric unknown' => [[0 => 'x']],
			'same title' => [['title' => ' Bestaand ']],
			'new title' => [['title' => 'Nieuw']],
			'blank title' => [['title' => '  ']],
			'null title' => [['title' => null]],
			'number title' => [['title' => 42]],
			'bool title' => [['title' => false]],
			'array title' => [['title' => ['x']]],
			'description cleared' => [['description' => '']],
			'description null' => [['description' => null]],
			'description object' => [['description' => new \stdClass()]],
			'description same' => [['description' => 'Oud']],
			'priority same' => [['priority' => 'NORMAL']],
			'priority alias' => [['priority' => 'critical']],
			'priority ical' => [['priority' => '1']],
			'priority bad' => [['priority' => 'asap']],
			'priority out of range' => [['priority' => 12]],
			'due cleared' => [['dueAt' => '']],
			'due null' => [['dueAt' => null]],
			'due same' => [['dueAt' => '2026-03-01T12:00:00+00:00']],
			'due date' => [['dueAt' => '2026-04-01']],
			'due with zone' => [['dueAt' => '2026-04-01T08:30:00+02:00']],
			'due on expiry' => [['dueAt' => '2026-06-30T00:00:00+00:00']],
			'due after expiry' => [['dueAt' => '2026-07-01']],
			'due unparseable prefix' => [['dueAt' => 'next week']],
			'due bad date' => [['dueAt' => '2026-99-99']],
			'due garbage after date' => [['dueAt' => '2026-04-01 nonsense']],
			'due number' => [['dueAt' => 20260401]],
			'due object' => [['dueAt' => new DateTime('2026-04-01T00:00:00+00:00')]],
			'everything' => [['priority' => 'low', 'dueAt' => '2026-05-05', 'description' => ' Nieuwe ', 'title' => 'Alles']],
			'first refusal wins' => [['dueAt' => 'x', 'priority' => 'asap', 'title' => '']],
		];
	}//end cases()

	/**
	 * Compare one edit with the recording.
	 *
	 * @param array<string, mixed> $changes The edit.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testMatchesGolden(array $changes): void {
		$name   = $this->dataName();
		$actual = [];
		foreach (['no expiry' => null, 'expiry' => '2026-06-30T00:00:00+00:00'] as $label => $expiry) {
			$task = new Task();
			$task->setTitle('Bestaand');
			$task->setDescription('Oud');
			$task->setPriority('normal');
			$task->setDueAt(new DateTime('2026-03-01T12:00:00+00:00'));
			if ($expiry !== null) {
				$task->setExpiresAt(new DateTime($expiry));
			}

			try {
				$changed        = (new TaskEdit())->apply($task, $changes);
				$actual[$label] = [
					'changed' => $changed,
					'title' => $task->getTitle(),
					'description' => $task->getDescription(),
					'priority' => $task->getPriority(),
					'dueAt' => $task->getDueAt()?->format('c'),
				];
			} catch (TaskValidationException $e) {
				$actual[$label] = ['refused' => $e->getMessage()];
			}
		}//end foreach

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
	}//end testMatchesGolden()
}//end class
