<?php

/**
 * Unit tests for HexIconService: cache lookup, app-glyph resolution and SVG fallback.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\WebPush
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\WebPush;

use OCA\OpenRegister\Notifications\Service\WebPush\HexIconService;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\WebPush\HexIconService
 */
final class HexIconServiceTest extends TestCase {

	/** @var IAppManager&MockObject */
	private IAppManager $appManager;

	/** @var IAppData&MockObject */
	private IAppData $appData;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** Temporary app root holding img/app.svg files. */
	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appData = $this->createMock(IAppData::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->root = sys_get_temp_dir() . '/hexicon-' . bin2hex(random_bytes(4));
		mkdir($this->root);
	}

	protected function tearDown(): void {
		foreach (glob($this->root . '/*/img/app.svg') ?: [] as $file) {
			unlink($file);
			rmdir(dirname($file));
			rmdir(dirname($file, 2));
		}
		rmdir($this->root);
		parent::tearDown();
	}

	private function makeApp(string $appId, string $svg): string {
		mkdir($this->root . '/' . $appId . '/img', 0777, true);
		file_put_contents($this->root . '/' . $appId . '/img/app.svg', $svg);
		return $this->root . '/' . $appId;
	}

	private function service(): HexIconService {
		return new HexIconService($this->appManager, $this->appData, $this->logger);
	}

	public function testCachedIconIsServedAsPngUnderTheIconKey(): void {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn('PNGBYTES');
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->expects($this->once())->method('fileExists')->with('myapp-icon.png')->willReturn(true);
		$folder->expects($this->once())->method('getFile')->with('myapp-icon.png')->willReturn($file);
		$this->appData->method('getFolder')->with('webpush-icons')->willReturn($folder);
		$this->appManager->expects($this->never())->method('getAppPath');

		$this->assertSame(['body' => 'PNGBYTES', 'mime' => 'image/png'], $this->service()->getIcon('MyApp'));
	}

	public function testBadgeUsesTheBadgeCacheKeyAndSanitisesTheAppId(): void {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn('BADGE');
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->expects($this->once())->method('fileExists')->with('evilapp-badge.png')->willReturn(true);
		$folder->method('getFile')->with('evilapp-badge.png')->willReturn($file);
		$this->appData->method('getFolder')->willReturn($folder);

		$this->assertSame(['body' => 'BADGE', 'mime' => 'image/png'], $this->service()->getBadge('../Evil App!'));
	}

	public function testCacheMissWithoutImagickFallsBackToTheRawAppSvg(): void {
		if (extension_loaded('imagick') === true) {
			$this->markTestSkipped('Imagick installed: the composite path runs instead of the SVG fallback.');
		}
		$appPath = $this->makeApp('myapp', '<svg id="myapp"/>');
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('fileExists')->willReturn(false);
		$folder->expects($this->never())->method('newFile');
		$this->appData->method('getFolder')->willReturn($folder);
		$this->appManager->method('getAppPath')->with('myapp')->willReturn($appPath);

		$this->assertSame(['body' => '<svg id="myapp"/>', 'mime' => 'image/svg+xml'], $this->service()->getIcon('myapp'));
	}

	public function testCacheReadFailureIsLoggedAndFallsThroughToTheSvg(): void {
		if (extension_loaded('imagick') === true) {
			$this->markTestSkipped('Imagick installed: the composite path runs instead of the SVG fallback.');
		}
		$appPath = $this->makeApp('myapp', '<svg id="fresh"/>');
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('fileExists')->willReturn(true);
		$folder->method('getFile')->willThrowException(new NotFoundException('gone'));
		$this->appData->method('getFolder')->willReturn($folder);
		$this->appManager->method('getAppPath')->willReturn($appPath);
		$this->logger->expects($this->once())->method('debug')
			->with($this->stringContains('cache read failed: gone'));

		$this->assertSame('<svg id="fresh"/>', $this->service()->getIcon('myapp')['body']);
	}

	public function testMissingCacheFolderIsCreated(): void {
		if (extension_loaded('imagick') === true) {
			$this->markTestSkipped('Imagick installed: the composite path runs instead of the SVG fallback.');
		}
		$appPath = $this->makeApp('myapp', '<svg id="a"/>');
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('fileExists')->willReturn(false);
		$this->appData->method('getFolder')->willThrowException(new NotFoundException());
		$this->appData->expects($this->once())->method('newFolder')->with('webpush-icons')->willReturn($folder);
		$this->appManager->method('getAppPath')->willReturn($appPath);

		$this->assertSame('<svg id="a"/>', $this->service()->getIcon('myapp')['body']);
	}

	public function testUnavailableAppDataIsLoggedAndTheIconStillResolves(): void {
		if (extension_loaded('imagick') === true) {
			$this->markTestSkipped('Imagick installed: the composite path runs instead of the SVG fallback.');
		}
		$appPath = $this->makeApp('myapp', '<svg id="b"/>');
		$this->appData->method('getFolder')->willThrowException(new NotFoundException());
		$this->appData->method('newFolder')->willThrowException(new \RuntimeException('ro fs'));
		$this->appManager->method('getAppPath')->willReturn($appPath);
		$this->logger->expects($this->once())->method('debug')
			->with($this->stringContains('appdata cache folder unavailable: ro fs'));

		$this->assertSame('<svg id="b"/>', $this->service()->getIcon('myapp')['body']);
	}

	public function testUnknownAppFallsBackToTheOpenregisterGlyph(): void {
		if (extension_loaded('imagick') === true) {
			$this->markTestSkipped('Imagick installed: the composite path runs instead of the SVG fallback.');
		}
		$orPath = $this->makeApp('openregister', '<svg id="or"/>');
		$this->appData->method('getFolder')->willThrowException(new \RuntimeException('x'));
		$this->appManager->method('getAppPath')->willReturnCallback(
			function (string $appId) use ($orPath): string {
				if ($appId === 'openregister') {
					return $orPath;
				}
				throw new AppPathNotFoundException($appId);
			}
		);

		$this->assertSame(['body' => '<svg id="or"/>', 'mime' => 'image/svg+xml'], $this->service()->getIcon('ghost'));
	}

	public function testAppPathWithoutSvgAlsoFallsBackToTheOpenregisterGlyph(): void {
		if (extension_loaded('imagick') === true) {
			$this->markTestSkipped('Imagick installed: the composite path runs instead of the SVG fallback.');
		}
		$orPath = $this->makeApp('openregister', '<svg id="or2"/>');
		$this->appData->method('getFolder')->willThrowException(new \RuntimeException('x'));
		$this->appManager->method('getAppPath')->willReturnMap(
			[
				['noglyph', $this->root . '/does-not-exist'],
				['openregister', $orPath],
			]
		);

		$this->assertSame('<svg id="or2"/>', $this->service()->getIcon('noglyph')['body']);
	}

	public function testNoGlyphAnywhereYieldsTheOnePixelSvgAndLogsTheMissingFallback(): void {
		if (extension_loaded('imagick') === true) {
			$this->markTestSkipped('Imagick installed: the composite path runs instead of the SVG fallback.');
		}
		$this->appData->method('getFolder')->willThrowException(new \RuntimeException('x'));
		$this->appManager->method('getAppPath')->willThrowException(new AppPathNotFoundException('none'));
		$debug = [];
		$this->logger->method('debug')->willReturnCallback(
			function (string $msg) use (&$debug): void {
				$debug[] = $msg;
			}
		);

		$this->assertSame(
			['body' => '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>', 'mime' => 'image/svg+xml'],
			$this->service()->getBadge('ghost')
		);
		$this->assertStringContainsString('openregister app.svg fallback missing', end($debug));
	}

	public function testOpenregisterPathWithoutSvgYieldsTheOnePixelSvg(): void {
		if (extension_loaded('imagick') === true) {
			$this->markTestSkipped('Imagick installed: the composite path runs instead of the SVG fallback.');
		}
		$this->appData->method('getFolder')->willThrowException(new \RuntimeException('x'));
		$this->appManager->method('getAppPath')->willReturn($this->root . '/nowhere');

		$this->assertSame(
			'<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>',
			$this->service()->getIcon('ghost')['body']
		);
	}
}
