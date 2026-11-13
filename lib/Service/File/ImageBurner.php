<?php

/**
 * Burns regions into the pixels of a standalone image.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use GdImage;

/**
 * Decodes with GD, paints each region as an opaque fill into the pixel data
 * and re-encodes. GD writes no EXIF, XMP, IPTC or thumbnail, so re-encoding
 * strips them; nothing is drawn as an overlay or a separate layer.
 *
 * A JPEG fill is widened to the 16-pixel grid the encoder works in, so the
 * region reads back as one colour instead of carrying compression ringing.
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
 */
class ImageBurner {

	/**
	 * The image types GD can decode and encode, by MIME type.
	 *
	 * @var array<string, string>
	 */
	public const SUPPORTED = [
		'image/png' => 'png',
		'image/jpeg' => 'jpeg',
		'image/webp' => 'webp',
	];

	/**
	 * The JPEG block grid, with 2x2 chroma subsampling.
	 */
	private const JPEG_GRID = 16;

	/**
	 * Burn the regions and answer the re-encoded image.
	 *
	 * @param string            $bytes    The image.
	 * @param string            $mimeType Its MIME type.
	 * @param list<ImageRegion> $regions  The decided regions.
	 * @param string            $fileName The file, for messages.
	 *
	 * @return string The burned image.
	 *
	 * @throws ImageRegionNotBurnableException When the type is not supported, the image does not decode or a region does not fit.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
	 */
	public function burn(string $bytes, string $mimeType, array $regions, string $fileName): string {
		$format = (self::SUPPORTED[$mimeType] ?? null);
		if ($format === null) {
			throw new ImageRegionNotBurnableException(fileName: $fileName, reason: sprintf('%s images cannot be burned on this instance.', $mimeType));
		}

		$image = $this->decode(bytes: $bytes, fileName: $fileName);
		$width = imagesx($image);
		$height = imagesy($image);
		imagealphablending($image, false);
		$fill = imagecolorallocatealpha($image, 0, 0, 0, 0);
		foreach ($regions as $region) {
			if ($region->page !== null && $region->page !== 1) {
				throw new ImageRegionNotBurnableException(fileName: $fileName, reason: 'a standalone image has no such page.', region: $region);
			}

			$rect = $this->pixels(box: $region->box, width: $width, height: $height, format: $format);
			imagefilledrectangle($image, $rect[0], $rect[1], $rect[2], $rect[3], $fill);
		}

		return $this->encode(image: $image, format: $format);
	}//end burn()

	/**
	 * Decode the image bytes.
	 *
	 * @param string $bytes    The image.
	 * @param string $fileName The file, for messages.
	 *
	 * @return GdImage The image.
	 *
	 * @throws ImageRegionNotBurnableException When it does not decode.
	 */
	private function decode(string $bytes, string $fileName): GdImage {
		$image = false;
		if ($bytes !== '') {
			$image = @imagecreatefromstring($bytes);
		}

		if (($image instanceof GdImage) === false) {
			throw new ImageRegionNotBurnableException(fileName: $fileName, reason: 'the image is corrupt or in an encoding that cannot be decoded.');
		}

		if (imageistruecolor($image) === false) {
			imagepalettetotruecolor($image);
		}

		return $image;
	}//end decode()

	/**
	 * The pixel rectangle of a box: x0, y0, x1, y1, inclusive.
	 *
	 * @param array{x: float, y: float, w: float, h: float} $box    The box.
	 * @param int                                           $width  The image width.
	 * @param int                                           $height The image height.
	 * @param string                                        $format The encoding.
	 *
	 * @return array{0: int, 1: int, 2: int, 3: int} The rectangle.
	 */
	private function pixels(array $box, int $width, int $height, string $format): array {
		$left = (int)floor($box['x'] * $width);
		$top = (int)floor($box['y'] * $height);
		$right = (int)ceil(($box['x'] + $box['w']) * $width);
		$bottom = (int)ceil(($box['y'] + $box['h']) * $height);
		if ($format === 'jpeg') {
			$left = (intdiv($left, self::JPEG_GRID) * self::JPEG_GRID);
			$top = (intdiv($top, self::JPEG_GRID) * self::JPEG_GRID);
			$right = ((int)ceil($right / self::JPEG_GRID) * self::JPEG_GRID);
			$bottom = ((int)ceil($bottom / self::JPEG_GRID) * self::JPEG_GRID);
		}

		return [
			max(0, $left),
			max(0, $top),
			min(($width - 1), max($left, ($right - 1))),
			min(($height - 1), max($top, ($bottom - 1))),
		];
	}//end pixels()

	/**
	 * Re-encode the image.
	 *
	 * @param GdImage $image  The image.
	 * @param string  $format The encoding.
	 *
	 * @return string The bytes.
	 */
	private function encode(GdImage $image, string $format): string {
		ob_start();
		if ($format === 'png') {
			imagesavealpha($image, true);
			imagepng($image);
		} else if ($format === 'jpeg') {
			imagejpeg($image, null, 92);
		} else {
			imagewebp($image, null, 90);
		}

		return (string)ob_get_clean();
	}//end encode()
}//end class
