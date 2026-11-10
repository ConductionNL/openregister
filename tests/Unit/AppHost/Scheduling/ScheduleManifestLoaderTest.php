<?php

/**
 * Tests for ScheduleManifestLoader: which installed apps yield a schedule manifest, and the failures that yield none.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Scheduling;

use OCA\OpenRegister\AppHost\Scheduling\CronScheduleEvaluator;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleManifestLoader;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The loader over a mocked app manager and real manifest files in a temporary directory.
 */
class ScheduleManifestLoaderTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir().'/or-schedule-loader-'.getmypid().'-'.spl_object_id($this);
		mkdir($this->root);
	}

	protected function tearDown(): void {
		foreach (glob($this->root.'/*/src/manifest.json') as $file) {
			unlink($file);
			rmdir(dirname($file));
			rmdir(dirname($file, 2));
		}

		foreach (glob($this->root.'/*') as $dir) {
			rmdir($dir);
		}

		rmdir($this->root);
		parent::tearDown();
	}

	/**
	 * Write an app directory, with a manifest when $manifest is not null.
	 *
	 * @param string      $appId    The app id.
	 * @param string|null $manifest The raw manifest.json contents.
	 *
	 * @return string The app path.
	 */
	private function app(string $appId, ?string $manifest): string {
		$path = $this->root.'/'.$appId;
		mkdir($path);
		if ($manifest !== null) {
			mkdir($path.'/src');
			file_put_contents($path.'/src/manifest.json', $manifest);
		}

		return $path;
	}

	public function testLoadForAppParsesTheSchedulesOfAReadableManifest(): void {
		$path = $this->app('alpha', json_encode(['schedules' => [
			['id' => 'nightly', 'cron' => '0 2 * * *', 'action' => 'openconnector:synchronization'],
			['id' => 'broken', 'cron' => 'not-a-cron', 'action' => 'openconnector:synchronization'],
		]]));
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->once())->method('getAppPath')->with('alpha')->willReturn($path);
		$loader = new ScheduleManifestLoader($apps, new CronScheduleEvaluator(), $this->createMock(LoggerInterface::class));

		$manifest = $loader->loadForApp('alpha');

		$this->assertNotNull($manifest);
		$this->assertSame('alpha', $manifest->applicationId);
		$this->assertCount(1, $manifest->schedules);
		$this->assertSame('nightly', $manifest->schedules[0]->id);
		// The cron validator was passed through: the unparseable entry is a diagnostic, not a schedule.
		$this->assertSame(['Schedule "broken" has an unparseable cron expression.'], $manifest->diagnostics);
	}

	public function testLoadForAppIsNullWhenTheAppPathCannotBeFound(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->once())->method('getAppPath')->with('ghost')->willThrowException(new RuntimeException('no such app'));
		$loader = new ScheduleManifestLoader($apps, new CronScheduleEvaluator(), $this->createMock(LoggerInterface::class));

		$this->assertNull($loader->loadForApp('ghost'));
	}

	public function testLoadForAppIsNullWhenTheAppBundlesNoManifest(): void {
		$path = $this->app('bare', null);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->willReturn($path);
		$loader = new ScheduleManifestLoader($apps, new CronScheduleEvaluator(), $this->createMock(LoggerInterface::class));

		$this->assertNull($loader->loadForApp('bare'));
	}

	public function testLoadAllOnDiskKeepsOnlyAppsThatDeclareSchedules(): void {
		$paths = [
			'with'    => $this->app('with', json_encode(['schedules' => [['id' => 'a', 'interval' => 60, 'action' => 'openconnector:synchronization']]])),
			'without' => $this->app('without', json_encode(['name' => 'no schedules'])),
			'bare'    => $this->app('bare', null),
		];
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->once())->method('getInstalledApps')->willReturn(['with', 'without', 'bare', 'gone']);
		$apps->method('getAppPath')->willReturnCallback(
			static function (string $appId) use ($paths): string {
				if (isset($paths[$appId]) === false) {
					throw new RuntimeException('not on disk');
				}

				return $paths[$appId];
			}
		);
		$loader = new ScheduleManifestLoader($apps, new CronScheduleEvaluator(), $this->createMock(LoggerInterface::class));

		$manifests = $loader->loadAllOnDisk();

		$this->assertCount(1, $manifests);
		$this->assertSame('with', $manifests[0]->applicationId);
		$this->assertSame('a', $manifests[0]->schedules[0]->id);
	}

	public function testLoadAllOnDiskIsEmptyAndWarnsWhenAppsCannotBeEnumerated(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->once())->method('getInstalledApps')->willThrowException(new RuntimeException('db down'));
		$apps->expects($this->never())->method('getAppPath');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('[AppHost\\Scheduling] Failed to enumerate installed apps: db down', $this->isType('array'));
		$loader = new ScheduleManifestLoader($apps, new CronScheduleEvaluator(), $logger);

		$this->assertSame([], $loader->loadAllOnDisk());
	}
}
