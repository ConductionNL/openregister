<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\TalkLink;

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
 */
class TalkGatewayTest extends TestCase {

	public function testManagerIsNullWhenTalkIsDisabled(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->with('spreed')->willReturn(false);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$gateway = new TalkGateway($container, $apps, $this->createMock(LoggerInterface::class));

		$this->assertFalse($gateway->isAvailable());
		$this->assertNull($gateway->manager());
	}

	public function testFindRoomFallsBackToTheRawLookup(): void {
		$gateway = new TalkGateway($this->createMock(ContainerInterface::class), $this->createMock(IAppManager::class), $this->createMock(LoggerInterface::class));

		$this->assertEquals((object)['token' => 't1'], $gateway->findRoom(new TalkGatewayTestOldManager(), 't1', 'alice'));
		$this->assertNull($gateway->findRoom(new TalkGatewayTestOldManager(), 'gone', 'alice'));
		$this->assertNull($gateway->findRoom(new \stdClass(), 't1', 'alice'));
	}

	public function testSetDescriptionSkipsAnEmptyDescription(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$gateway = new TalkGateway($container, $this->createMock(IAppManager::class), $this->createMock(LoggerInterface::class));

		$gateway->setDescription(new \stdClass(), '');
		$gateway->setDescription(new \stdClass(), null);
		$this->addToAssertionCount(1);
	}
}
