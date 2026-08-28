<?php

/**
 * Unit tests for CalendarEventsController Tier-2 endpoints.
 *
 * Covers:
 *  - POST  /events/link            → CalendarLinkService::linkEvent
 *  - DELETE /events/{uid}/link     → CalendarLinkService::unlinkEvent
 *  - GET   /integrations/calendar/calendars
 *  - GET   /integrations/calendar/calendars/{uri}/events
 *  - POST  /events  (createAndLinkEvent — link mirror)
 *  - DELETE /events/{eventId} (destroy — legacy + link cleanup)
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\CalendarEventsController;
use OCA\OpenRegister\Db\CalendarLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\CalendarLinkService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class CalendarEventsControllerTest extends TestCase {
	private CalendarEventService&MockObject $calendarEventService;
	private CalendarLinkService&MockObject $calendarLinkService;
	private ObjectService&MockObject $objectService;
	private IRequest&MockObject $request;
	private CalendarEventsController $controller;

	/**
	 * Test object UUID.
	 *
	 * @var string
	 */
	private const OBJ_UUID = 'obj-uuid-1';

	protected function setUp(): void {
		$this->calendarEventService = $this->createMock(CalendarEventService::class);
		$this->calendarLinkService = $this->createMock(CalendarLinkService::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->request = $this->createMock(IRequest::class);

		$this->controller = new CalendarEventsController(
			'openregister',
			$this->request,
			$this->calendarEventService,
			$this->calendarLinkService,
			$this->objectService,
		);

		// ObjectEntity uses __call magic via the Nextcloud Entity base
		// class, so PHPUnit's mock generator can't stub setters. Use a
		// real entity with concrete values instead.
		$object = new ObjectEntity();
		$object->setUuid(self::OBJ_UUID);
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setName('Object title');

		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setObject')->willReturnSelf();
		$this->objectService->method('getObject')->willReturn($object);
	}

	private function buildLink(): CalendarLink {
		$link = new CalendarLink();
		$link->setObjectUuid(self::OBJ_UUID);
		$link->setEventUid('ev-uid-1');
		$link->setEventUri('event.ics');
		$link->setCalendarUri('personal');
		$link->setCalendarId(7);
		$link->setSummary('Kickoff');
		return $link;
	}

	public function testLinkRequiresPayload(): void {
		$this->request->method('getParams')->willReturn([]);
		$response = $this->controller->link('r', 's', 'id');
		$this->assertSame(400, $response->getStatus());
	}

	public function testLinkDelegatesToService(): void {
		$this->request->method('getParams')->willReturn([
			'calendarUri' => 'personal',
			'eventUid' => 'ev-uid-1',
		]);
		$this->calendarLinkService->expects($this->once())
			->method('linkEvent')
			->with(self::OBJ_UUID, 1, 2, 'personal', 'ev-uid-1')
			->willReturn($this->buildLink());

		$response = $this->controller->link('r', 's', 'id');
		$this->assertSame(201, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('ev-uid-1', $data['uid']);
	}

	public function testUnlinkDelegatesToService(): void {
		$this->calendarLinkService->expects($this->once())
			->method('unlinkEvent')
			->with(self::OBJ_UUID, 'ev-uid-1');

		$response = $this->controller->unlink('r', 's', 'id', 'ev-uid-1');
		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testCreateMirrorsViaLinkService(): void {
		$this->request->method('getParams')->willReturn(['summary' => 'New meeting']);
		$this->calendarLinkService->expects($this->once())
			->method('createAndLinkEvent')
			->with(self::OBJ_UUID, 1, 2, $this->callback(function ($data) {
				$this->assertSame('New meeting', $data['summary']);
				$this->assertSame('Object title', $data['objectTitle']);
				return true;
			}))
			->willReturn($this->buildLink());

		$response = $this->controller->create('r', 's', 'id');
		$this->assertSame(201, $response->getStatus());
	}

	public function testListCalendarsDelegates(): void {
		$this->calendarLinkService->expects($this->once())
			->method('getAvailableCalendars')
			->willReturn([
				['id' => 7, 'uri' => 'personal', 'displayName' => 'Personal', 'color' => null],
			]);

		$response = $this->controller->listCalendars();
		$this->assertSame(200, $response->getStatus());
		$this->assertCount(1, $response->getData()['results']);
	}

	public function testListCalendarEventsHonoursDefaults(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->calendarLinkService->expects($this->once())
			->method('getEventsForCalendar')
			->with('personal', 100, $this->isInstanceOf(\DateTimeInterface::class))
			->willReturn([
				['uid' => 'ev-1', 'summary' => 'S', 'calendarUri' => 'personal'],
			]);

		$response = $this->controller->listCalendarEvents('personal');
		$this->assertSame(200, $response->getStatus());
		$this->assertCount(1, $response->getData()['results']);
	}

	public function testDestroyCallsLegacyServiceAndCleansLink(): void {
		$this->calendarLinkService->method('getLinkedEvents')->willReturn([
			['id' => 'event.ics', 'uid' => 'ev-uid-1', 'calendarId' => '7'],
		]);
		$this->calendarEventService->expects($this->once())
			->method('unlinkEvent')
			->with('7', 'event.ics');
		$this->calendarLinkService->expects($this->once())
			->method('unlinkEvent')
			->with(self::OBJ_UUID, 'ev-uid-1');

		$response = $this->controller->destroy('r', 's', 'id', 'event.ics');
		$this->assertSame(200, $response->getStatus());
	}
}
