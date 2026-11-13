<?php

/**
 * The image seam's values: OcrWords, ImageRegion, ImageDetection, the registry and the burn refusal.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
use InvalidArgumentException;
use OCA\OpenRegister\Service\File\AnonymiqRegionDetector;
use OCA\OpenRegister\Service\File\IImageRegionDetector;
use OCA\OpenRegister\Service\File\ImageDetection;
use OCA\OpenRegister\Service\File\ImageRegion;
use OCA\OpenRegister\Service\File\ImageRegionDetectorRegistry;
use OCA\OpenRegister\Service\File\ImageRegionNotBurnableException;
use OCA\OpenRegister\Service\File\OcrWords;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\File\OcrWords
 * @covers \OCA\OpenRegister\Service\File\ImageRegion
 * @covers \OCA\OpenRegister\Service\File\ImageDetection
 * @covers \OCA\OpenRegister\Service\File\ImageRegionDetectorRegistry
 * @covers \OCA\OpenRegister\Service\File\ImageRegionNotBurnableException
 */
final class ImageSeamValuesTest extends TestCase {

	public function testWordsAreNormalisedAndAPageIsOptional(): void {
		$words = OcrWords::normalise([['text' => 'Jan', 'box' => ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]]]);

		$this->assertSame([['text' => 'Jan', 'page' => null, 'box' => ['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0]]], $words);
	}//end testWordsAreNormalisedAndAPageIsOptional()

	/**
	 * @return array<string, array{0: array, 1: string}>
	 */
	public static function badWords(): array {
		return [
			'no text' => [[['page' => 1]], 'words[0] needs a text.'],
			'page zero' => [[['text' => 'a', 'page' => 0, 'box' => ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 0]]], 'words[0].page must be a whole number from 1.'],
			'no box' => [[['text' => 'a']], 'words[0].box must be an object with x, y, w and h.'],
			'w over one' => [[['text' => 'a', 'box' => ['x' => 0, 'y' => 0, 'w' => 1.5, 'h' => 0]]], 'words[0].box.w must be a number from 0 to 1.'],
			'string x' => [[['text' => 'a', 'box' => ['x' => '0', 'y' => 0, 'w' => 0, 'h' => 0]]], 'words[0].box.x must be a number from 0 to 1.'],
		];
	}//end badWords()

	/**
	 * @dataProvider badWords
	 */
	public function testABadWordIsRefusedWithWhatIsWrong(array $words, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		OcrWords::normalise($words);
	}//end testABadWordIsRefusedWithWhatIsWrong()

	public function testTooManyWordsAreRefused(): void {
		$this->expectExceptionMessage('words may hold at most 200000 entries.');

		OcrWords::normalise(array_fill(0, (OcrWords::MAX_WORDS + 1), []));
	}//end testTooManyWordsAreRefused()

	public function testADetectorRegionIsReadAndUpperCased(): void {
		$region = ImageRegion::fromDetector(['page' => 2, 'box' => ['x' => 0.1, 'y' => 0.2, 'w' => 0.3, 'h' => 0.1], 'entityType' => 'signature', 'confidence' => 1], 'anonymiq 1.0.0');

		$this->assertSame(
			['page' => 2, 'box' => ['x' => 0.1, 'y' => 0.2, 'w' => 0.3, 'h' => 0.1], 'entityType' => 'SIGNATURE', 'confidence' => 1.0, 'source' => 'anonymiq 1.0.0', 'relationId' => null],
			$region->toArray()
		);
		$this->assertSame(9, $region->withRelation(9)->relationId);
	}//end testADetectorRegionIsReadAndUpperCased()

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function badRegions(): array {
		$box = ['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 0.5];
		return [
			'not an object' => ['x', 'A region must be an object.'],
			'page zero' => [['page' => 0, 'box' => $box, 'entityType' => 'FACE', 'confidence' => 0.5], 'A region page must be null or a whole number from 1.'],
			'no type' => [['box' => $box, 'entityType' => ' ', 'confidence' => 0.5], 'A region needs an entityType.'],
			'confidence over one' => [['box' => $box, 'entityType' => 'FACE', 'confidence' => 2], 'A region confidence must be a number from 0 to 1.'],
		];
	}//end badRegions()

	/**
	 * @dataProvider badRegions
	 */
	public function testAMalformedDetectorRegionIsRefused(mixed $region, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		ImageRegion::fromDetector($region, 'x 1');
	}//end testAMalformedDetectorRegionIsRefused()

	public function testNoDetectorThatRanMeansObjectDetectionNotRun(): void {
		$detection = new ImageDetection(true, [], ['anonymiq' => ['status' => 'not-installed', 'reason' => 'not-installed']]);

		$this->assertSame('not-run', $detection->objectDetection());
		$this->assertSame('anonymiq: not-installed not-installed', $detection->objectDetectionReason());
		$this->assertSame('no detector registered', (new ImageDetection(true))->objectDetectionReason());
	}//end testNoDetectorThatRanMeansObjectDetectionNotRun()

	public function testOneDetectorThatRanMeansObjectDetectionRan(): void {
		$detection = new ImageDetection(true, [], ['a' => ['status' => 'failed', 'reason' => 'x'], 'b' => ['status' => 'ran', 'reason' => null]]);

		$this->assertSame('ran', $detection->objectDetection());
		$this->assertNull($detection->objectDetectionReason());
	}//end testOneDetectorThatRanMeansObjectDetectionRan()

	public function testANotDetectedFileSaysWhyAndHasNoRegions(): void {
		$out = ImageDetection::notDetected('no OCR')->toArray();

		$this->assertSame(['detected' => false, 'reason' => 'no OCR', 'regions' => [], 'detectors' => [], 'objectDetection' => 'not-run', 'objectDetectionReason' => 'no detector registered'], $out);
	}//end testANotDetectedFileSaysWhyAndHasNoRegions()

	public function testTheRegistryHoldsAnonymiqAndReplacesByName(): void {
		$anonymiq = $this->createMock(AnonymiqRegionDetector::class);
		$anonymiq->method('name')->willReturn('anonymiq');
		$other = $this->createMock(IImageRegionDetector::class);
		$other->method('name')->willReturn('anonymiq');

		$registry = new ImageRegionDetectorRegistry($anonymiq);
		$this->assertSame(['anonymiq' => $anonymiq], $registry->all());

		$registry->register($other);
		$this->assertSame(['anonymiq' => $other], $registry->all());
	}//end testTheRegistryHoldsAnonymiqAndReplacesByName()

	public function testTheBurnRefusalNamesTheFileAndTheRegion(): void {
		$region = new ImageRegion(2, ['x' => 0.5, 'y' => 0.25, 'w' => 0.1, 'h' => 0.1], 'FACE', 0.9, 'x 1');

		$this->assertSame(
			'scan.tif could not be redacted (region FACE on page 2 at x=0.5000 y=0.2500 w=0.1000 h=0.1000): bad. No anonymised copy was written.',
			(new ImageRegionNotBurnableException('scan.tif', 'bad.', $region))->getMessage()
		);
		$this->assertSame('scan.tif could not be redacted: bad. No anonymised copy was written.', (new ImageRegionNotBurnableException('scan.tif', 'bad.'))->getMessage());
	}//end testTheBurnRefusalNamesTheFileAndTheRegion()
}//end class
