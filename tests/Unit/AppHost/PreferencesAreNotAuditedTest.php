<?php

/**
 * Per-user preferences are not audited (settings-change-audit, task 3.2b).
 *
 * A preference is one person's own view state, not configuration of the
 * install, so a preference write must never reach the settings audit writer or
 * the audit trail, and must never touch app configuration (which IS audited).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Controller\GenericPreferencesController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Rbac\SettingsChangeAuditor;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\AppHost\Controller\GenericPreferencesController
 */
final class PreferencesAreNotAuditedTest extends TestCase {

	public function testThePreferencesControllerCannotReachTheAuditWriter(): void {
		$constructor = (new ReflectionClass(GenericPreferencesController::class))->getConstructor();
		$types = array_map(
			static fn ($parameter): string => (string)$parameter->getType(),
			$constructor->getParameters()
		);

		$this->assertNotContains(SettingsChangeAuditor::class, $types);
		$this->assertNotContains(AuditTrailMapper::class, $types);
		$this->assertSame(['string', IRequest::class, IConfig::class, IUserSession::class], $types);
	}

	public function testAPreferenceWriteAndClearTouchOnlyTheUsersOwnValues(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())->method('setUserValue')->with('alice', 'myapp', 'pref_view', 'cards');
		$config->expects($this->once())->method('deleteUserValue')->with('alice', 'myapp', 'pref_view');
		$config->expects($this->never())->method('setAppValue');
		$config->expects($this->never())->method('deleteAppValue');
		$config->expects($this->never())->method('setSystemValue');

		$controller = new GenericPreferencesController('myapp', $this->createMock(IRequest::class), $config, $session);
		$this->assertSame(['value' => 'cards'], $controller->setPreference(key: 'view', value: 'cards')->getData());
		$this->assertSame(['value' => null], $controller->setPreference(key: 'view', value: '')->getData());
	}
}
