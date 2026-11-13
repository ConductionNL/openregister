<?php

/**
 * Unit test for AdministratorOnlyGate.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\AdministratorOnlyGate;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The refusal an administrator-only endpoint sends, or none.
 *
 * @covers \OCA\OpenRegister\Controller\Support\AdministratorOnlyGate
 */
class AdministratorOnlyGateTest extends TestCase {
	private function gate(?string $uid, bool $isAdmin = false): AdministratorOnlyGate {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $id): bool => $isAdmin && $id === $uid);

		return new AdministratorOnlyGate($session, $groups);
	}

	public function testNoUserIsUnauthorized(): void {
		$refusal = $this->gate(null)->refusal('Admins only');

		$this->assertSame(401, $refusal->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $refusal->getData());
	}

	public function testANonAdminIsForbiddenWithTheGivenMessage(): void {
		$refusal = $this->gate('bob')->refusal('Admins only');

		$this->assertSame(403, $refusal->getStatus());
		$this->assertSame(['error' => 'Admins only'], $refusal->getData());
	}

	public function testAnAdminPasses(): void {
		$this->assertNull($this->gate('root', true)->refusal('Admins only'));
	}
}
