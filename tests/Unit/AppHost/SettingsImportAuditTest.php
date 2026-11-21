<?php

/**
 * A register configuration import is recorded as one audit row naming how many keys it overwrote.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Service\AppHostSettingsService;
use OCA\OpenRegister\AppHost\Service\AppHostSettingsServices;
use OCA\OpenRegister\AppHost\Service\GenericSettingsService;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\Rbac\SettingsImport;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

#[CoversClass(AppHostSettingsService::class)]
#[CoversClass(GenericSettingsService::class)]
#[UsesClass(AppHostSettingsServices::class)]
#[UsesClass(SettingsImport::class)]
class SettingsImportAuditTest extends TestCase {

	/** @var array<int, array{0: string, 1: SettingsImport}> */
	private array $recorded = [];

	/** @var array<int, string> */
	private array $tempDirs = [];

	private bool $imported = false;

	protected function tearDown(): void {
		foreach ($this->tempDirs as $dir) {
			unlink($dir . '/lib/Settings/myapp_register.json');
			rmdir($dir . '/lib/Settings');
			rmdir($dir . '/lib');
			rmdir($dir);
		}

		parent::tearDown();
	}

	/**
	 * The service, with the stored values before and after the import.
	 *
	 * @param array<string, string> $before Stored values before.
	 * @param array<string, string> $after  Stored values after.
	 * @param array<string, mixed>  $result What the import returns.
	 */
	private function service(array $before, array $after, array $result, bool $withAuditor = true): GenericSettingsService {
		$dir = sys_get_temp_dir() . '/settings-import-audit-' . uniqid();
		mkdir($dir . '/lib/Settings', 0777, true);
		file_put_contents($dir . '/lib/Settings/myapp_register.json', json_encode(['info' => ['version' => '1.0.0']]));
		$this->tempDirs[] = $dir;

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appManager->method('getAppPath')->willReturn($dir);
		$appManager->method('getAppVersion')->willReturn('9.9.9');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key): string => (($this->imported === true ? $after : $before)[$key] ?? '')
		);

		$configurationService = $this->createMock(ConfigurationService::class);
		$configurationService->method('importFromApp')->willReturnCallback(
			function () use ($result): array {
				$this->imported = true;
				return $result;
			}
		);

		$recorded = &$this->recorded;
		$auditor  = new class ($recorded) {
			/** @param array<int, mixed> $recorded */
			public function __construct(private array &$recorded) {
			}

			public function recordImport(string $app, SettingsImport $import): int {
				$this->recorded[] = [$app, $import];
				return 1;
			}

			/** @param array<string, mixed> $before @param array<string, mixed> $after @param array<int, string> $secrets */
			public function recordUpdate(string $app, array $before, array $after, array $secrets = []): int {
				$this->recorded[] = [$app, ['before' => $before, 'after' => $after]];
				return 1;
			}

			/** @return array<int, string> */
			public function secretKeysIn(mixed ...$arguments): array {
				return [];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($configurationService, $auditor, $withAuditor): object {
				if ($id === 'OCA\OpenRegister\Service\ConfigurationService') {
					return $configurationService;
				}

				if ($withAuditor === true) {
					return $auditor;
				}

				throw new \RuntimeException('unknown service ' . $id);
			}
		);

		return new class (
			'myapp',
			new AppHostSettingsServices($appConfig, $appManager, $container, $this->createMock(LoggerInterface::class)),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserSession::class)
		) extends GenericSettingsService {
			protected function configKeys(): array {
				return ['register', 'mode', 'fresh', 'cleared'];
			}
		};
	}

	public function testAnImportIsOneRowCountingTheKeysItOverwrote(): void {
		$service = $this->service(
			before: ['register' => 'r1', 'mode' => '1', 'fresh' => '', 'cleared' => 'x'],
			after: ['register' => 'r2', 'mode' => '1', 'fresh' => 'new', 'cleared' => ''],
			result: ['version' => '1.0.0']
		);

		$outcome = $service->loadConfiguration(force: true);

		$this->assertTrue($outcome['success']);
		$this->assertCount(1, $this->recorded);
		[$app, $import] = $this->recorded[0];
		$this->assertSame('myapp', $app);
		$this->assertTrue($import->forced);
		// register changed and cleared was emptied; mode is unchanged and fresh was set for the first time.
		$this->assertSame(2, $import->keysOverwritten);
	}

	public function testAnUnforcedImportThatChangedNothingStillRecordsTheImport(): void {
		$service = $this->service(before: ['register' => 'r1'], after: ['register' => 'r1'], result: ['version' => '1.0.0']);

		$service->loadConfiguration(force: false);

		$this->assertCount(1, $this->recorded);
		$this->assertFalse($this->recorded[0][1]->forced);
		$this->assertSame(0, $this->recorded[0][1]->keysOverwritten);
	}

	public function testTheGenericUpdateRecordsTheChangeThroughTheSameAuditor(): void {
		$service = $this->service(before: ['register' => 'r1'], after: ['register' => 'r2'], result: []);
		$this->imported = true;

		$service->updateSettings(['register' => 'r2']);

		$this->assertCount(1, $this->recorded);
		$this->assertSame('myapp', $this->recorded[0][0]);
		$this->assertSame('r2', $this->recorded[0][1]['after']['register']);
	}

	public function testAnEmptyImportRecordsNothing(): void {
		$service = $this->service(before: ['register' => 'r1'], after: ['register' => 'r2'], result: []);

		$outcome = $service->loadConfiguration(force: true);

		$this->assertFalse($outcome['success']);
		$this->assertSame([], $this->recorded);
	}

	public function testAnUnresolvableAuditorDoesNotFailTheImport(): void {
		$service = $this->service(before: ['register' => 'r1'], after: ['register' => 'r2'], result: ['version' => '1.0.0'], withAuditor: false);

		$outcome = $service->loadConfiguration(force: true);

		$this->assertTrue($outcome['success']);
		$this->assertSame([], $this->recorded);
	}
}
