<?php

/**
 * AppHost AppHostSettingsService — settings resolution + OR availability tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Service\AppHostSettingsService;
use OCA\OpenRegister\Service\ConfigurationService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies the settings map shape, OR-availability and disabled-OR degrade.
 */
class AppHostSettingsServiceTest extends TestCase {
	/** @var array<int, string> Temp directories created by a test, removed in tearDown. */
	private array $tempDirs = [];

	protected function tearDown(): void {
		foreach ($this->tempDirs as $dir) {
			$this->removeDir($dir);
		}

		$this->tempDirs = [];
		parent::tearDown();
	}//end tearDown()

	/**
	 * Recursively removes a temp directory tree.
	 */
	private function removeDir(string $dir): void {
		if (is_dir($dir) === false) {
			return;
		}

		$items = scandir($dir);
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$path = $dir . '/' . $item;
			if (is_dir($path) === true) {
				$this->removeDir($path);
			} else {
				unlink($path);
			}
		}

		rmdir($dir);
	}//end removeDir()

	/**
	 * Creates a scratch "app path" with an `{appId}_register.json` (and optional
	 * `register.d/` fragments) so {@see AppHostSettingsService::resolveRegisterConfiguration()}
	 * can be exercised against real files, mirroring the fleet's `lib/Settings/` layout.
	 *
	 * @param string $appId The leaf app id.
	 * @param array<string, mixed> $register The base `{appId}_register.json` content.
	 * @param array<string, array<string, mixed>> $fragments Map of fragment filename => content.
	 *
	 * @return string The scratch app path.
	 */
	private function makeAppPath(string $appId, array $register, array $fragments = []): string {
		$dir = sys_get_temp_dir() . '/apphost-settings-test-' . uniqid();
		mkdir($dir . '/lib/Settings/register.d', 0777, true);
		file_put_contents($dir . '/lib/Settings/' . $appId . '_register.json', json_encode($register));

		foreach ($fragments as $filename => $content) {
			file_put_contents($dir . '/lib/Settings/register.d/' . $filename, json_encode($content));
		}

		$this->tempDirs[] = $dir;
		return $dir;
	}//end makeAppPath()

	private function service(
		bool $orInstalled,
		bool $isAdmin,
		string $registerValue = 'reg-uuid',
		?ContainerInterface $container = null,
		?IAppManager $appManager = null,
		string $appId = 'myapp',
	): AppHostSettingsService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($registerValue);

		if ($appManager === null) {
			$appManager = $this->createMock(IAppManager::class);
			$appManager->method('isInstalled')->willReturn($orInstalled);
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		return new AppHostSettingsService(
			$appId,
			$appConfig,
			$appManager,
			($container ?? $this->createMock(ContainerInterface::class)),
			$groupManager,
			$userSession,
			$this->createMock(LoggerInterface::class)
		);
	}//end service()

	public function testGetSettingsIncludesRegisterAndMetadata(): void {
		$settings = $this->service(orInstalled: true, isAdmin: true)->getSettings();
		$this->assertSame('reg-uuid', $settings['register']);
		$this->assertTrue($settings['openregisters']);
		$this->assertTrue($settings['isAdmin']);
	}//end testGetSettingsIncludesRegisterAndMetadata()

	public function testNonAdminFlagReflected(): void {
		$settings = $this->service(orInstalled: true, isAdmin: false)->getSettings();
		$this->assertFalse($settings['isAdmin']);
	}//end testNonAdminFlagReflected()

	public function testIsOpenRegisterAvailableReflectsAppManager(): void {
		$this->assertTrue($this->service(orInstalled: true, isAdmin: false)->isOpenRegisterAvailable());
		$this->assertFalse($this->service(orInstalled: false, isAdmin: false)->isOpenRegisterAvailable());
	}//end testIsOpenRegisterAvailableReflectsAppManager()

	public function testLoadConfigurationDegradesWhenOrAbsent(): void {
		$result = $this->service(orInstalled: false, isAdmin: true)->loadConfiguration(force: true);
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('OpenRegister', $result['message']);
	}//end testLoadConfigurationDegradesWhenOrAbsent()

	/**
	 * Regression test for or#285: importFromApp() requires `$data` (array) and
	 * `$version` (string) - the generic AppHost path must build and pass both,
	 * not call importFromApp(appId, force) alone (which fatals).
	 */
	public function testLoadConfigurationImportsAppRegisterJsonWithDataAndVersion(): void {
		$appPath = $this->makeAppPath('myapp', [
			'info' => ['version' => '1.2.3'],
			'components' => ['schemas' => ['Widget' => ['type' => 'object']]],
		]);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appManager->method('getAppPath')->with('myapp')->willReturn($appPath);
		$appManager->method('getAppVersion')->with('myapp')->willReturn('9.9.9');

		$configurationService = $this->createMock(ConfigurationService::class);
		$configurationService->expects($this->once())
			->method('importFromApp')
			->with(
				'myapp',
				['info' => ['version' => '1.2.3'], 'components' => ['schemas' => ['Widget' => ['type' => 'object']]]],
				'1.2.3',
				false
			)
			->willReturn(['version' => '1.2.3']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->with('OCA\OpenRegister\Service\ConfigurationService')
			->willReturn($configurationService);

		$result = $this->service(
			orInstalled: true,
			isAdmin: true,
			container: $container,
			appManager: $appManager
		)->loadConfiguration(force: false);

		$this->assertTrue($result['success']);
		$this->assertSame('1.2.3', $result['version']);
	}//end testLoadConfigurationImportsAppRegisterJsonWithDataAndVersion()

	/**
	 * register.d/ fragments must be deep-merged onto the base register document and
	 * their combined content hash folded into the version string, so importFromApp's
	 * version-gate re-imports when a fragment changes even if info.version did not.
	 */
	public function testLoadConfigurationMergesRegisterDFragmentsIntoVersionSuffix(): void {
		$appPath = $this->makeAppPath(
			'myapp',
			[
				'info' => ['version' => '1.0.0'],
				'components' => ['schemas' => ['Widget' => ['type' => 'object']]],
			],
			['20-extra.json' => ['components' => ['schemas' => ['Gadget' => ['type' => 'object']]]]]
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appManager->method('getAppPath')->with('myapp')->willReturn($appPath);
		$appManager->method('getAppVersion')->willReturn('9.9.9');

		$capturedVersion = null;
		$capturedData = null;
		$configurationService = $this->createMock(ConfigurationService::class);
		$configurationService->expects($this->once())
			->method('importFromApp')
			->willReturnCallback(function ($appId, $data, $version, $force) use (&$capturedVersion, &$capturedData) {
				$capturedVersion = $version;
				$capturedData = $data;
				return ['version' => $version];
			});

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($configurationService);

		$result = $this->service(
			orInstalled: true,
			isAdmin: true,
			container: $container,
			appManager: $appManager
		)->loadConfiguration(force: false);

		$this->assertTrue($result['success']);
		// Both schemas from the base document and the fragment must be present (merged).
		$this->assertArrayHasKey('Widget', $capturedData['components']['schemas']);
		$this->assertArrayHasKey('Gadget', $capturedData['components']['schemas']);
		// Version is the base info.version plus a folded fragment-signature suffix.
		$this->assertMatchesRegularExpression('/^1\.0\.0\+frag\.[0-9a-f]{8}$/', $capturedVersion);
	}//end testLoadConfigurationMergesRegisterDFragmentsIntoVersionSuffix()

	/**
	 * A leaf app with no `{appId}_register.json` degrades gracefully - never fatals.
	 */
	public function testLoadConfigurationReturnsFailureWhenRegisterJsonMissing(): void {
		$dir = sys_get_temp_dir() . '/apphost-settings-test-empty-' . uniqid();
		mkdir($dir, 0777, true);
		$this->tempDirs[] = $dir;

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appManager->method('getAppPath')->with('myapp')->willReturn($dir);

		$result = $this->service(
			orInstalled: true,
			isAdmin: true,
			appManager: $appManager
		)->loadConfiguration(force: false);

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('myapp_register.json', $result['message']);
	}//end testLoadConfigurationReturnsFailureWhenRegisterJsonMissing()
}//end class
