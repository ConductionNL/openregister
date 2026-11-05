<?php

/**
 * A settings key the register configuration declares secret is masked on the audit trail.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\AppHost
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/settings-change-audit/specs/apphost-settings-plane/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Service\AppHostSettingsService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Rbac\SettingsChangeAuditor;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs the real AppHostSettingsService and SettingsChangeAuditor over a register JSON on disk.
 */
class AppHostSettingsSecretAuditTest extends TestCase {

	/** @var array<int, \OCA\OpenRegister\Db\AuditTrail> The rows the auditor wrote. */
	private array $written = [];

	private string $appPath = '';

	protected function tearDown(): void {
		if ($this->appPath !== '') {
			unlink($this->appPath . '/lib/Settings/myapp_register.json');
			rmdir($this->appPath . '/lib/Settings');
			rmdir($this->appPath . '/lib');
			rmdir($this->appPath);
		}

		parent::tearDown();
	}

	/**
	 * Save `register` from one value to another under the given register JSON.
	 *
	 * @param array<string, mixed> $register The app's register JSON.
	 *
	 * @return array<string, mixed> The `changed` block of the one row written.
	 */
	private function saveRegisterSetting(array $register): array {
		$this->appPath = sys_get_temp_dir() . '/apphost-secret-audit-' . uniqid();
		mkdir($this->appPath . '/lib/Settings', 0777, true);
		file_put_contents($this->appPath . '/lib/Settings/myapp_register.json', json_encode($register));

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appManager->method('getAppPath')->willReturn($this->appPath);

		$stored = ['register' => 'old-token'];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$stored): string {
				return ($stored[$key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): bool {
				$stored[$key] = $value;
				return true;
			}
		);

		$mapper = $this->getMockBuilder(AuditTrailMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['insertAuditTrails'])
			->getMock();
		$written = &$this->written;
		$mapper->method('insertAuditTrails')->willReturnCallback(
			static function (array $entries, int $chunkSize = 100) use (&$written): array {
				$written = array_merge($written, $entries);
				return $entries;
			}
		);
		$auditor = new SettingsChangeAuditor(
			mapper: $mapper,
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class)
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($auditor);

		$service = new AppHostSettingsService(
			'myapp',
			$appConfig,
			$appManager,
			$container,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class)
		);

		$service->updateSettings(['register' => 'new-token']);

		$this->assertCount(1, $this->written, 'one changed key, one row');
		return $this->written[0]->getChanged();
	}

	public function testAKeyDeclaredSecretIsMaskedOnTheTrail(): void {
		$changed = $this->saveRegisterSetting(
			[
				'info' => ['version' => '1.0.0'],
				'register' => [SettingsChangeAuditor::SECRET_KEY => true],
			]
		);

		$this->assertSame('register', $changed['key']);
		$this->assertTrue($changed['secret']);
		$this->assertSame(SettingsChangeAuditor::MASK, $changed['old']);
		$this->assertSame(SettingsChangeAuditor::MASK, $changed['new']);
		$this->assertStringNotContainsString('token', (string)json_encode($changed), 'no value reaches the row');
	}

	public function testAnUndeclaredKeyKeepsItsValues(): void {
		$changed = $this->saveRegisterSetting(['info' => ['version' => '1.0.0']]);

		$this->assertFalse($changed['secret']);
		$this->assertSame('old-token', $changed['old']);
		$this->assertSame('new-token', $changed['new']);
	}
}
