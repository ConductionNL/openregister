<?php

/**
 * OpenRegister HexIconService
 *
 * Composites a per-originApp notification icon: the app's white monochrome
 * `img/app.svg` glyph laid onto the Conduction cobalt hexagon (`#21468B`)
 * and rasterised to PNG (Web Push `icon`/`badge` require a raster URL, not an
 * inline SVG). The rendered PNG is cached in appdata keyed by appId, so a hot
 * notification path never recomposites.
 *
 * Imagick is guarded with `extension_loaded('imagick')`. When Imagick is
 * unavailable the service degrades gracefully: it returns the raw app.svg
 * bytes so the notification still shows the app glyph (just un-hexed) rather
 * than failing. This is the documented ADR-031 imperative exception (image
 * compositing is not expressible as schema metadata).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\WebPush
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/web-push-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\WebPush;

use Imagick;
use ImagickPixel;
use OCP\App\IAppManager;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use Psr\Log\LoggerInterface;

/**
 * Builds + caches the cobalt-hex notification icon/badge per originApp.
 *
 * @spec openspec/specs/web-push-delivery/spec.md
 */
class HexIconService {

	/**
	 * Conduction cobalt hex fill colour.
	 *
	 * @var string
	 */
	private const COBALT = '#21468B';

	/**
	 * Appdata folder name for cached icons.
	 *
	 * @var string
	 */
	private const CACHE_FOLDER = 'webpush-icons';

	/**
	 * Rendered icon edge length in pixels (square).
	 *
	 * @var int
	 */
	private const ICON_SIZE = 192;

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager App manager for resolving app.svg paths.
	 * @param IAppData $appData App data for the rendered-PNG cache.
	 * @param LoggerInterface $logger Logger for compositing diagnostics.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IAppData $appData,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Return the icon PNG bytes (cobalt hex + white glyph) for an app.
	 *
	 * Cached: the second request for the same app is served from appdata.
	 *
	 * @param string $appId The originApp id.
	 *
	 * @return array{body: string, mime: string} The icon body + MIME type.
	 *
	 * @spec openspec/specs/web-push-delivery/spec.md
	 */
	public function getIcon(string $appId): array {
		return $this->getOrRender(appId: $appId, badge: false);
	}//end getIcon()

	/**
	 * Return the monochrome badge PNG bytes for an app.
	 *
	 * @param string $appId The originApp id.
	 *
	 * @return array{body: string, mime: string} The badge body + MIME type.
	 *
	 * @spec openspec/specs/web-push-delivery/spec.md
	 */
	public function getBadge(string $appId): array {
		return $this->getOrRender(appId: $appId, badge: true);
	}//end getBadge()

	/**
	 * Resolve from cache or render + cache.
	 *
	 * @param string $appId The originApp id.
	 * @param bool $badge Whether to render the monochrome badge variant.
	 *
	 * @return array{body: string, mime: string}
	 *
	 * @spec openspec/specs/web-push-delivery/spec.md
	 */
	private function getOrRender(string $appId, bool $badge): array {
		$safeApp = preg_replace('/[^a-z0-9_-]/', '', strtolower($appId)) ?? 'openregister';
		$entry = new IconCacheEntry(folder: $this->cacheFolder(), key: $safeApp . $this->cacheSuffix(badge: $badge));

		$cached = $this->readCached(entry: $entry);
		if ($cached !== null) {
			return $cached;
		}

		$png = $this->render(appId: $safeApp, badge: $badge);
		if ($png === null) {
			return $this->rawAppSvg(appId: $safeApp);
		}

		$this->writeCached(entry: $entry, png: $png);

		return ['body' => $png, 'mime' => 'image/png'];
	}//end getOrRender()

	/**
	 * The cache file suffix of the icon or the badge.
	 *
	 * @param bool $badge Whether the badge variant is meant.
	 *
	 * @return string
	 */
	private function cacheSuffix(bool $badge): string {
		$suffix = '-icon.png';
		if ($badge === true) {
			$suffix = '-badge.png';
		}

		return $suffix;
	}//end cacheSuffix()

	/**
	 * The cached PNG, or null when it is not cached or cannot be read.
	 *
	 * @param IconCacheEntry $entry The icon's cache entry.
	 *
	 * @return array{body: string, mime: string}|null
	 */
	private function readCached(IconCacheEntry $entry): ?array {
		if ($entry->exists() === true) {
			try {
				return ['body' => $entry->read(), 'mime' => 'image/png'];
			} catch (\Throwable $e) {
				$this->logger->debug('[HexIconService] cache read failed: ' . $e->getMessage());
			}
		}

		return null;
	}//end readCached()

	/**
	 * Cache a rendered PNG; a failed write is logged and otherwise ignored.
	 *
	 * @param IconCacheEntry $entry The icon's cache entry.
	 * @param string         $png   The rendered PNG bytes.
	 *
	 * @return void
	 */
	private function writeCached(IconCacheEntry $entry, string $png): void {
		try {
			$entry->write(png: $png);
		} catch (\Throwable $e) {
			$this->logger->debug('[HexIconService] cache write failed: ' . $e->getMessage());
		}
	}//end writeCached()

	/**
	 * Render the PNG, or null when Imagick is unavailable or compositing failed.
	 *
	 * @param string $appId The sanitised originApp id.
	 * @param bool   $badge Whether to render the monochrome badge variant.
	 *
	 * @return string|null PNG bytes.
	 */
	private function render(string $appId, bool $badge): ?string {
		// Imagick unavailable → degrade to the raw app.svg (SVG MIME).
		if (extension_loaded('imagick') === false) {
			return null;
		}

		return $this->composite(appId: $appId, badge: $badge);
	}//end render()

	/**
	 * Composite the white app glyph onto the cobalt hexagon via Imagick.
	 *
	 * @param string $appId The originApp id.
	 * @param bool $badge Whether to render the monochrome badge (transparent bg).
	 *
	 * @return string|null PNG bytes, or null when compositing failed.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @spec openspec/specs/web-push-delivery/spec.md
	 */
	private function composite(string $appId, bool $badge): ?string {
		$svgPath = $this->appSvgPath(appId: $appId);
		if ($svgPath === null) {
			return null;
		}

		try {
			return $this->renderIcon(svgPath: $svgPath, badge: $badge);
		} catch (\Throwable $e) {
			$this->logger->warning('[HexIconService] Imagick compositing failed: ' . $e->getMessage());
			return null;
		}
	}//end composite()

	/**
	 * Render the icon (glyph on hex) or the badge (glyph on transparency) as PNG.
	 *
	 * @param string $svgPath The app glyph's SVG file.
	 * @param bool   $badge   Whether to render the badge (no hex).
	 *
	 * @return string PNG bytes.
	 *
	 * @throws \ImagickException When Imagick cannot render.
	 */
	private function renderIcon(string $svgPath, bool $badge): string {
		$size = self::ICON_SIZE;

		// Base canvas: the cobalt hexagon for the icon, transparent for the badge.
		$canvas = new Imagick();
		$canvas->newImage($size, $size, new ImagickPixel('transparent'), 'png');

		if ($badge === false) {
			$this->drawHex(canvas: $canvas);
		}

		$this->drawGlyph(canvas: $canvas, svgPath: $svgPath);

		$canvas->setImageFormat('png');
		$blob = $canvas->getImageBlob();
		$canvas->clear();

		return $blob;
	}//end renderIcon()

	/**
	 * Draw the cobalt hexagon onto the canvas, fitted to its height and centred.
	 *
	 * @param Imagick $canvas The square canvas.
	 *
	 * @return void
	 */
	private function drawHex(Imagick $canvas): void {
		$size = self::ICON_SIZE;
		$hex = new Imagick();
		$hex->setBackgroundColor(new ImagickPixel('transparent'));
		// The brand hex is a regular pointy-top hexagon with a 100:115
		// (width:height) ratio. Rendering it into a square canvas would
		// stretch it ~15% horizontally and distort the shape, so fit it
		// to the canvas height and centre it horizontally instead.
		$hexWidth = (int)round($size * (100 / 115));
		$hex->readImageBlob($this->hexSvg(width: $hexWidth, height: $size));
		$hex->setImageFormat('png');
		$hexX = (int)(($size - $hexWidth) / 2);
		$canvas->compositeImage($hex, \Imagick::COMPOSITE_OVER, $hexX, 0);
		$hex->clear();
	}//end drawHex()

	/**
	 * The SVG of the cobalt hexagon at a size.
	 *
	 * @param int $width  Width in pixels.
	 * @param int $height Height in pixels.
	 *
	 * @return string
	 */
	private function hexSvg(int $width, int $height): string {
		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 115" width="%d" height="%d">'
			. '<polygon points="50,0 100,28.75 100,86.25 50,115 0,86.25 0,28.75" fill="%s"/></svg>',
			$width,
			$height,
			self::COBALT
		);
	}//end hexSvg()

	/**
	 * Draw the app glyph, forced to white, centred onto the canvas.
	 *
	 * @param Imagick $canvas  The canvas.
	 * @param string  $svgPath The app glyph's SVG file.
	 *
	 * @return void
	 */
	private function drawGlyph(Imagick $canvas, string $svgPath): void {
		$glyph = $this->loadGlyph(svgPath: $svgPath);

		// Force the glyph to white (monochrome) so it reads on cobalt / as a badge.
		$glyph->setImageAlphaChannel(\Imagick::ALPHACHANNEL_EXTRACT);
		$white = new Imagick();
		$white->newImage($glyph->getImageWidth(), $glyph->getImageHeight(), new ImagickPixel('white'), 'png');
		$white->compositeImage($glyph, \Imagick::COMPOSITE_COPYOPACITY, 0, 0);

		$offset = (int)((self::ICON_SIZE - $white->getImageWidth()) / 2);
		$canvas->compositeImage($white, \Imagick::COMPOSITE_OVER, $offset, $offset);

		$glyph->clear();
		$white->clear();
	}//end drawGlyph()

	/**
	 * Read the app glyph and scale it to ~60% of the canvas.
	 *
	 * @param string $svgPath The app glyph's SVG file.
	 *
	 * @return Imagick The scaled glyph.
	 */
	private function loadGlyph(string $svgPath): Imagick {
		$glyph = new Imagick();
		$glyph->setBackgroundColor(new ImagickPixel('transparent'));
		$glyph->readImage($svgPath);
		$glyph->setImageFormat('png');
		$glyphSize = (int)(self::ICON_SIZE * 0.6);
		$glyph->resizeImage($glyphSize, $glyphSize, \Imagick::FILTER_LANCZOS, 1, true);

		return $glyph;
	}//end loadGlyph()

	/**
	 * Resolve the absolute filesystem path to an app's img/app.svg.
	 *
	 * @param string $appId The app id.
	 *
	 * @return string|null The path, or null when the app / file is absent.
	 */
	private function appSvgPath(string $appId): ?string {
		try {
			$path = $this->appManager->getAppPath($appId) . '/img/app.svg';
		} catch (\Throwable $e) {
			$path = '';
		}

		if ($path !== '' && is_file($path) === true) {
			return $path;
		}

		// Fall back to openregister's own glyph.
		try {
			$fallback = $this->appManager->getAppPath('openregister') . '/img/app.svg';
			if (is_file($fallback) === true) {
				return $fallback;
			}
		} catch (\Throwable $e) {
			$this->logger->debug('[HexIconService] openregister app.svg fallback missing: ' . $e->getMessage());
		}

		return null;
	}//end appSvgPath()

	/**
	 * Degrade to the raw app.svg bytes when Imagick is unavailable.
	 *
	 * @param string $appId The app id.
	 *
	 * @return array{body: string, mime: string}
	 */
	private function rawAppSvg(string $appId): array {
		$path = $this->appSvgPath(appId: $appId);
		if ($path !== null) {
			$bytes = file_get_contents($path);
			if ($bytes !== false) {
				return ['body' => $bytes, 'mime' => 'image/svg+xml'];
			}
		}

		// Minimal valid empty PNG-less fallback: a tiny transparent SVG.
		return [
			'body' => '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>',
			'mime' => 'image/svg+xml',
		];
	}//end rawAppSvg()

	/**
	 * Get (creating if needed) the appdata cache folder, or null on failure.
	 *
	 * @return ISimpleFolder|null The cache folder.
	 */
	private function cacheFolder(): ?ISimpleFolder {
		try {
			try {
				return $this->appData->getFolder(self::CACHE_FOLDER);
			} catch (NotFoundException $e) {
				return $this->appData->newFolder(self::CACHE_FOLDER);
			}
		} catch (\Throwable $e) {
			$this->logger->debug('[HexIconService] appdata cache folder unavailable: ' . $e->getMessage());
			return null;
		}
	}//end cacheFolder()
}//end class
