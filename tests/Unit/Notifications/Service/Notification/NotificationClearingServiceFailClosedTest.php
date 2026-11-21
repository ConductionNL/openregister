<?php

/**
 * Unit tests for the fail-closed branches of NotificationClearingService.
 *
 * A clearing or archiving failure must never surface to the reader: it is
 * logged and reported as "nothing changed". An anonymous caller is refused.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Notifications\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationClearingService;
use OCA\OpenRegister\Notifications\Service\PrincipalDirectory;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\NotificationClearingService
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\NoticeSubject
 * @uses   \OCA\OpenRegister\Notifications\Service\PrincipalDirectory
 */
final class NotificationClearingServiceFailClosedTest extends TestCase {

	/** @var NotificationHistoryMapper&MockObject */
	private $mapper;

	/** @var LoggerInterface&MockObject */
	private $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(NotificationHistoryMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function serviceAs(?string $uid): NotificationClearingService {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new NotificationClearingService(
			mapper: $this->mapper,
			principals: new PrincipalDirectory($this->createMock(IUserManager::class), $this->createMock(IGroupManager::class), $session),
			logger: $this->logger
		);
	}

	public function testAnOrphanArchiveWithoutAnObjectDoesNothing(): void {
		$this->mapper->expects($this->never())->method('archiveByObject');

		$this->assertSame(0, $this->serviceAs(null)->archiveOrphaned(objectUuid: ''));
	}

	public function testAnOrphanArchiveReturnsTheArchivedCount(): void {
		$this->mapper->expects($this->once())->method('archiveByObject')->with('uuid-gone')->willReturn(4);

		$this->assertSame(4, $this->serviceAs(null)->archiveOrphaned(objectUuid: 'uuid-gone'));
	}

	public function testAFailedOrphanArchiveIsLoggedAndCountsNothing(): void {
		$this->mapper->method('archiveByObject')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('[NotificationClearingService] orphan archive failed for uuid-gone: db down');

		$this->assertSame(0, $this->serviceAs(null)->archiveOrphaned(objectUuid: 'uuid-gone'));
	}

	public function testAFailedClearIsLoggedAndCountsNothing(): void {
		$this->mapper->method('markReadForSubject')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('[NotificationClearingService] clearing failed for uuid-case-1: db down');

		$this->assertSame(0, $this->serviceAs('alice')->clearForObject(objectUuid: 'uuid-case-1'));
	}

	public function testAnExplicitReaderIsClearedWithoutASession(): void {
		$this->mapper->expects($this->once())
			->method('markReadForSubject')
			->with('bob', 'uuid-case-1', null)
			->willReturn(2);

		$this->assertSame(2, $this->serviceAs(null)->clearForObject(objectUuid: 'uuid-case-1', userId: 'bob'));
	}

	public function testAnAnonymousThreadReadIsRefused(): void {
		$this->mapper->expects($this->never())->method('markReadForSubject');

		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage('Clearing a notification needs a signed-in user');

		$this->serviceAs(null)->markThreadRead(objectUuid: 'uuid-case-1');
	}

	public function testASignedInUserWithAnEmptyUidIsRefused(): void {
		$this->expectException(NotAuthorizedException::class);

		$this->serviceAs('')->markThreadRead(objectUuid: 'uuid-case-1');
	}
}
