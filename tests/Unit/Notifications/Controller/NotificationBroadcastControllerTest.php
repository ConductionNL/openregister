<?php

/**
 * Unit tests for NotificationBroadcastController: who may list, send and
 * withdraw broadcasts, what a send needs, and what a signed-in user reads and
 * acknowledges.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Notifications\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Controller;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Notifications\Controller\NotificationBroadcastController;
use OCA\OpenRegister\Notifications\Controller\Support\NotificationBroadcastControllerServices;
use OCA\OpenRegister\Notifications\Db\BroadcastMessage;
use OCA\OpenRegister\Notifications\Db\BroadcastWindow;
use OCA\OpenRegister\Notifications\Db\NotificationBroadcast;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationBroadcastService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Admin-only management of broadcasts, and the signed-in user's own view of them.
 */
#[CoversClass(NotificationBroadcastController::class)]
#[UsesClass(NotificationBroadcastControllerServices::class)]
#[UsesClass(ControllerSession::class)]
#[UsesClass(BroadcastMessage::class)]
#[UsesClass(BroadcastWindow::class)]
#[UsesClass(NotificationBroadcast::class)]
final class NotificationBroadcastControllerTest extends TestCase {

	private NotificationBroadcastService&MockObject $broadcasts;

	protected function setUp(): void {
		$this->broadcasts  = $this->createMock(NotificationBroadcastService::class);
	}//end setUp()

	/**
	 * The controller for a signed-in $uid (null for nobody) with these request params.
	 *
	 * @param string|null          $uid    The signed-in user.
	 * @param array<string, mixed> $params The request parameters.
	 * @param bool                 $admin  Whether the user is an administrator.
	 *
	 * @return NotificationBroadcastController
	 */
	private function controller(?string $uid, array $params = [], bool $admin = true): NotificationBroadcastController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $id): bool => $admin && $id === $uid);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(static fn (string $key): mixed => ($params[$key] ?? null));

		return new NotificationBroadcastController(
			$request,
			new NotificationBroadcastControllerServices(
				broadcastService: $this->broadcasts,
				session: new ControllerSession(
					userSession: $userSession,
					groupManager: $groups,
					logger: $this->createMock(LoggerInterface::class)
				)
			)
		);
	}//end controller()

	/**
	 * A stored broadcast.
	 *
	 * @param string $uuid    The uuid.
	 * @param string $subject The subject.
	 *
	 * @return NotificationBroadcast
	 */
	private static function broadcast(string $uuid, string $subject): NotificationBroadcast {
		$broadcast = new NotificationBroadcast();
		$broadcast->setUuid($uuid);
		$broadcast->setSubject($subject);
		return $broadcast;
	}//end broadcast()

	public function testManagementNeedsASignedInAdministrator(): void {
		$this->broadcasts->expects($this->never())->method('listAll');
		$this->broadcasts->expects($this->never())->method('send');
		$this->broadcasts->expects($this->never())->method('withdraw');

		$anonymous = $this->controller(null);
		foreach ([$anonymous->index(), $anonymous->create(), $anonymous->destroy('b-1')] as $response) {
			$this->assertSame(401, $response->getStatus());
			$this->assertSame(['error' => 'Authentication required'], $response->getData());
		}

		$member = $this->controller('bob', ['subject' => 'x', 'endsAt' => '2026-12-01'], admin: false);
		foreach ([$member->index(), $member->create(), $member->destroy('b-1')] as $response) {
			$this->assertSame(403, $response->getStatus());
			$this->assertSame(['error' => 'Administrator required'], $response->getData());
		}
	}//end testManagementNeedsASignedInAdministrator()

	public function testTheAdminListsWithNumericPagingOnly(): void {
		$this->broadcasts->expects($this->exactly(2))
			->method('listAll')
			->willReturnCallback(static fn (?int $limit, ?int $offset): array => [['limit' => $limit, 'offset' => $offset]]);

		$paged = $this->controller('admin', ['limit' => '10', 'offset' => '20'])->index();
		$this->assertSame(['results' => [['limit' => 10, 'offset' => 20]], 'total' => 1], $paged->getData());

		$unpaged = $this->controller('admin', ['limit' => '-1', 'offset' => 'abc'])->index();
		$this->assertSame(['results' => [['limit' => null, 'offset' => null]], 'total' => 1], $unpaged->getData());
	}//end testTheAdminListsWithNumericPagingOnly()

	public function testASendNeedsASubjectAndAReadableEnd(): void {
		$this->broadcasts->expects($this->never())->method('send');

		$noSubject = $this->controller('admin', ['endsAt' => '2026-12-01'])->create();
		$this->assertSame([422, ['error' => 'A "subject" is required']], [$noSubject->getStatus(), $noSubject->getData()]);

		$noEnd = $this->controller('admin', ['subject' => 'Maintenance'])->create();
		$this->assertSame([422, ['error' => 'An "endsAt" is required']], [$noEnd->getStatus(), $noEnd->getData()]);

		$unreadable = $this->controller('admin', ['subject' => 'Maintenance', 'startsAt' => 'not a date', 'endsAt' => '2026-12-01'])->create();
		$this->assertSame(
			[422, ['error' => 'Both "startsAt" and "endsAt" must be readable moments']],
			[$unreadable->getStatus(), $unreadable->getData()]
		);
	}//end testASendNeedsASubjectAndAReadableEnd()

	public function testTheAdminSendsTheMessageForTheWindowAsThemselves(): void {
		$sent = self::broadcast('b-9', 'Maintenance');
		$this->broadcasts->expects($this->once())
			->method('send')
			->with(
				$this->callback(static fn (BroadcastMessage $m): bool => $m->subject === 'Maintenance' && $m->body === 'Down tonight'),
				'admin',
				$this->callback(
					static fn (BroadcastWindow $w): bool => $w->startsAt->format('Y-m-d H:i') === '2026-12-01 20:00'
						&& $w->endsAt->format('Y-m-d H:i') === '2026-12-01 23:00'
				)
			)
			->willReturn($sent);

		$response = $this->controller(
			'admin',
			['subject' => 'Maintenance', 'body' => 'Down tonight', 'startsAt' => '2026-12-01 20:00', 'endsAt' => '2026-12-01 23:00']
		)->create();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame($sent->jsonSerialize(), $response->getData());
	}//end testTheAdminSendsTheMessageForTheWindowAsThemselves()

	public function testAMissingStartMeansNowAndAnEmptyBodyIsNone(): void {
		$before = new DateTime('-1 minute');
		$this->broadcasts->expects($this->once())
			->method('send')
			->with(
				$this->callback(static fn (BroadcastMessage $m): bool => $m->body === null),
				'admin',
				$this->callback(static fn (BroadcastWindow $w): bool => $w->startsAt > $before && $w->startsAt < new DateTime('+1 minute'))
			)
			->willReturn(self::broadcast('b-1', 'Hi'));

		$this->assertSame(201, $this->controller('admin', ['subject' => 'Hi', 'body' => '', 'endsAt' => '2030-01-01'])->create()->getStatus());
	}//end testAMissingStartMeansNowAndAnEmptyBodyIsNone()

	public function testARejectedSendIsUnprocessable(): void {
		$this->broadcasts->method('send')->willThrowException(new InvalidArgumentException('The window ends before it starts'));

		$response = $this->controller('admin', ['subject' => 'Hi', 'startsAt' => '2026-12-02', 'endsAt' => '2026-12-01'])->create();

		$this->assertSame([422, ['error' => 'The window ends before it starts']], [$response->getStatus(), $response->getData()]);
	}//end testARejectedSendIsUnprocessable()

	public function testTheAdminWithdrawsAKnownBroadcastOnly(): void {
		$this->broadcasts->method('withdraw')->willReturnCallback(static fn (string $uuid): bool => $uuid === 'b-1');

		$known = $this->controller('admin')->destroy('b-1');
		$unknown = $this->controller('admin')->destroy('b-2');

		$this->assertSame([200, ['uuid' => 'b-1', 'withdrawn' => true]], [$known->getStatus(), $known->getData()]);
		$this->assertSame([404, ['error' => 'Unknown broadcast']], [$unknown->getStatus(), $unknown->getData()]);
	}//end testTheAdminWithdrawsAKnownBroadcastOnly()

	public function testASignedInUserReadsTheirActiveBroadcasts(): void {
		$one = self::broadcast('b-1', 'One');
		$two = self::broadcast('b-2', 'Two');
		$this->broadcasts->expects($this->once())->method('activeFor')->with('bob')->willReturn([$one, $two]);

		$response = $this->controller('bob', [], admin: false)->active();

		$this->assertSame(['results' => [$one->jsonSerialize(), $two->jsonSerialize()], 'total' => 2], $response->getData());
	}//end testASignedInUserReadsTheirActiveBroadcasts()

	public function testAUserAcknowledgesAKnownBroadcastForThemselves(): void {
		$this->broadcasts->method('acknowledge')->willReturnCallback(
			static fn (string $broadcastUuid, string $userId): bool => $broadcastUuid === 'b-1' && $userId === 'bob'
		);

		$known = $this->controller('bob', [], admin: false)->acknowledge('b-1');
		$unknown = $this->controller('bob', [], admin: false)->acknowledge('b-2');

		$this->assertSame([200, ['uuid' => 'b-1', 'acknowledged' => true]], [$known->getStatus(), $known->getData()]);
		$this->assertSame([404, ['error' => 'Unknown broadcast']], [$unknown->getStatus(), $unknown->getData()]);
	}//end testAUserAcknowledgesAKnownBroadcastForThemselves()

	public function testReadingAndAcknowledgingNeedASignedInUser(): void {
		$this->broadcasts->expects($this->never())->method('activeFor');
		$this->broadcasts->expects($this->never())->method('acknowledge');

		$this->assertSame(401, $this->controller(null)->active()->getStatus());
		$this->assertSame(401, $this->controller(null)->acknowledge('b-1')->getStatus());
	}//end testReadingAndAcknowledgingNeedASignedInUser()
}//end class
