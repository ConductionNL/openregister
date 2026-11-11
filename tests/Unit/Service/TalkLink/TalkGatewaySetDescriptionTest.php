<?php

/**
 * Characterisation tests for TalkGateway::setDescription() with a Talk RoomService present.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\TalkLink
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TalkLink;

use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCA\OpenRegister\Service\TalkLink\TalkGateway;
use OCP\App\IAppManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every branch of setDescription() past the empty-description guard: no
 * Talk installed, a RoomService that cannot be resolved, one without
 * setDescription(), one that takes the description, and one that throws.
 *
 * Each case that needs Talk's RoomService declares a stand-in for it, so it
 * runs in its own process and the class never exists for any other test.
 */
class TalkGatewaySetDescriptionTest extends TestCase {

	private const ROOM_SERVICE = 'OCA\\Talk\\Service\\RoomService';

	private LoggerInterface $logger;

	/**
	 * The gateway over a container that answers RoomService with the given value.
	 *
	 * @param mixed $roomService The service, or a Throwable to throw.
	 *
	 * @return TalkGateway
	 */
	private function gateway(mixed $roomService): TalkGateway {
		$this->logger = $this->createMock(LoggerInterface::class);
		$container = $this->createMock(ContainerInterface::class);
		if ($roomService instanceof \Throwable) {
			$container->method('get')->with(self::ROOM_SERVICE)->willThrowException($roomService);
		} else {
			$container->method('get')->with(self::ROOM_SERVICE)->willReturn($roomService);
		}

		return new TalkGateway($container, new LinkServiceRuntime($this->createMock(IAppManager::class), $this->createMock(IUserSession::class), $this->logger));
	}

	/**
	 * Declare the RoomService stand-in in this process.
	 *
	 * @return void
	 */
	private static function declareRoomService(): void {
		eval('namespace OCA\\Talk\\Service; class RoomService { public array $calls = []; public function setDescription(object $room, string $description): void { $this->calls[] = [$room, $description]; if ($description === "boom") { throw new \\RuntimeException("talk says no"); } } }');
	}

	public function testWithoutTalkNothingIsResolved(): void {
		$this->assertFalse(class_exists(self::ROOM_SERVICE));
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$gateway = new TalkGateway($container, new LinkServiceRuntime($this->createMock(IAppManager::class), $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class)));

		$gateway->setDescription(new \stdClass(), 'Zaak 12');
	}

	#[RunInSeparateProcess]
	public function testTheDescriptionIsSetThroughTheRoomService(): void {
		self::declareRoomService();
		$service = new \OCA\Talk\Service\RoomService();
		$room = new \stdClass();
		$gateway = $this->gateway($service);
		$this->logger->expects($this->never())->method('debug');

		$gateway->setDescription($room, 'Zaak 12');

		$this->assertSame([[$room, 'Zaak 12']], $service->calls);
	}

	#[RunInSeparateProcess]
	public function testARoomServiceWithoutSetDescriptionIsLeftAlone(): void {
		self::declareRoomService();
		$gateway = $this->gateway(new \stdClass());
		$this->logger->expects($this->never())->method('debug');

		$gateway->setDescription(new \stdClass(), 'Zaak 12');
	}

	#[RunInSeparateProcess]
	public function testAnUnresolvableRoomServiceIsLoggedOnceAndSkipped(): void {
		self::declareRoomService();
		$gateway = $this->gateway(new RuntimeException('not wired'));
		$this->logger->expects($this->once())->method('debug')->with('Talk RoomService not resolvable: not wired');

		$gateway->setDescription(new \stdClass(), 'Zaak 12');
	}

	#[RunInSeparateProcess]
	public function testAFailingRoomServiceIsLoggedNotThrown(): void {
		self::declareRoomService();
		$gateway = $this->gateway(new \OCA\Talk\Service\RoomService());
		$this->logger->expects($this->once())->method('debug')->with('Failed to set Talk room description: talk says no');

		$gateway->setDescription(new \stdClass(), 'boom');
	}
}//end class
