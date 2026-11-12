<?php

/**
 * Characterisation tests for HexIconService's Imagick compositing path.
 *
 * Pins what a rendered icon and badge look like (size, hex fill, white glyph,
 * transparent background), that a rendered PNG is cached, and how a failing
 * render or cache write degrades. Pixels are compared, never PNG bytes, so the
 * tests do not depend on the ImageMagick build.
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

use Imagick;
use OCA\OpenRegister\Notifications\Service\WebPush\HexIconService;
use OCP\App\IAppManager;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\WebPush\HexIconService
 */
final class HexIconCompositeTest extends TestCase {

	/** A glyph that fills its whole view box. */
	private const SQUARE_GLYPH = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 10 10">'
		. '<rect width="10" height="10" fill="black"/></svg>';

	/** @var IAppManager&MockObject */
	private IAppManager $appManager;

	/** @var IAppData&MockObject */
	private IAppData $appData;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var ISimpleFolder&MockObject */
	private ISimpleFolder $folder;

	/** Temporary app root holding img/app.svg files. */
	private string $root;

	protected function setUp(): void {
		parent::setUp();
		if (extension_loaded('imagick') === false) {
			$this->markTestSkipped('Imagick is not installed: the compositing path cannot run.');
		}

		$this->appManager = $this->createMock(IAppManager::class);
		$this->appData = $this->createMock(IAppData::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->folder = $this->createMock(ISimpleFolder::class);
		$this->folder->method('fileExists')->willReturn(false);
		$this->appData->method('getFolder')->willReturn($this->folder);
		$this->root = sys_get_temp_dir() . '/hexcomposite-' . bin2hex(random_bytes(4));
		mkdir($this->root);
	}

	protected function tearDown(): void {
		foreach (glob($this->root . '/*/img/app.svg') ?: [] as $file) {
			unlink($file);
			rmdir(dirname($file));
			rmdir(dirname($file, 2));
		}
		if (is_dir($this->root) === true) {
			rmdir($this->root);
		}
		parent::tearDown();
	}

	private function makeApp(string $appId, string $svg): void {
		mkdir($this->root . '/' . $appId . '/img', 0777, true);
		file_put_contents($this->root . '/' . $appId . '/img/app.svg', $svg);
		$this->appManager->method('getAppPath')->willReturn($this->root . '/' . $appId);
	}

	private function service(): HexIconService {
		return new HexIconService($this->appManager, $this->appData, $this->logger);
	}

	/**
	 * The RGBA of one pixel, alpha rounded to 0 or 1.
	 *
	 * @return array{0: int, 1: int, 2: int, 3: int}
	 */
	private function pixel(string $png, int $x, int $y): array {
		$image = new Imagick();
		$image->readImageBlob($png);
		$colour = $image->getImagePixelColor($x, $y)->getColor(2);
		$image->clear();

		return [(int)$colour['r'], (int)$colour['g'], (int)$colour['b'], (int)round($colour['a'] / 255)];
	}

	private function dimensions(string $png): array {
		$image = new Imagick();
		$image->readImageBlob($png);
		$size = [$image->getImageWidth(), $image->getImageHeight(), strtolower($image->getImageFormat())];
		$image->clear();

		return $size;
	}

	public function testIconIsAWhiteGlyphOnACobaltHexAndIsCached(): void {
		$this->makeApp('myapp', self::SQUARE_GLYPH);
		$cached = [];
		$this->folder->expects($this->once())->method('newFile')
			->willReturnCallback(function (string $name, string $body) use (&$cached) {
				$cached[$name] = $body;
				return null;
			});

		$icon = $this->service()->getIcon('myapp');

		$this->assertSame('image/png', $icon['mime']);
		$this->assertSame(['myapp-icon.png' => $icon['body']], $cached);
		$this->assertSame([192, 192, 'png'], $this->dimensions($icon['body']));
		// Glyph centre: white and opaque.
		$this->assertSame([255, 255, 255, 1], $this->pixel($icon['body'], 96, 96));
		// Inside the hex, left of the glyph: cobalt #21468B.
		$this->assertSame([0x21, 0x46, 0x8B, 1], $this->pixel($icon['body'], 20, 96));
		// Left of the hex (the hex is narrower than the canvas): transparent.
		$this->assertSame(0, $this->pixel($icon['body'], 2, 96)[3]);
		// Top corner, outside the pointy-top hex: transparent.
		$this->assertSame(0, $this->pixel($icon['body'], 0, 0)[3]);
	}

	public function testBadgeIsTheWhiteGlyphOnTransparencyAndIsCachedUnderTheBadgeKey(): void {
		$this->makeApp('myapp', self::SQUARE_GLYPH);
		$this->folder->expects($this->once())->method('newFile')->with('myapp-badge.png', $this->isType('string'));

		$badge = $this->service()->getBadge('myapp');

		$this->assertSame('image/png', $badge['mime']);
		$this->assertSame([192, 192, 'png'], $this->dimensions($badge['body']));
		$this->assertSame([255, 255, 255, 1], $this->pixel($badge['body'], 96, 96));
		$this->assertSame(0, $this->pixel($badge['body'], 20, 96)[3]);
	}

	public function testUnreadableGlyphIsLoggedAndFallsBackToTheRawSvg(): void {
		$this->makeApp('myapp', 'this is not an svg');
		$this->folder->expects($this->never())->method('newFile');
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringStartsWith('[HexIconService] Imagick compositing failed: '));

		$this->assertSame(
			['body' => 'this is not an svg', 'mime' => 'image/svg+xml'],
			$this->service()->getIcon('myapp')
		);
	}

	public function testNoGlyphAnywhereSkipsCompositingAndYieldsTheOnePixelSvg(): void {
		$this->appManager->method('getAppPath')->willReturn($this->root . '/missing');
		$this->folder->expects($this->never())->method('newFile');
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame(
			['body' => '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>', 'mime' => 'image/svg+xml'],
			$this->service()->getIcon('myapp')
		);
	}

	public function testCacheWriteFailureIsLoggedAndTheRenderedPngStillServed(): void {
		$this->makeApp('myapp', self::SQUARE_GLYPH);
		$this->folder->method('newFile')->willThrowException(new \RuntimeException('disk full'));
		$this->logger->expects($this->once())->method('debug')->with('[HexIconService] cache write failed: disk full');

		$icon = $this->service()->getIcon('myapp');

		$this->assertSame('image/png', $icon['mime']);
		$this->assertSame([192, 192, 'png'], $this->dimensions($icon['body']));
	}
}
