<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\File\ImageBurner;
use OCA\OpenRegister\Service\File\ImageRegion;
use OCA\OpenRegister\Service\File\ImageRegionNotBurnableException;
use OCA\OpenRegister\Service\File\TiffCodec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Baseline TIFF is decoded to the pixels another decoder reads, re-encoded
 * without its metadata, and burned per page.
 *
 * The fixtures in tests/fixtures/tiff were written by ImageMagick 6.9; the
 * hashes below are of ImageMagick's own decode of them, so the decoder is
 * checked against an independent implementation, not against itself.
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
 */
#[CoversClass(TiffCodec::class)]
#[CoversClass(ImageBurner::class)]
#[UsesClass(ImageRegion::class)]
#[UsesClass(ImageRegionNotBurnableException::class)]
final class TiffCodecTest extends TestCase {

	private const DIR = __DIR__ . '/../../../fixtures/tiff/';

	private static function fixture(string $name): string {
		return (string)file_get_contents(self::DIR . $name);
	}//end fixture()

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function encodings(): array {
		return [
			'LZW' => ['rgb-lzw.tif'],
			'PackBits' => ['rgb-packbits.tif'],
			'Deflate with horizontal predictor' => ['rgb-deflate-predictor.tif'],
			'8-bit palette' => ['palette-raw.tif'],
			'LZW with an XMP packet' => ['rgb-lzw-xmp.tif'],
		];
	}//end encodings()

	#[DataProvider('encodings')]
	public function testEveryEncodingDecodesToTheUncompressedPixels(string $name): void {
		$codec = new TiffCodec();
		$expected = $codec->decode(self::fixture('rgb-raw.tif'), 'rgb-raw.tif')[0];

		$page = $codec->decode(self::fixture($name), $name)[0];

		$this->assertSame([40, 30, 3], [$page['width'], $page['height'], $page['channels']]);
		$this->assertSame('3c3cc8', bin2hex(substr($page['pixels'], 0, 3)), 'Top-left is the gradient start.');
		$this->assertSame('e0a0c8', bin2hex(substr($page['pixels'], -3)), 'Bottom-right is the gradient end.');
		$this->assertSame($expected['pixels'], $page['pixels']);
	}//end testEveryEncodingDecodesToTheUncompressedPixels()

	public function testALzwStripThatResetsItsTableDecodesLikeImageMagick(): void {
		$page = (new TiffCodec())->decode(self::fixture('rgb-lzw-large.tif'), 'large.tif')[0];

		$this->assertSame([160, 120, 3], [$page['width'], $page['height'], $page['channels']]);
		$this->assertSame('f84d36bcabfa79d38a3e55b9d0d3ed26a69d2b9fffa426081e5ccf124be921b8', hash('sha256', $page['pixels']));
	}//end testALzwStripThatResetsItsTableDecodesLikeImageMagick()

	public function testGreyDecodesToOneChannelWithBlackAsZero(): void {
		$page = (new TiffCodec())->decode(self::fixture('grey-lzw.tif'), 'grey.tif')[0];

		$this->assertSame(1, $page['channels']);
		$this->assertSame(str_repeat("\x46", 40), substr($page['pixels'], 0, 40), 'The first row is one grey.');
	}//end testGreyDecodesToOneChannelWithBlackAsZero()

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function bilevel(): array {
		return [
			'black is zero, PackBits' => ['bilevel-packbits.tif'],
			'white is zero, uncompressed' => ['bilevel-white-is-zero.tif'],
		];
	}//end bilevel()

	#[DataProvider('bilevel')]
	public function testBilevelExpandsToGreyWithBlackAsZero(string $name): void {
		$page = (new TiffCodec())->decode(self::fixture($name), $name)[0];

		$this->assertSame(1, $page['channels']);
		$row = substr($page['pixels'], (5 * 40), 40);
		$this->assertSame(str_repeat("\x00", 10) . str_repeat("\xFF", 30), $row, 'Ten black columns, then white.');
	}//end testBilevelExpandsToGreyWithBlackAsZero()

	public function testAnAlphaSampleIsKept(): void {
		$page = (new TiffCodec())->decode(self::fixture('rgba-lzw.tif'), 'rgba.tif')[0];

		$this->assertSame(4, $page['channels']);
		$this->assertSame('3c3cc880', bin2hex(substr($page['pixels'], 0, 4)));
	}//end testAnAlphaSampleIsKept()

	public function testEveryPageOfAMultiPageTiffIsRead(): void {
		$pages = (new TiffCodec())->decode(self::fixture('two-pages.tif'), 'two.tif');

		$this->assertCount(2, $pages);
		$this->assertSame([20, 10], [$pages[1]['width'], $pages[1]['height']]);
		$this->assertSame('8a7ee2f66c749d57486ec8856a91fb63e02c20be538db5905ad30ee07cf9da95', hash('sha256', $pages[1]['pixels']));
	}//end testEveryPageOfAMultiPageTiffIsRead()

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function refused(): array {
		return [
			'CCITT group 4' => ['g4.tif', 'compression 4'],
			'JPEG in TIFF' => ['jpeg-compressed.tif', 'compression 7'],
			'16-bit samples' => ['rgb16.tif', '16 bits'],
			'tiles' => ['tiled.tif', 'tiled'],
		];
	}//end refused()

	#[DataProvider('refused')]
	public function testOutsideTheBaselineSubsetIsRefusedByName(string $name, string $why): void {
		$this->expectException(ImageRegionNotBurnableException::class);
		$this->expectExceptionMessage($why);

		(new TiffCodec())->decode(self::fixture($name), $name);
	}//end testOutsideTheBaselineSubsetIsRefusedByName()

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function corrupt(): array {
		$raw = (string)file_get_contents(self::DIR . 'rgb-lzw.tif');
		$loop = "II*\x00" . pack('V', 8) . pack('v', 1) . pack('vvVV', 254, 4, 1, 1) . pack('V', 8);
		$lzw = [256 => 2, 257 => 1, 258 => 8, 259 => 5, 262 => 1, 273 => 8, 277 => 1, 279 => 2];
		$badStrip = "II*\x00" . pack('V', 10) . "\xFF\xFF" . pack('v', count($lzw));
		foreach ($lzw as $tag => $value) {
			$badStrip .= pack('vvVV', $tag, 4, 1, $value);
		}

		$badStrip .= pack('V', 0);
		return [
			'not a TIFF' => ['GIF89a', 'not a TIFF'],
			'BigTIFF' => ["II+\x00" . str_repeat("\x00", 12), 'not a TIFF'],
			'truncated' => [substr($raw, 0, 200), 'truncated'],
			'a directory chain that loops' => [$loop, 'loops'],
			'an LZW strip with an undefined code' => [$badStrip, 'corrupt'],
		];
	}//end corrupt()

	#[DataProvider('corrupt')]
	public function testACorruptFileIsRefused(string $bytes, string $why): void {
		$this->expectException(ImageRegionNotBurnableException::class);
		$this->expectExceptionMessage($why);

		(new TiffCodec())->decode($bytes, 'scan.tif');
	}//end testACorruptFileIsRefused()

	public function testAPageLargerThanTheCeilingIsRefusedBeforeItIsRead(): void {
		$entries = [256 => 20000, 257 => 20000, 258 => 8, 262 => 1, 273 => 8, 277 => 1, 279 => 1];
		$ifd = pack('v', count($entries));
		foreach ($entries as $tag => $value) {
			$ifd .= pack('vvVV', $tag, 4, 1, $value);
		}

		$this->expectException(ImageRegionNotBurnableException::class);
		$this->expectExceptionMessage('20000x20000 pixels');

		(new TiffCodec())->decode("II*\x00" . pack('V', 8) . $ifd . pack('V', 0), 'huge.tif');
	}//end testAPageLargerThanTheCeilingIsRefusedBeforeItIsRead()

	public function testReEncodingKeepsThePixelsAndTheResolution(): void {
		$codec = new TiffCodec();
		$pages = $codec->decode(self::fixture('two-pages.tif'), 'two.tif');

		$again = $codec->decode($codec->encode($pages), 'again.tif');

		$this->assertSame(array_column($pages, 'pixels'), array_column($again, 'pixels'));
		$this->assertSame($pages[0]['resolution'], $again[0]['resolution']);
		$this->assertNotSame([], $again[0]['resolution'], 'The fixture carries a resolution.');
	}//end testReEncodingKeepsThePixelsAndTheResolution()

	public function testReEncodingDropsXmpAndTheDescription(): void {
		$source = self::fixture('rgb-lzw-xmp.tif');
		$this->assertStringContainsString('Jan Jansen', $source, 'The fixture carries the name in its metadata.');
		$codec = new TiffCodec();

		$out = $codec->encode($codec->decode($source, 'meta.tif'));

		$this->assertStringNotContainsString('Jan Jansen', $out);
		$this->assertStringNotContainsString('xmpmeta', $out);
	}//end testReEncodingDropsXmpAndTheDescription()

	public function testAThumbnailDirectoryIsDropped(): void {
		$codec = new TiffCodec();
		$page = $codec->decode(self::fixture('grey-lzw.tif'), 'grey.tif')[0];
		$thumbnail = ['width' => 4, 'height' => 3, 'channels' => 1, 'pixels' => str_repeat("\x10", 12)];
		$marked = $this->withNewSubfileType($codec->encode([$page, $thumbnail]), 4);

		$pages = $codec->decode($marked, 'thumb.tif');

		$this->assertCount(1, $pages, 'The reduced-resolution image is not a page.');
		$this->assertSame(40, $pages[0]['width']);
	}//end testAThumbnailDirectoryIsDropped()

	public function testATiffRegionIsBurnedIntoItsPageOnly(): void {
		$burner = new ImageBurner();
		$region = new ImageRegion(2, ['x' => 0.25, 'y' => 0.2, 'w' => 0.5, 'h' => 0.5], 'PERSON', 0.9, 'ocr-text');
		$codec = new TiffCodec();
		$before = $codec->decode(self::fixture('two-pages.tif'), 'two.tif');

		$after = $codec->decode($burner->burn(self::fixture('two-pages.tif'), 'image/tiff', [$region], 'two.tif'), 'out.tif');

		$this->assertSame($before[0]['pixels'], $after[0]['pixels'], 'Page 1 is untouched.');
		$page = $after[1];
		for ($y = 0; $y < 10; $y++) {
			for ($x = 0; $x < 20; $x++) {
				$pixel = substr($page['pixels'], ((($y * 20) + $x) * 3), 3);
				$inside = ($x >= 5 && $x <= 14 && $y >= 2 && $y <= 6);
				if ($inside === true) {
					$this->assertSame("\x00\x00\x00", $pixel, sprintf('(%d,%d) is filled.', $x, $y));
				} else {
					$this->assertSame(substr($before[1]['pixels'], ((($y * 20) + $x) * 3), 3), $pixel, sprintf('(%d,%d) is untouched.', $x, $y));
				}
			}
		}
	}//end testATiffRegionIsBurnedIntoItsPageOnly()

	public function testAStandaloneRegionBurnsTheFirstPageAndAlphaStaysOpaque(): void {
		$region = new ImageRegion(null, ['x' => 0.0, 'y' => 0.0, 'w' => 0.1, 'h' => 0.1], 'FACE', 0.9, 'anonymiq 1');

		$page = (new TiffCodec())->decode((new ImageBurner())->burn(self::fixture('rgba-lzw.tif'), 'image/tiff', [$region], 'a.tif'), 'a.tif')[0];

		$this->assertSame('000000ff', bin2hex(substr($page['pixels'], 0, 4)), 'Filled opaque black, not see-through.');
		$this->assertSame('3c3cc880', bin2hex(substr($page['pixels'], (4 * 4), 4)), 'Outside, on the same row, stays as it was.');
	}//end testAStandaloneRegionBurnsTheFirstPageAndAlphaStaysOpaque()

	public function testAGreyRegionIsFilledBlack(): void {
		$region = new ImageRegion(1, ['x' => 0.0, 'y' => 0.0, 'w' => 0.25, 'h' => 0.1], 'PERSON', 0.9, 'ocr-text');

		$burned = (new ImageBurner())->burn(self::fixture('bilevel-white-is-zero.tif'), 'image/tiff', [$region], 'b.tif');

		$page = (new TiffCodec())->decode($burned, 'b.tif')[0];

		$this->assertSame(str_repeat("\x00", 10) . str_repeat("\xFF", 30), substr($page['pixels'], 0, 40));
	}//end testAGreyRegionIsFilledBlack()

	public function testARegionOnAPageTheTiffLacksIsRefused(): void {
		$region = new ImageRegion(3, ['x' => 0.1, 'y' => 0.1, 'w' => 0.1, 'h' => 0.1], 'FACE', 0.9, 'anonymiq 1');

		$this->expectException(ImageRegionNotBurnableException::class);
		$this->expectExceptionMessage('region FACE on page 3');

		(new ImageBurner())->burn(self::fixture('two-pages.tif'), 'image/tiff', [$region], 'two.tif');
	}//end testARegionOnAPageTheTiffLacksIsRefused()

	/**
	 * Mark the second directory of a TIFF written by {@see TiffCodec::encode()} as reduced-resolution.
	 *
	 * @param string $tiff  The TIFF.
	 * @param int    $width The second page's width, to check the directory found.
	 *
	 * @return string The TIFF with NewSubfileType = 1 on its second directory.
	 */
	private function withNewSubfileType(string $tiff, int $width): string {
		$first = unpack('V', substr($tiff, 4, 4))[1];
		$count = unpack('v', substr($tiff, $first, 2))[1];
		$second = unpack('V', substr($tiff, ($first + 2 + (12 * $count)), 4))[1];
		$secondCount = unpack('v', substr($tiff, $second, 2))[1];
		$this->assertSame($width, unpack('V', substr($tiff, ($second + 2 + 8), 4))[1], 'The second directory starts with its width.');
		// Prepend NewSubfileType = 1 (tag order stays ascending). Every value of this one-channel directory is
		// inline and its strip lies before it, so inserting 12 bytes moves no offset.
		$entries = substr($tiff, ($second + 2), (12 * $secondCount));
		$entries = pack('vvVV', 254, 4, 1, 1) . $entries;
		$rebuilt = substr($tiff, 0, $second) . pack('v', ($secondCount + 1)) . $entries . substr($tiff, ($second + 2 + (12 * $secondCount)));
		return $rebuilt;
	}//end withNewSubfileType()
}//end class
