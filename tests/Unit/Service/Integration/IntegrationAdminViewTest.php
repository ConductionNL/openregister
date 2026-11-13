<?php

/**
 * Whether the caller is an administrator, and the admin-only fields added to
 * an integration row: required permission, OpenConnector source and the
 * provider's health, with a throwing health check reported as unavailable.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Service\Integration\IntegrationAdminView;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * IntegrationAdminView.
 *
 * @covers \OCA\OpenRegister\Service\Integration\IntegrationAdminView
 */
class IntegrationAdminViewTest extends TestCase {

	public function testNoSignedInUserIsNotAnAdmin(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->never())->method('isAdmin');

		$this->assertFalse(IntegrationAdminView::callerIsAdmin($session, $groups));
	}//end testNoSignedInUserIsNotAnAdmin()

	public function testTheGroupManagerDecidesForTheSignedInUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$admin = $this->createMock(IGroupManager::class);
		$admin->expects($this->once())->method('isAdmin')->with('alice')->willReturn(true);
		$this->assertTrue(IntegrationAdminView::callerIsAdmin($session, $admin));

		$plain = $this->createMock(IGroupManager::class);
		$plain->expects($this->once())->method('isAdmin')->with('alice')->willReturn(false);
		$this->assertFalse(IntegrationAdminView::callerIsAdmin($session, $plain));
	}//end testTheGroupManagerDecidesForTheSignedInUser()

	public function testTheAdminFieldsAreAddedToTheRow(): void {
		$provider = $this->createMock(IntegrationProvider::class);
		$provider->method('requiresPermission')->willReturn('integrations.manage');
		$provider->method('getOpenConnectorSource')->willReturn('source-7');
		$provider->method('health')->willReturn(['status' => 'ok', 'authStatus' => 'valid']);

		$this->assertSame(
			[
				'id' => 'email',
				'requiresPermission' => 'integrations.manage',
				'openConnectorSource' => 'source-7',
				'authStatus' => ['status' => 'ok', 'authStatus' => 'valid'],
			],
			IntegrationAdminView::withAdminFields(['id' => 'email'], $provider)
		);
	}//end testTheAdminFieldsAreAddedToTheRow()

	public function testAThrowingHealthCheckIsReportedAsUnavailable(): void {
		$provider = $this->createMock(IntegrationProvider::class);
		$provider->method('requiresPermission')->willReturn(null);
		$provider->method('getOpenConnectorSource')->willReturn(null);
		$provider->method('health')->willThrowException(new RuntimeException('secret detail'));

		$row = IntegrationAdminView::withAdminFields(['id' => 'calendar', 'requiresPermission' => 'stale'], $provider);

		$this->assertNull($row['requiresPermission']);
		$this->assertNull($row['openConnectorSource']);
		$this->assertSame(
			['status' => 'unavailable', 'authStatus' => 'unknown', 'message' => 'Provider health check threw'],
			$row['authStatus']
		);
	}//end testAThrowingHealthCheckIsReportedAsUnavailable()
}//end class
