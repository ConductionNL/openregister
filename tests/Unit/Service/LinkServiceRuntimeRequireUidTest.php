<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\LinkServiceRuntime::requireUid()}.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-registry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The link services' shared "who is signed in" check.
 */
class LinkServiceRuntimeRequireUidTest extends TestCase {

	/**
	 * A runtime whose session holds the given user.
	 *
	 * @param IUser|null $user The signed-in user, or null.
	 *
	 * @return LinkServiceRuntime
	 */
	private function runtimeWith(?IUser $user): LinkServiceRuntime {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new LinkServiceRuntime(
			$this->createMock(IAppManager::class),
			$session,
			$this->createMock(LoggerInterface::class)
		);
	}//end runtimeWith()

	/**
	 * A signed-in user yields their UID.
	 *
	 * @return void
	 */
	public function testReturnsTheSignedInUid(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$this->assertSame('alice', $this->runtimeWith(user: $user)->requireUid());
	}//end testReturnsTheSignedInUid()

	/**
	 * Without a user the call is refused with a 401.
	 *
	 * @return void
	 */
	public function testRefusesWithoutUserWith401(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('No user logged in');
		$this->expectExceptionCode(401);

		$this->runtimeWith(user: null)->requireUid();
	}//end testRefusesWithoutUserWith401()
}//end class
