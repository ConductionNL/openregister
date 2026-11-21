<?php

/**
 * Unit tests for finding an app's leaf bundle on disk.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Integration\LeafBundle;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * A bundle exists when `js/<app>-leaves.js` is in the app's folder; an app with no path has none.
 *
 * @covers \OCA\OpenRegister\Service\Integration\LeafBundle
 */
class LeafBundleTest extends TestCase {

	/**
	 * A temporary app folder.
	 *
	 * @var string
	 */
	private string $app;

	/**
	 * Create an app folder with a js directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->app = sys_get_temp_dir() . '/or-leafbundle-' . bin2hex(random_bytes(4));
		mkdir($this->app . '/js', 0777, true);

	}//end setUp()

	/**
	 * Remove the app folder.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ((array)glob($this->app . '/js/*') as $file) {
			unlink((string)$file);
		}

		rmdir($this->app . '/js');
		rmdir($this->app);

	}//end tearDown()

	/**
	 * The bundle is found only when its file is there.
	 *
	 * @return void
	 */
	public function testTheBundleIsFoundOnDisk(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->with('acme')->willReturn($this->app);
		$bundle = new LeafBundle($apps);

		$this->assertSame('acme-leaves.js', $bundle->expectedFileName('acme'));
		$this->assertFalse($bundle->existsFor('acme'));

		touch($this->app . '/js/acme-leaves.js');
		$this->assertTrue($bundle->existsFor('acme'));

	}//end testTheBundleIsFoundOnDisk()

	/**
	 * An app the app manager cannot locate has no path and no bundle.
	 *
	 * @return void
	 */
	public function testAnUnknownAppHasNoBundle(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->willThrowException(new AppPathNotFoundException('acme'));
		$bundle = new LeafBundle($apps);

		$this->assertNull($bundle->pathFor('acme'));
		$this->assertFalse($bundle->existsFor('acme'));

	}//end testAnUnknownAppHasNoBundle()
}//end class
