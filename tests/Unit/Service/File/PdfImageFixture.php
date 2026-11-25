<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use ddn\sapp\PDFDoc;
use ddn\sapp\PDFObject;
use GdImage;

/**
 * Builds small PDFs whose pages hold image XObjects, and reads their images back.
 *
 * Every image is a 64x64 raster: light grey with a dark-blue block in the
 * top-left quarter, so a burned region can be told apart from both.
 */
final class PdfImageFixture {

	public const SIZE = 64;

	/**
	 * A one-page PDF with one image, optionally with a text line drawn in Helvetica.
	 *
	 * @param string      $image  The image XObject body, from jpegImage() or rawImage().
	 * @param string|null $text   The text the page shows in Helvetica, or none; the font is the last object.
	 * @param list<string> $extra Extra objects appended after the image, numbered from 6.
	 *
	 * @return string The PDF.
	 */
	public static function onePage(string $image, ?string $text = null, array $extra = []): string {
		$content = 'q 100 0 0 100 0 0 cm /Im1 Do Q';
		$font = '';
		if ($text !== null) {
			$content .= "\nBT\n/F1 12 Tf\n10 50 Td\n(" . $text . ") Tj\nET\n";
			$font = ' /Font << /F1 ' . (6 + count($extra)) . ' 0 R >>';
			$extra[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		}

		return self::pdf(
			array_merge(
				[
					'<< /Type /Catalog /Pages 2 0 R >>',
					'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
					'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Resources << /XObject << /Im1 5 0 R >>' . $font . ' >> /Contents 4 0 R >>',
					self::stream('/Filter /FlateDecode', (string)gzcompress($content)),
					$image,
				],
				$extra
			)
		);
	}//end onePage()

	/**
	 * A PDF whose objects are given in order, numbered from 1, with a classic cross-reference table.
	 *
	 * @param list<string> $objects The object bodies.
	 *
	 * @return string The PDF.
	 */
	public static function pdf(array $objects): string {
		$out = "%PDF-1.4\n";
		$offsets = [];
		foreach ($objects as $i => $body) {
			$offsets[($i + 1)] = strlen($out);
			$out .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
		}

		$xref = strlen($out);
		$size = (count($objects) + 1);
		$out .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
		for ($i = 1; $i < $size; $i++) {
			$out .= sprintf("%010d 00000 n \n", $offsets[$i]);
		}

		return $out . "trailer\n<< /Size " . $size . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
	}//end pdf()

	/**
	 * A stream object body.
	 *
	 * @param string $dictionary The dictionary entries besides Length.
	 * @param string $data       The stream data.
	 *
	 * @return string The body.
	 */
	public static function stream(string $dictionary, string $data): string {
		return '<< ' . $dictionary . ' /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
	}//end stream()

	/**
	 * The fixture raster.
	 *
	 * @return GdImage The image.
	 */
	public static function raster(): GdImage {
		$image = imagecreatetruecolor(self::SIZE, self::SIZE);
		imagefill($image, 0, 0, imagecolorallocate($image, 210, 210, 210));
		imagefilledrectangle($image, 0, 0, 31, 31, imagecolorallocate($image, 20, 40, 160));
		return $image;
	}//end raster()

	/**
	 * The fixture as a JPEG.
	 *
	 * @return string The bytes.
	 */
	public static function jpeg(): string {
		ob_start();
		imagejpeg(self::raster(), null, 95);
		return (string)ob_get_clean();
	}//end jpeg()

	/**
	 * A DCTDecode image XObject body.
	 *
	 * @param string $colourSpace The colour space.
	 *
	 * @return string The body.
	 */
	public static function jpegImage(string $colourSpace = '/DeviceRGB'): string {
		return self::stream(
			'/Type /XObject /Subtype /Image /Width 64 /Height 64 /ColorSpace ' . $colourSpace . ' /BitsPerComponent 8 /Filter /DCTDecode',
			self::jpeg()
		);
	}//end jpegImage()

	/**
	 * The fixture's samples, row by row.
	 *
	 * @param int $components 1 for gray, 3 for RGB.
	 *
	 * @return string The samples.
	 */
	public static function samples(int $components): string {
		$image = self::raster();
		$samples = '';
		for ($y = 0; $y < self::SIZE; $y++) {
			for ($x = 0; $x < self::SIZE; $x++) {
				$colour = imagecolorat($image, $x, $y);
				$red = (($colour >> 16) & 0xFF);
				$samples .= ($components === 1 ? chr($red) : chr($red) . chr((($colour >> 8) & 0xFF)) . chr(($colour & 0xFF)));
			}
		}

		return $samples;
	}//end samples()

	/**
	 * A FlateDecode (or unfiltered) image XObject body.
	 *
	 * @param string $colourSpace The colour space.
	 * @param int    $components  The components of that colour space.
	 * @param bool   $flate       Whether the samples are Flate-compressed.
	 * @param string $more        More dictionary entries.
	 *
	 * @return string The body.
	 */
	public static function rawImage(string $colourSpace = '/DeviceRGB', int $components = 3, bool $flate = true, string $more = ''): string {
		$samples = self::samples(components: $components);
		$filter = '';
		if ($flate === true) {
			$samples = (string)gzcompress($samples);
			$filter = ' /Filter /FlateDecode';
		}

		return self::stream(
			'/Type /XObject /Subtype /Image /Width 64 /Height 64 /ColorSpace ' . $colourSpace . ' /BitsPerComponent 8' . $filter . $more,
			$samples
		);
	}//end rawImage()

	/**
	 * The image XObjects of a PDF's first page, by resource name.
	 *
	 * @param string $pdf The PDF.
	 *
	 * @return array<string, PDFObject> The images.
	 */
	public static function pageImages(string $pdf): array {
		$doc = PDFDoc::from_string($pdf);
		$images = [];
		$xobjects = $doc->get_page(0)['Resources']['XObject'];
		foreach ($xobjects->get_keys() as $name) {
			$images[$name] = $doc->get_object($xobjects[$name]->get_object_referenced());
		}

		return $images;
	}//end pageImages()

	/**
	 * The gray or RGB values of a decoded image's pixels in a rectangle.
	 *
	 * @param string $samples    The decoded samples.
	 * @param int    $components 1 or 3.
	 * @param array  $rectangle  Left, top, right, bottom, inclusive.
	 *
	 * @return list<string> The distinct pixel values, as byte strings.
	 */
	public static function pixels(string $samples, int $components, array $rectangle): array {
		[$left, $top, $right, $bottom] = $rectangle;
		$seen = [];
		for ($y = $top; $y <= $bottom; $y++) {
			for ($x = $left; $x <= $right; $x++) {
				$seen[substr($samples, ((($y * self::SIZE) + $x) * $components), $components)] = true;
			}
		}

		return array_map('strval', array_keys($seen));
	}//end pixels()
}//end class
