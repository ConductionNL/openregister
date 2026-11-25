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
use OCA\OpenRegister\Service\File\PdfImageBurner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The page images of a PDF are burned in place, in their own colour space.
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
 */
#[CoversClass(PdfImageBurner::class)]
#[UsesClass(ImageBurner::class)]
#[UsesClass(ImageRegion::class)]
#[UsesClass(ImageRegionNotBurnableException::class)]
final class PdfImageBurnerTest extends TestCase {

	private static function region(?int $page = 1, float $x = 0.0, float $y = 0.0): ImageRegion {
		return new ImageRegion($page, ['x' => $x, 'y' => $y, 'w' => 0.25, 'h' => 0.25], 'PERSON', 0.9, 'ocr-text');
	}//end region()

	private static function burn(string $pdf, array $regions): string {
		return (new PdfImageBurner(new ImageBurner()))->burn($pdf, $regions, 'scan.pdf');
	}//end burn()

	private static function refusal(string $pdf, array $regions): string {
		try {
			self::burn($pdf, $regions);
		} catch (ImageRegionNotBurnableException $e) {
			return $e->getMessage();
		}

		self::fail('The burn must be refused.');
	}//end refusal()

	public function testOnlyAPdfIsHandled(): void {
		$this->assertTrue(PdfImageBurner::handles('application/pdf'));
		$this->assertFalse(PdfImageBurner::handles('image/png'));
	}//end testOnlyAPdfIsHandled()

	public function testAJpegPageImageIsReplacedByAFlateRasterWithTheRegionFilled(): void {
		$source = PdfImageFixture::onePage(PdfImageFixture::jpegImage());

		$out = self::burn($source, [self::region(x: 0.5, y: 0.5)]);

		$this->assertStringNotContainsString(PdfImageFixture::jpeg(), $out, 'The original JPEG stream is absent from the output.');
		$image = PdfImageFixture::pageImages($out)['Im1'];
		$this->assertSame('/FlateDecode', trim((string)$image['Filter']));
		$this->assertSame('/DeviceRGB', trim((string)$image['ColorSpace']));
		$samples = (string)$image->get_stream(false);
		$this->assertSame(64 * 64 * 3, strlen($samples));
		$this->assertSame(["\0\0\0"], PdfImageFixture::pixels($samples, 3, [32, 32, 47, 47]), 'The region is one opaque fill.');
		$this->assertNotContains("\0\0\0", PdfImageFixture::pixels($samples, 3, [50, 0, 63, 20]), 'Outside the region nothing is filled.');
	}//end testAJpegPageImageIsReplacedByAFlateRasterWithTheRegionFilled()

	public function testAGrayFlateImageStaysGrayAndKeepsItsOtherPixels(): void {
		$source = PdfImageFixture::onePage(PdfImageFixture::rawImage('/DeviceGray', 1));

		$out = self::burn($source, [self::region()]);

		$image = PdfImageFixture::pageImages($out)['Im1'];
		$this->assertSame('/DeviceGray', trim((string)$image['ColorSpace']));
		$samples = (string)$image->get_stream(false);
		$this->assertSame(["\0"], PdfImageFixture::pixels($samples, 1, [0, 0, 15, 15]));
		$this->assertSame(substr(PdfImageFixture::samples(1), (40 * 64), 64), substr($samples, (40 * 64), 64), 'A row outside the region is unchanged.');
	}//end testAGrayFlateImageStaysGrayAndKeepsItsOtherPixels()

	public function testAnUnfilteredRgbImageIsBurned(): void {
		$out = self::burn(PdfImageFixture::onePage(PdfImageFixture::rawImage('/DeviceRGB', 3, false)), [self::region()]);

		$samples = (string)PdfImageFixture::pageImages($out)['Im1']->get_stream(false);
		$this->assertSame(["\0\0\0"], PdfImageFixture::pixels($samples, 3, [0, 0, 15, 15]));
	}//end testAnUnfilteredRgbImageIsBurned()

	public function testAnIccBasedImageIsReadThroughItsProfileComponentCount(): void {
		$profile = PdfImageFixture::stream('/N 3', 'profile');
		$source = PdfImageFixture::onePage(PdfImageFixture::rawImage('[/ICCBased 6 0 R]', 3), null, [$profile]);

		$out = self::burn($source, [self::region()]);

		$image = PdfImageFixture::pageImages($out)['Im1'];
		$this->assertStringContainsString('/ICCBased', (string)$image['ColorSpace'], 'The colour space is kept.');
		$this->assertSame(["\0\0\0"], PdfImageFixture::pixels((string)$image->get_stream(false), 3, [0, 0, 15, 15]));
	}//end testAnIccBasedImageIsReadThroughItsProfileComponentCount()

	public function testTheImageMetadataStreamIsNotCarriedOver(): void {
		$metadata = PdfImageFixture::stream('/Type /Metadata /Subtype /XML', '<x:xmpmeta>Jan Jansen</x:xmpmeta>');
		$source = PdfImageFixture::onePage(PdfImageFixture::rawImage(more: ' /Metadata 6 0 R'), null, [$metadata]);

		$out = self::burn($source, [self::region()]);

		$this->assertStringNotContainsString('Jan Jansen', $out, 'The burned image no longer refers to its XMP, so the full save drops it.');
	}//end testTheImageMetadataStreamIsNotCarriedOver()

	public function testNoAnnotationIsAdded(): void {
		$out = self::burn(PdfImageFixture::onePage(PdfImageFixture::jpegImage()), [self::region()]);

		$this->assertStringNotContainsString('/Annot', $out);
	}//end testNoAnnotationIsAdded()

	public function testResourcesInheritedFromThePageTreeAreFound(): void {
		$source = PdfImageFixture::pdf([
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 /Resources << /XObject << /Im1 5 0 R >> >> >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Contents 4 0 R >>',
			PdfImageFixture::stream('', 'q 100 0 0 100 0 0 cm /Im1 Do Q'),
			PdfImageFixture::rawImage(),
		]);

		$out = self::burn($source, [self::region()]);

		$this->assertStringNotContainsString((string)gzcompress(PdfImageFixture::samples(3)), $out);
	}//end testResourcesInheritedFromThePageTreeAreFound()

	public function testARegionOnAPageThePdfDoesNotHaveIsRefused(): void {
		$message = self::refusal(PdfImageFixture::onePage(PdfImageFixture::jpegImage()), [self::region(page: 2)]);

		$this->assertStringContainsString('the PDF has 1 pages', $message);
	}//end testARegionOnAPageThePdfDoesNotHaveIsRefused()

	public function testARegionWithoutAPageIsRefused(): void {
		$this->assertStringContainsString('addresses none of them', self::refusal(PdfImageFixture::onePage(PdfImageFixture::jpegImage()), [self::region(page: null)]));
	}//end testARegionWithoutAPageIsRefused()

	public function testAPageWithTwoImagesIsRefusedRatherThanGuessed(): void {
		$source = PdfImageFixture::pdf([
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Resources << /XObject << /Im1 5 0 R /Im2 6 0 R >> >> /Contents 4 0 R >>',
			PdfImageFixture::stream('', 'q 50 0 0 50 0 0 cm /Im1 Do Q q 50 0 0 50 50 50 cm /Im2 Do Q'),
			PdfImageFixture::jpegImage(),
			PdfImageFixture::rawImage(),
		]);

		$this->assertStringContainsString('page 1 holds 2 images', self::refusal($source, [self::region()]));
	}//end testAPageWithTwoImagesIsRefusedRatherThanGuessed()

	public function testAPageWithoutAnImageIsRefused(): void {
		$source = PdfImageFixture::pdf([
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Contents 4 0 R >>',
			PdfImageFixture::stream('', 'BT ET'),
		]);

		$this->assertStringContainsString('page 1 holds 0 images', self::refusal($source, [self::region()]));
	}//end testAPageWithoutAnImageIsRefused()

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function unburnable(): array {
		return [
			'cmyk' => [PdfImageFixture::rawImage('/DeviceCMYK', 4), 'colour space /DeviceCMYK'],
			'stencil mask' => [PdfImageFixture::rawImage(more: ' /ImageMask true'), 'stencil mask'],
			'jpeg 2000' => [
				PdfImageFixture::stream('/Type /XObject /Subtype /Image /Width 64 /Height 64 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /JPXDecode', 'jpx'),
				'encoding JPXDecode',
			],
			'samples that do not fill the image' => [
				PdfImageFixture::stream('/Type /XObject /Subtype /Image /Width 64 /Height 64 /ColorSpace /DeviceRGB /BitsPerComponent 8', 'short'),
				'encoding none',
			],
			'a JPEG that does not decode' => [
				PdfImageFixture::stream('/Type /XObject /Subtype /Image /Width 64 /Height 64 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode', 'not a jpeg'),
				'does not decode',
			],
		];
	}//end unburnable()

	#[DataProvider('unburnable')]
	public function testAnImageThatCannotBeWrittenBackIsRefused(string $image, string $reason): void {
		$this->assertStringContainsString($reason, self::refusal(PdfImageFixture::onePage($image), [self::region()]));
	}//end testAnImageThatCannotBeWrittenBackIsRefused()

	public function testACorruptPdfIsRefused(): void {
		$this->assertStringContainsString('corrupt or encrypted', self::refusal('not a pdf', [self::region()]));
		$this->assertStringContainsString('corrupt or encrypted', self::refusal('', [self::region()]));
	}//end testACorruptPdfIsRefused()
}//end class
