<?php

/**
 * TalkGateway: the user-scoped room lookup of a current Talk manager and its
 * fallback to the raw token lookup. Paths that resolve Talk services depend on
 * whether the Talk app is installed, so they are not asserted here.
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

use OCA\OpenRegister\Service\TalkLink\TalkGateway;
use OCP\App\IAppManager;
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
 */
class TalkGatewayUserScopedLookupTest extends TestCase {

	private function gateway(?ContainerInterface $container = null, bool $talkEnabled = true): TalkGateway {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->with('spreed')->willReturn($talkEnabled);
		return new TalkGateway($container ?? $this->createMock(ContainerInterface::class), $apps, $this->createMock(LoggerInterface::class));
	}

	public function testTheUserScopedLookupIsPreferred(): void {
		$manager = new TalkGatewayCurrentManager();

		$room = $this->gateway()->findRoom($manager, 'abc', 'alice');

		$this->assertEquals((object)['token' => 'abc', 'for' => 'alice'], $room);
		$this->assertSame(['user:abc:alice'], $manager->calls);
	}

	public function testAUserScopedRefusalFallsBackToTheRawLookup(): void {
		$manager = new TalkGatewayCurrentManager();

		$room = $this->gateway()->findRoom($manager, 'not-a-member', 'bob');

		$this->assertEquals((object)['token' => 'not-a-member', 'for' => null], $room);
		$this->assertSame(['user:not-a-member:bob', 'raw:not-a-member'], $manager->calls);
	}
}
