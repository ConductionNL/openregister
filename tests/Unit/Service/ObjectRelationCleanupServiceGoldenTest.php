<?php

/**
 * ObjectRelationCleanupServiceGoldenTest
 *
 * Pins what the relation cleanup destroys and leaves standing, and what it
 * reports, so a restructuring of ObjectRelationCleanupService cannot change
 * which rows of a deleted object go: destroyTimelineLinks() never touches
 * notes or tasks, cleanup() touches all six, a failing source neither stops
 * the others nor inflates a count, and every log line stays as it was.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Notifications\Service\EmailService;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\DeckCardService;
use OCA\OpenRegister\Service\ObjectRelationCleanupLinks;
use OCA\OpenRegister\Service\ObjectRelationCleanupService;
use OCA\OpenRegister\Service\TaskService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use ReflectionNamedType;
use Stringable;

/**
 * Golden outcomes of the relation cleanup.
 */
class ObjectRelationCleanupServiceGoldenTest extends TestCase {

	/**
	 * Every call on an entity service, as "service.method(args)".
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Every log line, as "level: message".
	 *
	 * @var array<int, string>
	 */
	private array $lines = [];

	/**
	 * Methods that throw, by "service.method".
	 *
	 * @var array<string, bool>
	 */
	private array $failing = [];

	/**
	 * A recording double of one entity service.
	 *
	 * @param string               $class   The class.
	 * @param string               $label   The label in $calls.
	 * @param array<string, mixed> $returns Return value per method.
	 *
	 * @return MockObject The double.
	 */
	private function recorder(string $class, string $label, array $returns): MockObject {
		$double = $this->createMock($class);
		foreach ($returns as $method => $value) {
			$double->method($method)->willReturnCallback(function (...$args) use ($label, $method, $value) {
				$this->calls[] = $label . '.' . $method . '(' . implode(',', $args) . ')';
				if (isset($this->failing[$label . '.' . $method]) === true) {
					throw new Exception($label . ' ' . $method . ' broke');
				}

				return $value;
			});
		}

		return $double;
	}//end recorder()

	/**
	 * Build the service under test on recording doubles.
	 *
	 * @param array<int, array<string, mixed>> $tasks The tasks the task service lists.
	 *
	 * @return ObjectRelationCleanupService The service.
	 */
	private function build(array $tasks = []): ObjectRelationCleanupService {
		$logger = new class($this->lines) extends AbstractLogger {
			/**
			 * @param array<int, string> $lines The sink.
			 */
			public function __construct(private array &$lines) {
			}

			/**
			 * @param mixed              $level   Level.
			 * @param string|Stringable  $message Message.
			 * @param array<mixed>       $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->lines[] = $level . ': ' . $message;
			}
		};

		return new ObjectRelationCleanupService(
			$this->recorder(NoteService::class, 'notes', ['deleteNotesForObject' => null]),
			$this->recorder(TaskService::class, 'tasks', ['getTasksForObject' => $tasks, 'deleteTask' => null]),
			new ObjectRelationCleanupLinks(
				$this->recorder(EmailService::class, 'emails', ['deleteLinksForObject' => 2, 'getEmailsForObject' => [1, 2]]),
				$this->recorder(CalendarEventService::class, 'events', ['unlinkEventsForObject' => null, 'getEventsForObject' => [1]]),
				$this->recorder(ContactService::class, 'contacts', ['deleteLinksForObject' => null, 'getContactsForObject' => [1, 2, 3]]),
				$this->recorder(DeckCardService::class, 'deck', ['deleteLinksForObject' => 0, 'getCardsForObject' => [1, 2, 3, 4]])
			),
			$logger
		);
	}//end build()

	/**
	 * cleanup() destroys all six relation kinds, in order, with exact log lines.
	 *
	 * @return void
	 */
	public function testCleanupDestroysAllSixKinds(): void {
		$this->build([['calendarId' => 'c1', 'id' => 't1'], ['calendarId' => 'c2', 'id' => 't2']])->cleanup('u1');

		$this->assertSame(
			[
				'notes.deleteNotesForObject(u1)',
				'tasks.getTasksForObject(u1)',
				'tasks.deleteTask(c1,t1)',
				'tasks.deleteTask(c2,t2)',
				'emails.deleteLinksForObject(u1)',
				'events.unlinkEventsForObject(u1)',
				'contacts.deleteLinksForObject(u1)',
				'deck.deleteLinksForObject(u1)',
			],
			$this->calls
		);
		$this->assertSame(
			[
				'info: Cleaned up notes for deleted object: u1',
				'info: Cleaned up 2 task(s) for deleted object: u1',
				'info: Cleaned up 2 email link(s) for deleted object: u1',
				'info: Unlinked calendar events for deleted object: u1',
				'info: Cleaned up contact links for deleted object: u1',
			],
			$this->lines
		);
	}//end testCleanupDestroysAllSixKinds()

	/**
	 * Every source failing: each is still attempted, and each failure is logged as a warning.
	 *
	 * @return void
	 */
	public function testEveryFailureIsContainedAndLogged(): void {
		$this->failing = [
			'notes.deleteNotesForObject' => true, 'tasks.deleteTask' => true, 'emails.deleteLinksForObject' => true,
			'events.unlinkEventsForObject' => true, 'contacts.deleteLinksForObject' => true, 'deck.deleteLinksForObject' => true,
		];
		$this->build([['calendarId' => 'c1', 'id' => 't1']])->cleanup('u2');

		$this->assertCount(7, $this->calls);
		$this->assertSame(
			[
				'warning: Failed to clean up notes for deleted object: u2: notes deleteNotesForObject broke',
				'warning: Failed to delete task t1 for object u2: tasks deleteTask broke',
				'info: Cleaned up 1 task(s) for deleted object: u2',
				'warning: Failed to clean up email links for deleted object: u2: emails deleteLinksForObject broke',
				'warning: Failed to unlink calendar events for deleted object: u2: events unlinkEventsForObject broke',
				'warning: Failed to clean up contact links for deleted object: u2: contacts deleteLinksForObject broke',
				'warning: Failed to clean up deck links for deleted object: u2: deck deleteLinksForObject broke',
			],
			$this->lines
		);
	}//end testEveryFailureIsContainedAndLogged()

	/**
	 * A failing task listing deletes no task and is logged once.
	 *
	 * @return void
	 */
	public function testFailingTaskListing(): void {
		$this->failing = ['tasks.getTasksForObject' => true];
		$this->build([['calendarId' => 'c1', 'id' => 't1']])->cleanup('u3');

		$this->assertNotContains('tasks.deleteTask(c1,t1)', $this->calls);
		$this->assertContains('warning: Failed to clean up tasks for deleted object: u3: tasks getTasksForObject broke', $this->lines);
	}//end testFailingTaskListing()

	/**
	 * destroyTimelineLinks() leaves notes and tasks standing.
	 *
	 * @return void
	 */
	public function testDestroyTimelineLinksLeavesNotesAndTasks(): void {
		$this->build([['calendarId' => 'c1', 'id' => 't1']])->destroyTimelineLinks('u4');

		$this->assertSame(
			[
				'emails.deleteLinksForObject(u4)',
				'events.unlinkEventsForObject(u4)',
				'contacts.deleteLinksForObject(u4)',
				'deck.deleteLinksForObject(u4)',
			],
			$this->calls
		);
	}//end testDestroyTimelineLinksLeavesNotesAndTasks()

	/**
	 * The count sums the four link sources; a failing one contributes nothing and is logged.
	 *
	 * @return void
	 */
	public function testCountTimelineLinks(): void {
		$service = $this->build();
		$this->assertSame(10, $service->countTimelineLinks('u5'));

		$this->failing = ['contacts.getContactsForObject' => true];
		$this->lines = [];
		$this->assertSame(7, $service->countTimelineLinks('u5'));
		$this->assertSame(['warning: Could not count contacts for object u5: contacts getContactsForObject broke'], $this->lines);
		$this->assertNotContains('notes.deleteNotesForObject(u5)', $this->calls);
	}//end testCountTimelineLinks()

	/**
	 * The container autowires the service and its bundle: every constructor
	 * parameter is a class or interface, none scalar, none untyped.
	 *
	 * @return void
	 */
	public function testConstructorsAreAutowirable(): void {
		foreach ([ObjectRelationCleanupService::class, ObjectRelationCleanupLinks::class] as $class) {
			foreach ((new ReflectionMethod($class, '__construct'))->getParameters() as $parameter) {
				$type = $parameter->getType();
				$this->assertInstanceOf(ReflectionNamedType::class, $type, $class . '::$' . $parameter->getName());
				$this->assertFalse($type->isBuiltin(), $class . '::$' . $parameter->getName());
				$this->assertTrue(class_exists($type->getName()) || interface_exists($type->getName()), $type->getName());
			}
		}
	}//end testConstructorsAreAutowirable()
}//end class
