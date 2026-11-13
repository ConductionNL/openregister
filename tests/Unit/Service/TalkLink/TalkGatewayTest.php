<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\TalkLink;

use OCP\IUserSession;
use OCA\OpenRegister\Service\TalkLink\TalkSession;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCA\OpenRegister\Service\TalkLink\TalkGateway;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A manager of an old Talk release: raw token lookup only.
 */
class TalkGatewayTestOldManager {
	/**
	 * @param string $token Token.
	 *
	 * @return object
	 */
	public function getRoomByToken(string $token): object {
		if ($token === 'gone') {
			throw new RuntimeException('gone');
		}

		return (object)['token' => $token];
	}
}

/**
 * @covers \OCA\OpenRegister\Service\TalkLink\TalkGateway
 * @uses   \OCA\OpenRegister\Service\LinkServiceRuntime
 * @uses   \OCA\OpenRegister\Service\TalkLink\TalkSession
 * @uses \OCA\OpenRegister\Service\LinkServiceRuntime
 * @uses \OCA\OpenRegister\Service\TalkLink\TalkSession
 */
class TalkGatewayTest extends TestCase {

	public function testManagerIsNullWhenTalkIsDisabled(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->with('spreed')->willReturn(false);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$gateway = new TalkGateway($container, new LinkServiceRuntime($apps, $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class)));

		$this->assertFalse($gateway->isAvailable());
		$this->assertNull($gateway->manager());
	}

	public function testFindRoomFallsBackToTheRawLookup(): void {
		$gateway = new TalkGateway($this->createMock(ContainerInterface::class), new LinkServiceRuntime($this->createMock(IAppManager::class), $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class)));

		$this->assertEquals((object)['token' => 't1'], $gateway->findRoom(new TalkSession(new TalkGatewayTestOldManager(), 'alice'), 't1'));
		$this->assertNull($gateway->findRoom(new TalkSession(new TalkGatewayTestOldManager(), 'alice'), 'gone'));
		$this->assertNull($gateway->findRoom(new TalkSession(new \stdClass(), 'alice'), 't1'));
	}

	public function testSetDescriptionSkipsAnEmptyDescription(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$gateway = new TalkGateway($container, new LinkServiceRuntime($this->createMock(IAppManager::class), $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class)));

		$gateway->setDescription(new \stdClass(), '');
		$gateway->setDescription(new \stdClass(), null);
		$this->addToAssertionCount(1);
	}
}
