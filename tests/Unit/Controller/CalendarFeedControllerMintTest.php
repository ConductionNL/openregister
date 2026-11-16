<?php

/**
 * Minting a calendar feed token passes the caller, scope, label and lifetime on, answers 201 or 400, and
 * answers 404 to an anonymous caller; listing, revoking and recording an answer behave for the edge callers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\CalendarFeedController;
use OCA\OpenRegister\Controller\Support\CalendarFeedControllerServices;
use OCA\OpenRegister\Controller\Support\CalendarFeedDomain;
use OCA\OpenRegister\Controller\Support\CalendarFeedPlatform;
use OCA\OpenRegister\Controller\Support\ObjectShareLinkThrottle;
use OCA\OpenRegister\Controller\Support\TrimmedRequestParam;
use OCA\OpenRegister\Db\CalendarFeedToken;
use OCA\OpenRegister\Service\Calendar\AppointmentAttendeeService;
use OCA\OpenRegister\Service\Calendar\AttendeeResponse;
use OCA\OpenRegister\Service\Calendar\CalendarFeedTokenService;
use OCA\OpenRegister\Service\Calendar\ObjectCalendarFeedService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\CalendarFeedControllerFactory;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A caseworker and an anonymous caller on the token management endpoints.
 */
#[CoversClass(CalendarFeedController::class)]
#[UsesClass(CalendarFeedControllerServices::class)]
#[UsesClass(CalendarFeedDomain::class)]
#[UsesClass(CalendarFeedPlatform::class)]
#[UsesClass(ObjectShareLinkThrottle::class)]
#[UsesClass(TrimmedRequestParam::class)]
#[UsesClass(CalendarFeedToken::class)]
#[UsesClass(AttendeeResponse::class)]
final class CalendarFeedControllerMintTest extends TestCase {
	/** @var CalendarFeedTokenService&MockObject */
	private CalendarFeedTokenService $tokens;

	/** @var AppointmentAttendeeService&MockObject */
	private AppointmentAttendeeService $attendees;

	/**
	 * Fresh services per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->tokens = $this->createMock(CalendarFeedTokenService::class);
		$this->attendees = $this->createMock(AppointmentAttendeeService::class);
	}

	/**
	 * The controller for the given request params, signed in as `caseworker` unless anonymous.
	 *
	 * @param array<string, mixed> $params    The request params.
	 * @param bool                 $anonymous Whether nobody is signed in.
	 *
	 * @return CalendarFeedController
	 */
	private function controller(array $params=[], bool $anonymous=false): CalendarFeedController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default)
		);

		$user = null;
		if ($anonymous === false) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('caseworker');
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return CalendarFeedControllerFactory::create(
			'openregister',
			$request,
			$this->tokens,
			$this->createMock(ObjectCalendarFeedService::class),
			$this->attendees,
			$session,
			$this->createMock(IThrottler::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * A mint passes the caller, the scope, the trimmed label and the numeric lifetime, and answers 201.
	 *
	 * @return void
	 */
	public function testAMintPassesTheDraftAndAnswers201(): void {
		$captured = null;
		$this->tokens->expects($this->once())
			->method('mint')
			->willReturnCallback(
				function (string $userId, CalendarFeedToken $draft, ?int $ttlSeconds) use (&$captured): array {
					$captured = [$userId, $draft->getScopeType(), $draft->getScopeId(), $draft->getLabel(), $ttlSeconds];
					return ['token' => 'abc', 'url' => '/feed/abc'];
				}
			);

		$response = $this->controller(
			['scopeType' => 'schema', 'scopeId' => '12', 'label' => '  Afspraken  ', 'ttlSeconds' => '3600']
		)->mint();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['token' => 'abc', 'url' => '/feed/abc'], $response->getData());
		$this->assertSame(['caseworker', 'schema', '12', 'Afspraken', 3600], $captured);
	}

	/**
	 * A blank label and a non-numeric lifetime are passed on as null.
	 *
	 * @return void
	 */
	public function testABlankLabelAndANonNumericLifetimeBecomeNull(): void {
		$captured = null;
		$this->tokens->method('mint')->willReturnCallback(
			function (string $userId, CalendarFeedToken $draft, ?int $ttlSeconds) use (&$captured): array {
				$captured = [$draft->getLabel(), $ttlSeconds];
				return [];
			}
		);

		$this->controller(['scopeType' => 'view', 'scopeId' => 'v1', 'label' => '   ', 'ttlSeconds' => 'forever'])->mint();

		$this->assertSame([null, null], $captured);
	}

	/**
	 * A mint the token service refuses is a 400 carrying its message.
	 *
	 * @return void
	 */
	public function testARefusedMintIs400(): void {
		$this->tokens->method('mint')->willThrowException(new InvalidArgumentException('Unknown scope type'));

		$response = $this->controller(['scopeType' => 'register', 'scopeId' => '1'])->mint();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Unknown scope type'], $response->getData());
	}

	/**
	 * An anonymous mint is a 404 and no token is made.
	 *
	 * @return void
	 */
	public function testAnAnonymousMintIs404(): void {
		$this->tokens->expects($this->never())->method('mint');

		$response = $this->controller(['scopeType' => 'schema', 'scopeId' => '1'], true)->mint();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Not Found'], $response->getData());
	}

	/**
	 * An anonymous listing is empty without asking the store, and an anonymous revoke is a 404.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerListsNothingAndRevokesNothing(): void {
		$this->tokens->expects($this->never())->method('listForUser');
		$this->tokens->expects($this->never())->method('revoke');

		$list = $this->controller([], true)->index();
		$revoke = $this->controller([], true)->revoke(5);

		$this->assertSame(['results' => []], $list->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $revoke->getStatus());
	}

	/**
	 * A recorded answer passes the object, attendee, status and trimmed responder, and answers the list.
	 *
	 * @return void
	 */
	public function testARecordedAnswerAnswersTheResponseList(): void {
		$this->attendees->method('mayRead')->with('object-uuid')->willReturn(true);
		$this->attendees->expects($this->once())
			->method('recordAttendeeResponse')
			->with(new AttendeeResponse(objectUuid: 'object-uuid', attendee: 'jan@example.com', status: 'accepted', respondedBy: 'Jan'))
			->willReturn([['attendee' => 'jan@example.com', 'status' => 'accepted']]);

		$response = $this->controller(
			['attendee' => 'jan@example.com', 'status' => 'accepted', 'respondedBy' => ' Jan ']
		)->recordAttendeeResponse('object-uuid');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => [['attendee' => 'jan@example.com', 'status' => 'accepted']]], $response->getData());
	}
}
