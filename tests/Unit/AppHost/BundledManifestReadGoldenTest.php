<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\OpenRegister\AppHost\Observability\ObservabilityManifest;
use OCA\OpenRegister\AppHost\Scheduling\CronScheduleEvaluator;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleManifestLoader;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins how the observability and schedule loaders read an app's bundled
 * src/manifest.json: decoded when valid, absent when unreadable or not a
 * JSON object (with a warning), absent when the app has no path.
 *
 * @covers \OCA\OpenRegister\AppHost\Observability\ManifestLoader
 * @covers \OCA\OpenRegister\AppHost\Scheduling\ScheduleManifestLoader
 */
class BundledManifestReadGoldenTest extends TestCase
{

    private string $appPath;

    /**
     * Create an app directory with an empty src/.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appPath = sys_get_temp_dir() . '/or-bundled-manifest-' . bin2hex(random_bytes(6));
        mkdir($this->appPath . '/src', 0777, true);
    }//end setUp()

    /**
     * Remove the app directory.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        @unlink($this->appPath . '/src/manifest.json');
        rmdir($this->appPath . '/src');
        rmdir($this->appPath);
    }//end tearDown()

    /**
     * A valid manifest is decoded by both loaders.
     *
     * @return void
     */
    public function testValidManifestIsDecoded(): void
    {
        $manifest = ['observability' => ['checks' => []], 'schedules' => []];
        file_put_contents($this->appPath . '/src/manifest.json', json_encode($manifest));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $this->assertEquals(
            ObservabilityManifest::fromManifest(appId: 'demo', manifest: $manifest),
            (new ManifestLoader(appManager: $this->apps(), logger: $logger))->load('demo')
        );
        $this->assertNotNull($this->scheduleLoader(logger: $logger)->loadForApp('demo'));
    }//end testValidManifestIsDecoded()

    /**
     * A missing manifest is absent, without a warning.
     *
     * @return void
     */
    public function testMissingManifestIsAbsent(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $this->assertEquals(
            ObservabilityManifest::defaults(appId: 'demo', manifest: []),
            (new ManifestLoader(appManager: $this->apps(), logger: $logger))->load('demo')
        );
        $this->assertNull($this->scheduleLoader(logger: $logger)->loadForApp('demo'));
    }//end testMissingManifestIsAbsent()

    /**
     * Invalid JSON, or JSON that is not an object, is absent with one warning per loader.
     *
     * @return void
     */
    public function testInvalidManifestIsAbsentWithAWarning(): void
    {
        foreach (['{not json', '"a string"'] as $raw) {
            file_put_contents($this->appPath . '/src/manifest.json', $raw);

            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects($this->once())->method('warning')->with('[AppHost\\ManifestLoader] manifest.json for "demo" is invalid JSON');
            $this->assertEquals(
                ObservabilityManifest::defaults(appId: 'demo', manifest: []),
                (new ManifestLoader(appManager: $this->apps(), logger: $logger))->load('demo')
            );

            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects($this->once())->method('warning')->with('[AppHost\\Scheduling] manifest.json for "demo" is invalid JSON');
            $this->assertNull($this->scheduleLoader(logger: $logger)->loadForApp('demo'));
        }
    }//end testInvalidManifestIsAbsentWithAWarning()

    /**
     * An app without a path is absent; only the observability loader says so, at debug level.
     *
     * @return void
     */
    public function testAppWithoutPathIsAbsent(): void
    {
        $apps = $this->createMock(IAppManager::class);
        $apps->method('getAppPath')->willThrowException(new RuntimeException('gone'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('debug')->with('[AppHost\\ManifestLoader] App "demo" path not found: gone');
        $this->assertEquals(
            ObservabilityManifest::defaults(appId: 'demo', manifest: []),
            (new ManifestLoader(appManager: $apps, logger: $logger))->load('demo')
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method($this->anything());
        $loader = new ScheduleManifestLoader(appManager: $apps, cron: new CronScheduleEvaluator(), logger: $logger);
        $this->assertNull($loader->loadForApp('demo'));
    }//end testAppWithoutPathIsAbsent()

    /**
     * An app manager that places "demo" in the temp app directory.
     *
     * @return IAppManager The app manager
     */
    private function apps(): IAppManager
    {
        $apps = $this->createMock(IAppManager::class);
        $apps->method('getAppPath')->willReturn($this->appPath);

        return $apps;
    }//end apps()

    /**
     * A schedule loader over the temp app directory.
     *
     * @param LoggerInterface $logger The logger
     *
     * @return ScheduleManifestLoader The loader
     */
    private function scheduleLoader(LoggerInterface $logger): ScheduleManifestLoader
    {
        return new ScheduleManifestLoader(appManager: $this->apps(), cron: new CronScheduleEvaluator(), logger: $logger);
    }//end scheduleLoader()
}//end class
