<?php

/**
 * Unit tests for CalendarFeedController.
 *
 * The feed endpoint is public and session-less, so its refusals are the
 * interesting half: an unknown, revoked or expired token all get the same
 * 404 and a throttler attempt, and a live token answers text/calendar rather
 * than JSON.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\CalendarFeedController;
use OCA\OpenRegister\Db\CalendarFeedToken;
use OCA\OpenRegister\Service\Calendar\AppointmentAttendeeService;
use OCA\OpenRegister\Service\Calendar\CalendarFeedTokenService;
use OCA\OpenRegister\Service\Calendar\ObjectCalendarFeedService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CalendarFeedControllerTest extends TestCase {

	private CalendarFeedTokenService&MockObject $tokens;
	private ObjectCalendarFeedService&MockObject $feed;
	private AppointmentAttendeeService&MockObject $attendees;
	private IThrottler&MockObject $throttler;
	private CalendarFeedController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->tokens = $this->createMock(CalendarFeedTokenService::class);
		$this->feed = $this->createMock(ObjectCalendarFeedService::class);
		$this->attendees = $this->createMock(AppointmentAttendeeService::class);
		$this->throttler = $this->createMock(IThrottler::class);

		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('203.0.113.10');

		$this->controller = new CalendarFeedController(
			'openregister',
			$request,
			$this->tokens,
			$this->feed,
			$this->attendees,
			$this->throttler,
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testALiveTokenAnswersTextCalendar(): void {
		$token = new CalendarFeedToken();
		$token->setToken('opaque');
		$token->setUserId('caseworker');

		$this->tokens->method('resolve')->willReturn($token);
		$this->feed->method('render')->willReturn("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");
		$this->tokens->expects($this->once())->method('noteRead')->with($token);

		$response = $this->controller->feed(token: 'opaque');

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertStringContainsString('text/calendar', $response->getHeaders()['Content-Type']);
		$this->assertStringContainsString('BEGIN:VCALENDAR', $response->render());
	}

	public function testARevokedTokenAnswersNotFoundAndIsThrottled(): void {
		$this->tokens->method('resolve')->willReturn(null);
		$this->feed->expects($this->never())->method('render');
		$this->throttler->expects($this->once())->method('registerAttempt');

		$response = $this->controller->feed(token: 'revoked');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testAFeedThatCannotRenderAnswersNotFoundRatherThanAnEmptyBody(): void {
		$token = new CalendarFeedToken();
		$token->setUserId('gone');

		$this->tokens->method('resolve')->willReturn($token);
		$this->feed->method('render')->willReturn(null);
		$this->tokens->expects($this->never())->method('noteRead');

		$response = $this->controller->feed(token: 'opaque');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testRevokingSomethingThatIsNotThereAnswersNotFound(): void {
		$this->tokens->method('revoke')->willReturn(false);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->revoke(id: 42)->getStatus());
	}

	public function testRevokingAnOwnedTokenSucceeds(): void {
		$this->tokens->method('revoke')->willReturn(true);

		$this->assertSame(Http::STATUS_OK, $this->controller->revoke(id: 5)->getStatus());
	}

	public function testAttendeeResponsesAreReadBackWithResponderAndTime(): void {
		$this->attendees->method('responsesFor')->willReturn(
			[
				['attendee' => 'ana@example.org', 'status' => 'ACCEPTED', 'respondedBy' => 'ana', 'respondedAt' => '2026-09-14T10:00:00+02:00'],
			]
		);

		$response = $this->controller->attendeeResponses(id: 'object-uuid');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('ana@example.org', $data['results'][0]['attendee']);
		$this->assertSame('ana', $data['results'][0]['respondedBy']);
		$this->assertSame('2026-09-14T10:00:00+02:00', $data['results'][0]['respondedAt']);
	}

	public function testAnUnknownObjectAnswersNotFound(): void {
		$this->attendees->method('responsesFor')
			->willThrowException(new \InvalidArgumentException('no such object'));

		$this->assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->attendeeResponses(id: 'missing')->getStatus()
		);
	}

	public function testARefusedAnswerIsABadRequest(): void {
		$this->attendees->method('recordResponse')
			->willThrowException(new \InvalidArgumentException('MAYBE is not an attendee answer'));

		$this->assertSame(
			Http::STATUS_BAD_REQUEST,
			$this->controller->recordAttendeeResponse(id: 'object-uuid')->getStatus()
		);
	}
}
