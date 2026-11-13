<?php

/**
 * A removed user's object shares end with them.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Listener\SharerViewsEndWithUserListener;
use OCA\OpenRegister\Service\Rbac\SharerFolderAccess;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\BeforeUserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Listener\SharerViewsEndWithUserListener
 */
class SharerViewsEndWithUserListenerTest extends TestCase {

	private function removal(string $uid): BeforeUserDeletedEvent {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return new BeforeUserDeletedEvent($user);
	}

	public function testARemovedUsersViewsAndGrantsEnd(): void {
		$access = $this->createMock(SharerFolderAccess::class);
		$access->expects($this->once())->method('endAllViewsOf')->with('alice')->willReturn(2);

		(new SharerViewsEndWithUserListener(sharerAccess: $access, logger: $this->createMock(LoggerInterface::class)))
			->handle($this->removal(uid: 'alice'));
	}

	public function testOtherEventsAreIgnored(): void {
		$access = $this->createMock(SharerFolderAccess::class);
		$access->expects($this->never())->method('endAllViewsOf');

		(new SharerViewsEndWithUserListener(sharerAccess: $access, logger: $this->createMock(LoggerInterface::class)))
			->handle(new Event());
	}

	public function testAFailureDoesNotStopTheRemoval(): void {
		$access = $this->createMock(SharerFolderAccess::class);
		$access->method('endAllViewsOf')->willThrowException(new RuntimeException('share table locked'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		(new SharerViewsEndWithUserListener(sharerAccess: $access, logger: $logger))->handle($this->removal(uid: 'alice'));
	}
}
