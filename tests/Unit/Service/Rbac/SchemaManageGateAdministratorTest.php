<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Rbac\SchemaManageGate;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\SchemaManageGate
 */
class SchemaManageGateAdministratorTest extends TestCase {

	private function gate(?string $uid): SchemaManageGate {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $id): bool => $id === 'root');

		return new SchemaManageGate($session, $groups, $this->createMock(SchemaMapper::class));
	}

	public function testTheAdministratorIsAnswered(): void {
		$this->assertTrue($this->gate(uid: 'root')->callerIsAdministrator());
	}

	public function testAMemberIsNotAnAdministrator(): void {
		$this->assertFalse($this->gate(uid: 'alice')->callerIsAdministrator());
	}

	public function testNoSessionIsNotAnAdministrator(): void {
		$this->assertFalse($this->gate(uid: null)->callerIsAdministrator());
	}
}
