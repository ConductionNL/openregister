<?php

/**
 * The store block and installed version ManifestLoader reads for an app: an
 * app without a readable manifest gets a disabled store, one with a manifest
 * gets the store it declares, and a missing or blank version reads unknown.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Observability;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ManifestLoader::loadStore() and appVersion().
 *
 * @covers \OCA\OpenRegister\AppHost\Observability\ManifestLoader
 * @uses \OCA\OpenRegister\AppHost\Observability\BundledManifestFile
 * @uses \OCA\OpenRegister\AppHost\Observability\ManifestLookup
 * @uses \OCA\OpenRegister\AppHost\Store\StoreManifest
 * @uses \OCA\OpenRegister\AppHost\Store\StoreManifestCatalogue
 * @uses \OCA\OpenRegister\AppHost\Store\StoreManifestContent
 * @uses \OCA\OpenRegister\AppHost\Store\StoreManifestDiscovery
 * @uses \OCA\OpenRegister\AppHost\Store\StoreManifestPermissions
 * @uses \OCA\OpenRegister\AppHost\Store\StoreManifestTarget
 */
class ManifestLoaderStoreTest extends TestCase {

	private string $appPath;

	protected function setUp(): void {
		$this->appPath = sys_get_temp_dir().'/or-store-'.bin2hex(random_bytes(6));
		mkdir($this->appPath.'/src', 0777, true);
	}//end setUp()

	protected function tearDown(): void {
		@unlink($this->appPath.'/src/manifest.json');
		@rmdir($this->appPath.'/src');
		@rmdir($this->appPath);
	}//end tearDown()

	private function loader(IAppManager $appManager, ?LoggerInterface $logger = null): ManifestLoader {
		return new ManifestLoader($appManager, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end loader()

	private function appAt(string $path): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('dossiq')->willReturn($path);

		return $appManager;
	}//end appAt()

	public function testAnAppWhosePathCannotBeFoundHasADisabledStore(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willThrowException(new AppPathNotFoundException('gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with(
			'[AppHost\ManifestLoader] App "dossiq" path not found: gone',
			$this->anything()
		);

		$store = $this->loader($appManager, $logger)->loadStore('dossiq');

		$this->assertSame('dossiq', $store->appId);
		$this->assertFalse($store->enabled);
	}//end testAnAppWhosePathCannotBeFoundHasADisabledStore()

	public function testAnAppWithoutAManifestFileHasADisabledStore(): void {
		$store = $this->loader($this->appAt($this->appPath))->loadStore('dossiq');

		$this->assertFalse($store->enabled);
	}//end testAnAppWithoutAManifestFileHasADisabledStore()

	public function testAManifestWithoutAStoreBlockDeclaresNoStore(): void {
		file_put_contents($this->appPath.'/src/manifest.json', '{"version":"1.0"}');

		$store = $this->loader($this->appAt($this->appPath))->loadStore('dossiq');

		$this->assertSame('dossiq', $store->appId);
		$this->assertFalse($store->enabled);
	}//end testAManifestWithoutAStoreBlockDeclaresNoStore()

	public function testTheInstalledVersionIsReported(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->once())->method('getAppVersion')->with('stackiq')->willReturn('3.2.1');

		$this->assertSame('3.2.1', $this->loader($appManager)->appVersion('stackiq'));
	}//end testTheInstalledVersionIsReported()

	public function testABlankOrUnreadableVersionIsUnknown(): void {
		$blank = $this->createMock(IAppManager::class);
		$blank->method('getAppVersion')->willReturn('');
		$this->assertSame('unknown', $this->loader($blank)->appVersion('stackiq'));

		$broken = $this->createMock(IAppManager::class);
		$broken->method('getAppVersion')->willThrowException(new RuntimeException('no app'));
		$this->assertSame('unknown', $this->loader($broken)->appVersion('stackiq'));
	}//end testABlankOrUnreadableVersionIsUnknown()
}//end class
