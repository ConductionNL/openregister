<?php

/**
 * TalkGateway: the user-scoped room lookup of a current Talk manager, its fallback
 * to the raw token lookup, and the no-op paths when Talk's classes are absent.
 *
 * TalkGatewayTest covers a disabled Talk and an old manager with the raw lookup only.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\TalkLink
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TalkLink;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCP\IUserSession;
use OCA\OpenRegister\Service\TalkLink\TalkSession;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCA\OpenRegister\Service\TalkLink\TalkGateway;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A current Talk manager: user-scoped lookup first, raw lookup as the fallback.
 */
class TalkGatewayCurrentManager {

	/**
	 * Calls made, in order.
	 *
	 * @var array<int, string>
	 */
	public array $calls = [];

	public function getRoomForUserByToken(string $token, string $userUid): object {
		$this->calls[] = "user:{$token}:{$userUid}";
		if ($token === 'not-a-member') {
			throw new RuntimeException('participant not found');
		}

		return (object)['token' => $token, 'for' => $userUid];
	}

	public function getRoomByToken(string $token): object {
		$this->calls[] = "raw:{$token}";
		return (object)['token' => $token, 'for' => null];
	}
}

/**
 * @covers \OCA\OpenRegister\Service\TalkLink\TalkGateway
 * @uses   \OCA\OpenRegister\Service\LinkServiceRuntime
 * @uses   \OCA\OpenRegister\Service\TalkLink\TalkSession
 * @uses \OCA\OpenRegister\Service\LinkServiceRuntime
 * @uses \OCA\OpenRegister\Service\TalkLink\TalkSession
 */
class TalkGatewayUserScopedLookupTest extends TestCase {

	private function gateway(?ContainerInterface $container = null, bool $talkEnabled = true): TalkGateway {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->with('spreed')->willReturn($talkEnabled);
		return new TalkGateway($container ?? $this->createMock(ContainerInterface::class), new LinkServiceRuntime($apps, $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class)));
	}

	public function testTheUserScopedLookupIsPreferred(): void {
		$manager = new TalkGatewayCurrentManager();

		$room = $this->gateway()->findRoom(new TalkSession($manager, 'alice'), 'abc');

		$this->assertEquals((object)['token' => 'abc', 'for' => 'alice'], $room);
		$this->assertSame(['user:abc:alice'], $manager->calls);
	}

	public function testAUserScopedRefusalFallsBackToTheRawLookup(): void {
		$manager = new TalkGatewayCurrentManager();

		$room = $this->gateway()->findRoom(new TalkSession($manager, 'bob'), 'not-a-member');

		$this->assertEquals((object)['token' => 'not-a-member', 'for' => null], $room);
		$this->assertSame(['user:not-a-member:bob', 'raw:not-a-member'], $manager->calls);
	}

	/**
	 * Own process: other Talk tests declare OCA\Talk stand-ins, which would make
	 * the classes exist here when the suite runs them first.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testAnEnabledTalkWithoutItsClassesHasNoManagerAndTheContainerIsNotAsked(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$gateway = $this->gateway($container);

		$this->assertTrue($gateway->isAvailable());
		$this->assertNull($gateway->manager());
		$this->assertNull($gateway->participantService());
	}
}
