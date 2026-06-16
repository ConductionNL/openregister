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
 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\WebPush;

use OCP\App\IAppManager;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use Psr\Log\LoggerInterface;

/**
 * Builds + caches the cobalt-hex notification icon/badge per originApp.
 *
 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
 */
class HexIconService
{

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
     * @param IAppManager     $appManager App manager for resolving app.svg paths.
     * @param IAppData        $appData    App data for the rendered-PNG cache.
     * @param LoggerInterface $logger     Logger for compositing diagnostics.
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly IAppData $appData,
        private readonly LoggerInterface $logger
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
     * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
     */
    public function getIcon(string $appId): array
    {
        return $this->getOrRender(appId: $appId, badge: false);
    }//end getIcon()

    /**
     * Return the monochrome badge PNG bytes for an app.
     *
     * @param string $appId The originApp id.
     *
     * @return array{body: string, mime: string} The badge body + MIME type.
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
     */
    public function getBadge(string $appId): array
    {
        return $this->getOrRender(appId: $appId, badge: true);
    }//end getBadge()

    /**
     * Resolve from cache or render + cache.
     *
     * @param string $appId The originApp id.
     * @param bool   $badge Whether to render the monochrome badge variant.
     *
     * @return array{body: string, mime: string}
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
     */
    private function getOrRender(string $appId, bool $badge): array
    {
        $safeApp = preg_replace('/[^a-z0-9_-]/', '', strtolower($appId)) ?? 'openregister';
        $suffix  = '-icon.png';
        if ($badge === true) {
            $suffix = '-badge.png';
        }

        $cacheKey = $safeApp.$suffix;

        $folder = $this->cacheFolder();
        if ($folder !== null && $folder->fileExists($cacheKey) === true) {
            try {
                return ['body' => $folder->getFile($cacheKey)->getContent(), 'mime' => 'image/png'];
            } catch (\Throwable $e) {
                $this->logger->debug('[HexIconService] cache read failed: '.$e->getMessage());
            }
        }

        // Imagick unavailable → degrade to the raw app.svg (SVG MIME).
        if (extension_loaded('imagick') === false) {
            return $this->rawAppSvg(appId: $safeApp);
        }

        $png = $this->composite(appId: $safeApp, badge: $badge);
        if ($png === null) {
            return $this->rawAppSvg(appId: $safeApp);
        }

        if ($folder !== null) {
            try {
                $folder->newFile($cacheKey, $png);
            } catch (\Throwable $e) {
                $this->logger->debug('[HexIconService] cache write failed: '.$e->getMessage());
            }
        }

        return ['body' => $png, 'mime' => 'image/png'];
    }//end getOrRender()

    /**
     * Composite the white app glyph onto the cobalt hexagon via Imagick.
     *
     * @param string $appId The originApp id.
     * @param bool   $badge Whether to render the monochrome badge (transparent bg).
     *
     * @return string|null PNG bytes, or null when compositing failed.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
     */
    private function composite(string $appId, bool $badge): ?string
    {
        $svgPath = $this->appSvgPath(appId: $appId);
        if ($svgPath === null) {
            return null;
        }

        try {
            $size = self::ICON_SIZE;

            // Base canvas: the cobalt hexagon for the icon, transparent for the badge.
            $canvas = new \Imagick();
            $canvas->newImage($size, $size, new \ImagickPixel('transparent'), 'png');

            if ($badge === false) {
                $hex = new \Imagick();
                $hex->setBackgroundColor(new \ImagickPixel('transparent'));
                $hexSvg = sprintf(
                    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 115" width="%d" height="%d">'
                    .'<polygon points="50,0 100,28.75 100,86.25 50,115 0,86.25 0,28.75" fill="%s"/></svg>',
                    $size,
                    $size,
                    self::COBALT
                );
                $hex->readImageBlob($hexSvg);
                $hex->setImageFormat('png');
                $canvas->compositeImage($hex, \Imagick::COMPOSITE_OVER, 0, 0);
                $hex->clear();
            }

            // The app glyph, tinted white, scaled to ~60% of the canvas and centred.
            $glyph = new \Imagick();
            $glyph->setBackgroundColor(new \ImagickPixel('transparent'));
            $glyph->readImage($svgPath);
            $glyph->setImageFormat('png');
            $glyphSize = (int) ($size * 0.6);
            $glyph->resizeImage($glyphSize, $glyphSize, \Imagick::FILTER_LANCZOS, 1, true);

            // Force the glyph to white (monochrome) so it reads on cobalt / as a badge.
            $glyph->setImageAlphaChannel(\Imagick::ALPHACHANNEL_EXTRACT);
            $white = new \Imagick();
            $white->newImage($glyph->getImageWidth(), $glyph->getImageHeight(), new \ImagickPixel('white'), 'png');
            $white->compositeImage($glyph, \Imagick::COMPOSITE_COPYOPACITY, 0, 0);

            $offset = (int) (($size - $white->getImageWidth()) / 2);
            $canvas->compositeImage($white, \Imagick::COMPOSITE_OVER, $offset, $offset);

            $canvas->setImageFormat('png');
            $blob = $canvas->getImageBlob();

            $glyph->clear();
            $white->clear();
            $canvas->clear();

            return $blob;
        } catch (\Throwable $e) {
            $this->logger->warning('[HexIconService] Imagick compositing failed: '.$e->getMessage());
            return null;
        }//end try
    }//end composite()

    /**
     * Resolve the absolute filesystem path to an app's img/app.svg.
     *
     * @param string $appId The app id.
     *
     * @return string|null The path, or null when the app / file is absent.
     */
    private function appSvgPath(string $appId): ?string
    {
        try {
            $path = $this->appManager->getAppPath($appId).'/img/app.svg';
        } catch (\Throwable $e) {
            $path = '';
        }

        if ($path !== '' && is_file($path) === true) {
            return $path;
        }

        // Fall back to openregister's own glyph.
        try {
            $fallback = $this->appManager->getAppPath('openregister').'/img/app.svg';
            if (is_file($fallback) === true) {
                return $fallback;
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[HexIconService] openregister app.svg fallback missing: '.$e->getMessage());
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
    private function rawAppSvg(string $appId): array
    {
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
    private function cacheFolder(): ?ISimpleFolder
    {
        try {
            try {
                return $this->appData->getFolder(self::CACHE_FOLDER);
            } catch (NotFoundException $e) {
                return $this->appData->newFolder(self::CACHE_FOLDER);
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[HexIconService] appdata cache folder unavailable: '.$e->getMessage());
            return null;
        }
    }//end cacheFolder()
}//end class
