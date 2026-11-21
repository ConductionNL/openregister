<?php

/**
 * CalendarEventsControllerListEventsNotFoundTest
 *
 * Listing the events of a calendar the user does not have answers 404, and
 * any other failure still answers 500.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/nextcloud-entity-relations/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\CalendarEventsController;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\CalendarLinkService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\CalendarEventsControllerFactory;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \OCA\OpenRegister\Controller\CalendarEventsController
 */
class CalendarEventsControllerListEventsNotFoundTest extends TestCase {

	/**
	 * The calendar link service the listing reads through.
	 *
	 * @var CalendarLinkService&MockObject
	 */
	private CalendarLinkService&MockObject $calendarLinkService;

	/**
	 * The controller under test.
	 *
	 * @var CalendarEventsController
	 */
	private CalendarEventsController $controller;

	/**
	 * Build the controller around a mocked link service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calendarLinkService = $this->createMock(CalendarLinkService::class);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['after' => '2026-01-01T00:00:00+00:00']);

		$this->controller = CalendarEventsControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(CalendarEventService::class),
			$this->calendarLinkService,
			$this->createMock(ObjectService::class),
			$this->createMock(PermissionHandler::class),
		);
	}//end setUp()

	/**
	 * An unknown calendar (the service's 404-coded refusal) is a 404.
	 *
	 * @return void
	 */
	public function testUnknownCalendarIsNotFound(): void {
		$this->calendarLinkService->method('getEventsForCalendar')
			->willThrowException(new Exception('Calendar nope not found for user', 404));

		$response = $this->controller->listCalendarEvents('nope');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Calendar nope not found for user'], $response->getData());
	}//end testUnknownCalendarIsNotFound()

	/**
	 * Any other failure is still a 500.
	 *
	 * @return void
	 */
	public function testOtherFailureIsServerError(): void {
		$this->calendarLinkService->method('getEventsForCalendar')
			->willThrowException(new Exception('CalDAV backend down'));

		$response = $this->controller->listCalendarEvents('personal');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'CalDAV backend down'], $response->getData());
	}//end testOtherFailureIsServerError()
}//end class
