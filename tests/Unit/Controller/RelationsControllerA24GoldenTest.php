<?php

/**
 * Golden outcomes of the unified relations route.
 *
 * Pins, before a restructure, the full body of an allowed read (key order,
 * envelope shapes, timeline order) and every refusal the object read answers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\RelationsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Notifications\Service\EmailService;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\DeckCardService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TalkLinkService;
use OCA\OpenRegister\Service\TalkLinkServiceInterface;
use OCA\OpenRegister\Service\TaskService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\RelationsControllerFactory;
use OCA\OpenRegister\Tests\Unit\Support\RegistersContainerServices;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Controller\RelationsController
 */
class RelationsControllerA24GoldenTest extends TestCase {
	use RegistersContainerServices;

	/**
	 * The request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The object read double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The note double.
	 *
	 * @var NoteService&MockObject
	 */
	private NoteService&MockObject $notes;

	/**
	 * The task double.
	 *
	 * @var TaskService&MockObject
	 */
	private TaskService&MockObject $tasks;

	/**
	 * The mail double.
	 *
	 * @var EmailService&MockObject
	 */
	private EmailService&MockObject $emails;

	/**
	 * The calendar double.
	 *
	 * @var CalendarEventService&MockObject
	 */
	private CalendarEventService&MockObject $events;

	/**
	 * The contact double.
	 *
	 * @var ContactService&MockObject
	 */
	private ContactService&MockObject $contacts;

	/**
	 * The deck double.
	 *
	 * @var DeckCardService&MockObject
	 */
	private DeckCardService&MockObject $deck;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The controller under test.
	 *
	 * @var RelationsController
	 */
	private RelationsController $controller;

	/**
	 * Build the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->notes = $this->createMock(NoteService::class);
		$this->tasks = $this->createMock(TaskService::class);
		$this->emails = $this->createMock(EmailService::class);
		$this->events = $this->createMock(CalendarEventService::class);
		$this->contacts = $this->createMock(ContactService::class);
		$this->deck = $this->createMock(DeckCardService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = RelationsControllerFactory::create(
			'openregister',
			$this->request,
			$this->objects,
			$this->notes,
			$this->tasks,
			$this->emails,
			$this->events,
			$this->contacts,
			$this->deck,
			$this->logger,
			$this->containerMock()
		);
	}//end setUp()

	/**
	 * The read resolves the object through the register, schema and id named.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return void
	 */
	private function readable(string $uuid): void {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$this->objects->expects($this->once())->method('setRegister')->with('reg');
		$this->objects->expects($this->once())->method('setSchema')->with('sch');
		$this->objects->expects($this->once())->method('setObject')->with('id-1');
		$this->objects->method('getObject')->willReturn($object);
	}//end readable()

	/**
	 * Every core type answering, in the documented shapes.
	 *
	 * @return void
	 */
	private function allCoreTypesAnswer(): void {
		$this->notes->method('getNotesForObject')->with('u-1')->willReturn([['id' => 'n', 'createdAt' => '2']]);
		$this->tasks->method('getTasksForObject')->willReturn([['id' => 't', 'dtstart' => '4']]);
		$this->emails->method('isMailAvailable')->willReturn(true);
		$this->emails->method('getEmailsForObject')->willReturn(['results' => [['id' => 'e', 'date' => '1']], 'total' => 1]);
		$this->events->method('getEventsForObject')->willReturn([['id' => 'v']]);
		$this->contacts->method('getContactsForObject')->willReturn(['results' => [['id' => 'c', 'linkedAt' => '3']], 'total' => 1]);
		$this->deck->method('isDeckAvailable')->willReturn(true);
		$this->deck->method('getCardsForObject')->willReturn(['results' => [['id' => 'd', 'created' => '5']], 'total' => 1]);
	}//end allCoreTypesAnswer()

	/**
	 * The allowed read: every type in order, leaves absent from the container skipped.
	 *
	 * @return void
	 */
	public function testTheFullBodyInOrder(): void {
		$this->readable(uuid: 'u-1');
		$this->request->method('getParams')->willReturn([]);
		$this->allCoreTypesAnswer();

		$response = $this->controller->index('reg', 'sch', 'id-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				'notes' => ['results' => [['id' => 'n', 'createdAt' => '2']], 'total' => 1],
				'tasks' => ['results' => [['id' => 't', 'dtstart' => '4']], 'total' => 1],
				'emails' => ['results' => [['id' => 'e', 'date' => '1']], 'total' => 1],
				'events' => ['results' => [['id' => 'v']], 'total' => 1],
				'contacts' => ['results' => [['id' => 'c', 'linkedAt' => '3']], 'total' => 1],
				'deck' => ['results' => [['id' => 'd', 'created' => '5']], 'total' => 1],
			],
			$response->getData()
		);
	}//end testTheFullBodyInOrder()

	/**
	 * The timeline is flat, singular-typed and newest first, an undated item last.
	 *
	 * @return void
	 */
	public function testTheTimelineOrder(): void {
		$this->readable(uuid: 'u-1');
		$this->request->method('getParams')->willReturn(['view' => 'timeline', 'types' => 'notes, tasks,emails,events,contacts,deck']);
		$this->allCoreTypesAnswer();

		$this->assertSame(
			[
				['id' => 'd', 'created' => '5', 'type' => 'deck'],
				['id' => 't', 'dtstart' => '4', 'type' => 'task'],
				['id' => 'c', 'linkedAt' => '3', 'type' => 'contact'],
				['id' => 'n', 'createdAt' => '2', 'type' => 'note'],
				['id' => 'e', 'date' => '1', 'type' => 'email'],
				['id' => 'v', 'type' => 'event'],
			],
			$this->controller->index('reg', 'sch', 'id-1')->getData()
		);
	}//end testTheTimelineOrder()

	/**
	 * A failing leaf is a warning and an `_errors` entry; an unresolvable one is skipped.
	 *
	 * @return void
	 */
	public function testLeafFailuresAndAbsentLeaves(): void {
		$this->readable(uuid: 'u-1');
		$this->request->method('getParams')->willReturn(['types' => 'talk,forms,unknown']);
		$talk = $this->createMock(TalkLinkService::class);
		$talk->method('isTalkAvailable')->willThrowException(new RuntimeException('talk down'));
		$this->registerService(TalkLinkServiceInterface::class, fn () => $talk);
		$this->logger->expects($this->once())->method('warning')->with(
			'[RelationsController::gatherRelations] {type} lookup failed for object {uuid}: {error}',
			['type' => 'talk', 'uuid' => 'u-1', 'error' => 'talk down']
		);

		$this->assertSame(
			['_errors' => ['talk' => ['message' => 'talk down', 'exception' => RuntimeException::class]]],
			$this->controller->index('reg', 'sch', 'id-1')->getData()
		);
	}//end testLeafFailuresAndAbsentLeaves()

	/**
	 * A failing core type is an error log and an `_errors` entry after the others.
	 *
	 * @return void
	 */
	public function testACoreFailureComesLast(): void {
		$this->readable(uuid: 'u-1');
		$this->request->method('getParams')->willReturn(['types' => 'tasks,events']);
		$this->tasks->method('getTasksForObject')->willThrowException(new RuntimeException('caldav'));
		$this->events->method('getEventsForObject')->willReturn([]);
		$this->logger->expects($this->once())->method('error');

		$this->assertSame(
			[
				'events' => ['results' => [], 'total' => 0],
				'_errors' => ['tasks' => ['message' => 'caldav', 'exception' => RuntimeException::class]],
			],
			$this->controller->index('reg', 'sch', 'id-1')->getData()
		);
	}//end testACoreFailureComesLast()

	/**
	 * The refusals: no object, unknown object, ambiguous slug, failing read.
	 *
	 * @return void
	 */
	public function testTheRefusals(): void {
		$this->objects->method('getObject')->willReturnOnConsecutiveCalls(
			null,
			$this->throwException(new DoesNotExistException('gone')),
			$this->throwException(new MultipleObjectsReturnedException('two')),
			$this->throwException(new RuntimeException('db down'))
		);
		$this->notes->expects($this->never())->method('getNotesForObject');

		$outcomes = [];
		foreach (range(1, 4) as $call) {
			$response = $this->controller->index('reg', 'sch', 'slug-'.$call);
			$outcomes[] = [$response->getStatus(), $response->getData()];
		}

		$this->assertSame(
			[
				[404, ['error' => 'Object not found']],
				[404, ['error' => 'Object not found']],
				[409, ['message' => 'Identifier "slug-3" matches more than one object in this register and schema', 'error' => 'ambiguous-identifier']],
				[500, ['error' => 'db down']],
			],
			$outcomes
		);
	}//end testTheRefusals()
}//end class
