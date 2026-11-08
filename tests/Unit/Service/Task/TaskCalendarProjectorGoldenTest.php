<?php

/**
 * Golden test for TaskCalendarProjector.
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
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use DateTime;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskProjectionState;
use OCA\OpenRegister\Db\TaskProjectionStateMapper;
use OCA\OpenRegister\Exception\NoVtodoCalendarException;
use OCA\OpenRegister\Service\Task\TaskCalendarProjector;
use OCA\OpenRegister\Service\Task\TaskCalendarProjectorServices;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskVtodoRenderer;
use OCA\OpenRegister\Service\Task\VtodoCalendarLocator;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test pinning the projector's rendered bytes, the engine fields it
 * reads back, and the exact sequence of calendar, state and log calls each
 * sync scenario makes. Compared with fixtures/task-calendar-projector-golden.json;
 * set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * Clock-dependent parts are masked: the DTSTAMP line, and the timestamp in a
 * fresh retry uri. Calendar documents in the call log are recorded by their
 * content hash.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskCalendarProjector
 */
class TaskCalendarProjectorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/task-calendar-projector-golden.json';

	/**
	 * Every recorded call, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/** @var array<string, TaskProjectionState> */
	private array $rows = [];

	/** @var array<int, array<string, mixed>|null> Answers for getCalendarObject(), in order. */
	private array $objects = [];

	/** @var array<int, bool> Whether createCalendarObject() fails, per call. */
	private array $createFails = [];

	/** @var bool Whether deleteCalendarObject() fails. */
	private bool $deleteFails = false;

	/** @var array<string, int|null> Calendar id per uid; null means no VTODO calendar. */
	private array $calendarIds = [];

	private function projector(): TaskCalendarProjector {
		$backend = $this->createMock(CalDavBackend::class);
		$backend->method('getCalendarObject')->willReturnCallback(
			function (int $calendarId, string $uri): ?array {
				$this->calls[] = ['getCalendarObject', $calendarId, self::mask($uri)];
				return array_shift($this->objects);
			}
		);
		$backend->method('createCalendarObject')->willReturnCallback(
			function (int $calendarId, string $uri, string $data): string {
				$this->calls[] = ['createCalendarObject', $calendarId, self::mask($uri), TaskCalendarProjector::contentHash($data)];
				if (array_shift($this->createFails) === true) {
					throw new RuntimeException('uri taken');
				}

				return 'etag';
			}
		);
		$backend->method('updateCalendarObject')->willReturnCallback(
			function (int $calendarId, string $uri, string $data): string {
				$this->calls[] = ['updateCalendarObject', $calendarId, self::mask($uri), TaskCalendarProjector::contentHash($data)];
				return 'etag';
			}
		);
		$backend->method('deleteCalendarObject')->willReturnCallback(
			function (int $calendarId, string $uri): void {
				$this->calls[] = ['deleteCalendarObject', $calendarId, self::mask($uri)];
				if ($this->deleteFails === true) {
					throw new RuntimeException('delete failed');
				}
			}
		);

		$calendars = $this->createMock(VtodoCalendarLocator::class);
		$calendars->method('forUser')->willReturnCallback(
			function (string $uid): array {
				$this->calls[] = ['forUser', $uid];
				$id = ($this->calendarIds[$uid] ?? null);
				if ($id === null) {
					throw new NoVtodoCalendarException('no calendar for ' . $uid);
				}

				return ['id' => $id, 'uri' => 'personal'];
			}
		);

		$states = $this->createMock(TaskProjectionStateMapper::class);
		$states->method('findForTask')->willReturnCallback(
			function (string $uuid): ?TaskProjectionState {
				$this->calls[] = ['findForTask', $uuid];
				return $this->rows[$uuid] ?? null;
			}
		);
		$states->method('save')->willReturnCallback(
			function (TaskProjectionState $state): TaskProjectionState {
				$this->calls[] = [
					'save',
					$state->getTaskUuid(),
					$state->getSurface(),
					$state->getAssignee(),
					$state->getCalendarId(),
					self::mask((string)$state->getObjectUri()),
					$state->getRenderedHash(),
				];
				$this->rows[(string)$state->getTaskUuid()] = $state;
				return $state;
			}
		);
		$states->method('delete')->willReturnCallback(
			function (TaskProjectionState $state): TaskProjectionState {
				$this->calls[] = ['delete', $state->getTaskUuid()];
				unset($this->rows[(string)$state->getTaskUuid()]);
				return $state;
			}
		);

		$inbox = $this->createMock(TaskInboxService::class);
		$inbox->method('displayTitle')->willReturnCallback(
			static fn (Task $task): string => (string)($task->getTitle() ?? 'Task')
		);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params): string => 'https://cloud.example/' . $route . '/' . $params['uuid']
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->calls[] = ['log:' . $level, $message, $context];
				}
			);
		}

		return new TaskCalendarProjector(
			new TaskCalendarProjectorServices($backend, $calendars, $states, $logger),
			new TaskVtodoRenderer($inbox, $urls)
		);
	}

	private static function mask(string $text): string {
		return (string)preg_replace('/-\d{9,}\.ics$/', '-<time>.ics', $text);
	}

	private static function task(string $variant = 'assigned'): Task {
		$task = TaskCalendarProjectorTest::assignedTask();
		if ($variant === 'completed') {
			$task->setState(Task::STATE_COMPLETED);
			$task->setIsTerminal(true);
			$task->setCompletedAt(new DateTime('2026-09-01T09:00:00+00:00'));
		}

		if ($variant === 'minimal') {
			$task->setDescription('   ');
			$task->setDueAt(null);
			$task->setObjectUuid(null);
			$task->setPriority(null);
		}

		if ($variant === 'no-schema') {
			$task->setSchemaId(null);
		}

		if ($variant === 'pooled') {
			$task->setPerformerType(Task::PERFORMER_GROUP);
		}

		if ($variant === 'blank-assignee') {
			$task->setAssignee('  ');
		}

		if ($variant === 'reassigned') {
			$task->setAssignee('second-approver');
		}

		if ($variant === 'terminated') {
			$task->setState(Task::STATE_TERMINATED);
			$task->setIsTerminal(true);
		}

		return $task;
	}

	public function testRenderAndReadBackMatchGolden(): void {
		$projector = $this->projector();
		$actual = [];
		foreach (['assigned', 'completed', 'minimal', 'no-schema'] as $variant) {
			$ics = $projector->render(self::task($variant));
			$actual['render:' . $variant] = (string)preg_replace('/DTSTAMP:\d{8}T\d{6}Z/', 'DTSTAMP:<now>', $ics);
			$actual['fields:' . $variant] = TaskCalendarProjector::engineFields($ics);
			$actual['hash:' . $variant] = TaskCalendarProjector::contentHash($ics);
			$actual['uuid:' . $variant] = TaskCalendarProjector::taskUuidOf($ics);
		}

		$documents = [
			'garbage' => 'not a calendar',
			'vcard' => "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Someone\r\nEND:VCARD\r\n",
			'no-vtodo' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:e\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
			'standalone' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:abc\r\n"
				. "SUMMARY:Buy milk\r\nstatus: needs-action \r\nEND:VTODO\r\n"
				. "END:VCALENDAR\r\n",
			'bad-due' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:abc\r\n"
				. "X-OPENREGISTER-TASK: t-1 \r\nDUE;VALUE=TEXT:soon\r\nEND:VTODO\r\n"
				. "END:VCALENDAR\r\n",
			'date-due' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:abc\r\n"
				. "X-OPENREGISTER-TASK:t-2\r\nDUE:20260910T120000Z\r\nPRIORITY:1\r\n"
				. "END:VTODO\r\nEND:VCALENDAR\r\n",
		];
		foreach ($documents as $name => $document) {
			$actual['fields:' . $name] = TaskCalendarProjector::engineFields($document);
			$actual['hash:' . $name] = TaskCalendarProjector::contentHash($document);
			$actual['uuid:' . $name] = TaskCalendarProjector::taskUuidOf($document);
		}

		$this->assertGolden('render', $actual);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function scenarios(): array {
		return [
			'first projection' => ['first'],
			'unchanged re-projection' => ['unchanged'],
			'changed task' => ['changed'],
			'reassignment' => ['reassign'],
			'reconcile after deletion' => ['reconcile-gone'],
			'reconcile after edit' => ['reconcile-edited'],
			'reconcile untouched' => ['reconcile-untouched'],
			'calendar moved' => ['moved'],
			'pooled after projection' => ['pooled-after'],
			'pooled never projected' => ['pooled-never'],
			'pooled terminal never projected' => ['pooled-terminal'],
			'blank assignee' => ['blank'],
			'no calendar' => ['no-calendar'],
			'create retries' => ['retry'],
			'remove' => ['remove'],
			'remove unknown' => ['remove-unknown'],
			'remove when delete fails' => ['remove-fails'],
			'remove when object gone' => ['remove-gone'],
			'terminal never projected' => ['terminal-never'],
			'terminal after projection' => ['terminal-after'],
			'echo' => ['echo'],
		];
	}

	#[DataProvider('scenarios')]
	public function testSyncMatchesGolden(string $scenario): void {
		$projector = $this->projector();
		$this->calendarIds = [TaskCalendarProjectorTest::APPROVER => 9, 'second-approver' => 21];
		$uuid = TaskCalendarProjectorTest::PROJECTED_UUID;
		$result = null;

		$projector->project(self::task(), null);
		$this->calls = [];
		$rendered = $projector->render(self::task());

		switch ($scenario) {
			case 'first':
				$this->rows = [];
				$projector->project(self::task(), null);
				break;
			case 'unchanged':
				$projector->project(self::task(), TaskCalendarProjectorTest::APPROVER);
				break;
			case 'changed':
				$this->objects = [['calendardata' => $rendered]];
				$projector->project(self::task('completed'), TaskCalendarProjectorTest::APPROVER);
				break;
			case 'reassign':
				$this->objects = [['calendardata' => $rendered]];
				$projector->project(self::task('reassigned'), TaskCalendarProjectorTest::APPROVER);
				break;
			case 'reconcile-gone':
				$this->objects = [null];
				$projector->reconcile(self::task());
				break;
			case 'reconcile-edited':
				$this->objects = [['calendardata' => str_replace('SUMMARY:Approve the permit', 'SUMMARY:Other', $rendered)]];
				$projector->reconcile(self::task());
				break;
			case 'reconcile-untouched':
				$this->objects = [['calendardata' => $rendered]];
				$projector->reconcile(self::task());
				break;
			case 'moved':
				$this->calendarIds[TaskCalendarProjectorTest::APPROVER] = 10;
				$this->objects = [['calendardata' => $rendered]];
				$projector->project(self::task(), TaskCalendarProjectorTest::APPROVER);
				break;
			case 'pooled-after':
				$this->objects = [['calendardata' => $rendered]];
				$projector->project(self::task('pooled'), TaskCalendarProjectorTest::APPROVER);
				break;
			case 'pooled-never':
				$this->rows = [];
				$projector->project(self::task('pooled'), null);
				break;
			case 'pooled-terminal':
				$this->rows = [];
				$task = self::task('pooled');
				$task->setState(Task::STATE_TERMINATED);
				$task->setIsTerminal(true);
				$projector->project($task, null);
				break;
			case 'blank':
				$this->objects = [null];
				$projector->project(self::task('blank-assignee'), null);
				break;
			case 'no-calendar':
				$this->calendarIds = [];
				$projector->project(self::task('reassigned'), null);
				break;
			case 'retry':
				$this->rows = [];
				$this->createFails = [true, false];
				$projector->project(self::task(), null);
				break;
			case 'remove':
				$this->objects = [['calendardata' => $rendered]];
				$projector->remove($uuid);
				break;
			case 'remove-unknown':
				$projector->remove('unknown');
				break;
			case 'remove-fails':
				$this->objects = [['calendardata' => $rendered]];
				$this->deleteFails = true;
				$projector->remove($uuid);
				break;
			case 'remove-gone':
				$this->objects = [null];
				$projector->remove($uuid);
				break;
			case 'terminal-never':
				$this->rows = [];
				$projector->project(self::task('terminated'), null);
				break;
			case 'terminal-after':
				$this->objects = [['calendardata' => $rendered]];
				$projector->project(self::task('terminated'), TaskCalendarProjectorTest::APPROVER);
				break;
			case 'echo':
				$result = [
					$projector->isEcho($uuid, $rendered),
					$projector->isEcho($uuid, str_replace('STATUS:IN-PROCESS', 'STATUS:COMPLETED', $rendered)),
					$projector->isEcho('unknown', $rendered),
				];
				break;
		}

		$this->assertGolden($this->dataName(), ['calls' => $this->calls, 'result' => $result, 'rows' => array_keys($this->rows)]);
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
