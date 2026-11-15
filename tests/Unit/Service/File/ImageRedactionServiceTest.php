<?php

/**
 * ImageRedactionService: regions found through OCR words and detectors, burned into pixels.
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

use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapperInterface;
use OCA\OpenRegister\Files\Db\AnonymisationLog;
use OCA\OpenRegister\Files\Db\AnonymisationLogMapper;
use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Files\Db\ChunkSourceReaderInterface;
use OCA\OpenRegister\Service\File\AnonymiqRegionDetector;
use OCA\OpenRegister\Service\File\ImageBurner;
use OCA\OpenRegister\Service\File\ImageRedactionService;
use OCA\OpenRegister\Service\File\ImageRegion;
use OCA\OpenRegister\Service\File\ImageRegionDetectorRegistry;
use OCA\OpenRegister\Service\File\ImageRegionNotBurnableException;
use OCA\OpenRegister\Service\File\OcrWordLocator;
use OCA\OpenRegister\Service\File\OfficeMediaBurner;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\File\ImageRedactionService
 * @covers \OCA\OpenRegister\Service\File\ImageBurner
 * @covers \OCA\OpenRegister\Service\File\OfficeMediaBurner
 * @uses   \OCA\OpenRegister\Files\Db\AnonymisationLog
 * @uses   \OCA\OpenRegister\Service\File\ImageDetection
 * @uses   \OCA\OpenRegister\Service\File\ImageRegion
 * @uses   \OCA\OpenRegister\Service\File\ImageRegionDetectorRegistry
 * @uses   \OCA\OpenRegister\Service\File\ImageRegionNotBurnableException
 * @uses   \OCA\OpenRegister\Service\File\OcrWordLocator
 * @uses   \OCA\OpenRegister\Service\File\OcrWords
 * @uses   \OCA\OpenRegister\Db\EntityRelation
 * @uses   \OCA\OpenRegister\Db\EntityRelation\EntityRelationFieldTypes
 * @uses   \OCA\OpenRegister\Files\Db\Chunk
 * @uses   \OCA\OpenRegister\Db\GdprEntity
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler
 */
final class ImageRedactionServiceTest extends TestCase {

	private ChunkSourceReaderInterface&MockObject $chunks;

	private EntityRelationMapperInterface&MockObject $relations;

	private GdprEntityMapperInterface&MockObject $entities;

	private AnonymiqRegionDetector&MockObject $anonymiq;

	/**
	 * Where detection runs are recorded.
	 *
	 * @var AnonymisationLogMapper&MockObject
	 */
	private AnonymisationLogMapper&MockObject $runs;

	/** @var list<AnonymisationLog> Detection runs recorded on the log. */
	private array $recorded = [];

	/** @var array<string, string> Files written next to the source, by name. */
	private array $written = [];

	protected function setUp(): void {
		$this->chunks = $this->createMock(ChunkSourceReaderInterface::class);
		$this->relations = $this->createMock(EntityRelationMapperInterface::class);
		$this->entities = $this->createMock(GdprEntityMapperInterface::class);
		$this->anonymiq = $this->createMock(AnonymiqRegionDetector::class);
		$this->anonymiq->method('name')->willReturn('anonymiq');
		$this->runs = $this->createMock(AnonymisationLogMapper::class);
		$this->runs->method('insert')->willReturnCallback(
			function (AnonymisationLog $run): AnonymisationLog {
				$this->recorded[] = $run;
				return $run;
			}
		);
	}//end setUp()

	private function service(): ImageRedactionService {
		return new ImageRedactionService(
			$this->chunks,
			$this->relations,
			$this->entities,
			new ImageRegionDetectorRegistry($this->anonymiq),
			new OcrWordLocator(),
			new ImageBurner(),
			$this->runs
		);
	}//end service()

	/**
	 * An office archive holding the given parts.
	 *
	 * @param array<string, string> $parts The part bytes by name, in archive order.
	 *
	 * @return string The archive bytes.
	 */
	private static function archive(array $parts): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'or-test-');
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		foreach ($parts as $name => $bytes) {
			$zip->addFromString($name, $bytes);
		}

		$zip->close();
		$bytes = (string)file_get_contents($path);
		unlink($path);

		return $bytes;
	}//end archive()

	/**
	 * The parts of an office archive.
	 *
	 * @param string $bytes The archive bytes.
	 *
	 * @return array<string, string> The part bytes by name.
	 */
	private static function parts(string $bytes): array {
		$path = (string)tempnam(sys_get_temp_dir(), 'or-test-');
		file_put_contents($path, $bytes);
		$zip = new \ZipArchive();
		$zip->open($path);
		$parts = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$parts[(string)$zip->getNameIndex($i)] = (string)$zip->getFromIndex($i);
		}

		$zip->close();
		unlink($path);

		return $parts;
	}//end parts()

	/**
	 * A file whose parent records what is written next to it.
	 */
	private function file(string $name, string $mimeType, string $content = 'bytes', bool $siblingExists = false): File&MockObject {
		$parent = $this->createMock(Folder::class);
		$parent->method('nodeExists')->willReturn($siblingExists);
		$parent->method('newFile')->willReturnCallback(
			function (string $path, ?string $content): File {
				$this->written[$path] = (string)$content;
				$created = $this->createMock(File::class);
				$created->method('getName')->willReturn($path);
				return $created;
			}
		);
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getName')->willReturn($name);
		$file->method('getMimeType')->willReturn($mimeType);
		$file->method('getContent')->willReturn($content);
		$file->method('getParent')->willReturn($parent);
		return $file;
	}//end file()

	/**
	 * The file's metadata chunk with the given OCR words (null: no OCR at all).
	 */
	private function ocrWords(?array $words): void {
		$metadata = new Chunk();
		$metadata->setId(900);
		$metadata->setChunkIndex(-1);
		$reference = ['type' => 'metadata'];
		if ($words !== null) {
			$reference['ocrWords'] = $words;
		}

		$metadata->setPositionReference($reference);
		$content = new Chunk();
		$content->setChunkIndex(0);
		$this->chunks->method('findBySource')->with('file', 42)->willReturn([$content, $metadata]);
	}//end ocrWords()

	private static function janJansen(): array {
		return [
			['text' => 'Geachte', 'page' => 1, 'box' => ['x' => 0.1, 'y' => 0.1, 'w' => 0.1, 'h' => 0.02]],
			['text' => 'Jan', 'page' => 1, 'box' => ['x' => 0.25, 'y' => 0.1, 'w' => 0.05, 'h' => 0.02]],
			['text' => 'Jansen,', 'page' => 1, 'box' => ['x' => 0.31, 'y' => 0.1, 'w' => 0.09, 'h' => 0.025]],
		];
	}//end janJansen()

	/**
	 * A PNG of the given size filled with a gradient, so no pixel is black by accident.
	 */
	private static function gradient(int $width, int $height, string $format): string {
		$image = imagecreatetruecolor($width, $height);
		for ($x = 0; $x < $width; $x++) {
			for ($y = 0; $y < $height; $y++) {
				imagesetpixel($image, $x, $y, imagecolorallocate($image, 60 + ($x % 190), 80 + ($y % 170), 200));
			}
		}

		ob_start();
		if ($format === 'jpeg') {
			imagejpeg($image, null, 90);
		} else {
			imagepng($image);
		}

		return (string)ob_get_clean();
	}//end gradient()

	/**
	 * The distinct colours inside a pixel rectangle of an encoded image.
	 *
	 * @return list<int>
	 */
	private static function colours(string $bytes, int $left, int $top, int $right, int $bottom): array {
		$image = imagecreatefromstring($bytes);
		$seen = [];
		for ($x = $left; $x <= $right; $x++) {
			for ($y = $top; $y <= $bottom; $y++) {
				$seen[imagecolorat($image, $x, $y)] = true;
			}
		}

		return array_keys($seen);
	}//end colours()

	public function testAnOcrLocatedNameBecomesARegion(): void {
		$this->ocrWords(self::janJansen());
		$this->relations->method('findEntitiesForFile')->with(42)->willReturn(
			[['relation_id' => 5, 'entity_id' => 3, 'position_start' => 8, 'position_end' => 18, 'confidence' => 0.9, 'entity_type' => 'PERSON', 'entity_value' => 'Jan Jansen', 'category' => 'personal_data']]
		);
		$relation = new EntityRelation();
		$relation->setId(5);
		$this->relations->method('find')->with(5)->willReturn($relation);
		$this->relations->expects($this->once())->method('update')->with($this->identicalTo($relation));

		$detection = $this->service()->detectImage($this->file('scan.pdf', 'application/pdf'));

		$this->assertTrue($detection->detected);
		$this->assertCount(1, $detection->regions);
		$this->assertSame('PERSON', $detection->regions[0]->entityType);
		$this->assertSame(ImageRegion::SOURCE_OCR, $detection->regions[0]->source);
		$this->assertSame(5, $detection->regions[0]->relationId);
		$this->assertSame(1, $relation->getPage());
		$this->assertEqualsWithDelta(['x' => 0.25, 'y' => 0.1, 'w' => 0.15, 'h' => 0.025], $relation->getBox(), 1e-9);
	}//end testAnOcrLocatedNameBecomesARegion()

	public function testTheSecondRelationOfAValueTakesItsSecondPlace(): void {
		$words = array_merge(self::janJansen(), [['text' => 'Jansen', 'page' => 2, 'box' => ['x' => 0.5, 'y' => 0.5, 'w' => 0.1, 'h' => 0.02]]]);
		$this->ocrWords($words);
		$row = ['entity_type' => 'PERSON', 'entity_value' => 'Jansen', 'confidence' => 0.8];
		$this->relations->method('findEntitiesForFile')->willReturn([$row + ['relation_id' => 1], $row + ['relation_id' => 2], $row + ['relation_id' => 3]]);
		$this->relations->method('find')->willReturnCallback(static fn (int $id): EntityRelation => new EntityRelation());

		$detection = $this->service()->detectImage($this->file('scan.pdf', 'application/pdf'));

		$this->assertSame([1, 2], array_map(static fn (ImageRegion $region): ?int => $region->page, $detection->regions), 'The third relation has no third place and gets no box.');
		$this->assertSame([1, 2], array_map(static fn (ImageRegion $region): ?int => $region->relationId, $detection->regions));
	}//end testTheSecondRelationOfAValueTakesItsSecondPlace()

	public function testAnImageWithoutOcrIsNotDetectable(): void {
		$this->ocrWords(null);
		$this->relations->expects($this->never())->method('update');
		$this->relations->expects($this->never())->method('insert');
		$this->anonymiq->expects($this->never())->method('detect');

		$detection = $this->service()->detectImage($this->file('photo.png', 'image/png'));

		$this->assertFalse($detection->detected);
		$this->assertSame([], $detection->regions);
		$this->assertStringContainsString('No OCR words with positions were provided', (string)$detection->reason);
		$this->assertStringContainsString('extractFromProvidedText()', (string)$detection->reason);
	}//end testAnImageWithoutOcrIsNotDetectable()

	public function testAnImageThatWasNeverExtractedIsNotDetectable(): void {
		$this->chunks->method('findBySource')->willReturn([]);

		$this->assertFalse($this->service()->detectImage($this->file('photo.png', 'image/png'))->detected);
	}//end testAnImageThatWasNeverExtractedIsNotDetectable()

	public function testAnAbsentDetectorIsRecordedAsNotRun(): void {
		$this->ocrWords(self::janJansen());
		$this->relations->method('findEntitiesForFile')->willReturn([]);
		$this->anonymiq->method('isInstalled')->willReturn(false);
		$this->anonymiq->expects($this->never())->method('detect');

		$detection = $this->service()->detectImage($this->file('photo.png', 'image/png'));

		$this->assertTrue($detection->detected);
		$this->assertSame(['anonymiq' => ['status' => 'not-installed', 'reason' => 'not-installed']], $detection->detectors);
		$this->assertSame('not-run', $detection->toArray()['objectDetection']);

		$this->assertCount(1, $this->recorded, 'The detection run is recorded on the anonymisation log.');
		$run = $this->recorded[0];
		$this->assertSame('ImageDetection', $run->getEngine());
		$this->assertSame('success', $run->getStatus());
		$step = $run->getStepsArray()['image-detection'];
		$this->assertSame('not-run', $step['objectDetection']);
		$this->assertSame(['anonymiq' => ['status' => 'not-installed', 'reason' => 'not-installed']], $step['detectors']);
	}//end testAnAbsentDetectorIsRecordedAsNotRun()

	public function testAnImageThatCouldNotBeDetectedIsRecordedAsAFailedRunNotAsClean(): void {
		$this->ocrWords(null);

		$this->service()->detectImage($this->file('scan.png', 'image/png'));

		$this->assertCount(1, $this->recorded);
		$this->assertSame('failure', $this->recorded[0]->getStatus());
		$this->assertSame(ImageRedactionService::NO_OCR, $this->recorded[0]->getReason());
		$this->assertSame('skipped', $this->recorded[0]->getStepsArray()['image-detection']['outcome']);
	}//end testAnImageThatCouldNotBeDetectedIsRecordedAsAFailedRunNotAsClean()

	public function testARanDetectorIsRecordedWithItsRegions(): void {
		$this->ocrWords(self::janJansen());
		$this->relations->method('findEntitiesForFile')->willReturn([]);
		$this->anonymiq->method('isInstalled')->willReturn(true);
		$this->anonymiq->method('detect')->willThrowException(new RuntimeException('ExApp timed out'));

		$this->service()->detectImage($this->file('photo.png', 'image/png'));

		$step = $this->recorded[0]->getStepsArray()['image-detection'];
		$this->assertSame(['anonymiq' => ['status' => 'failed', 'reason' => 'ExApp timed out']], $step['detectors']);
		$this->assertSame('not-run', $step['objectDetection']);
		$this->assertSame(0, $step['regions']);
	}//end testARanDetectorIsRecordedWithItsRegions()

	public function testADetectorRegionIsStoredWithPageBoxAndSource(): void {
		$this->ocrWords(self::janJansen());
		$this->relations->method('findEntitiesForFile')->willReturn([]);
		$this->anonymiq->method('isInstalled')->willReturn(true);
		$this->anonymiq->expects($this->once())->method('detect')->with('PNG', null)->willReturn(
			[
				'regions' => [['page' => null, 'box' => ['x' => 0.6, 'y' => 0.8, 'w' => 0.25, 'h' => 0.1], 'entityType' => 'SIGNATURE', 'confidence' => 0.93]],
				'detector' => ['name' => 'anonymiq', 'version' => '0.4.1'],
			]
		);
		$this->entities->method('findOneByValueAndType')->willReturn(null);
		$this->entities->expects($this->once())->method('insert')->willReturnCallback(
			static function (GdprEntity $entity): GdprEntity {
				$entity->setId(77);
				return $entity;
			}
		);
		$this->relations->method('findByEntityId')->willReturn([]);
		$stored = null;
		$this->relations->expects($this->once())->method('insert')->willReturnCallback(
			static function (EntityRelation $relation) use (&$stored): EntityRelation {
				$relation->setId(500);
				$stored = $relation;
				return $relation;
			}
		);

		$detection = $this->service()->detectImage($this->file('photo.png', 'image/png', 'PNG'));

		$this->assertSame('ran', $detection->objectDetection());
		$this->assertSame(500, $detection->regions[0]->relationId);
		$this->assertSame(77, $stored->getEntityId());
		$this->assertSame(900, $stored->getChunkId(), 'Tied to the metadata chunk that holds the OCR words.');
		$this->assertSame(42, $stored->getFileId());
		$this->assertNull($stored->getPage());
		$this->assertSame(['x' => 0.6, 'y' => 0.8, 'w' => 0.25, 'h' => 0.1], $stored->getBox());
		$this->assertSame('anonymiq 0.4.1', $stored->getDetectionMethod());
	}//end testADetectorRegionIsStoredWithPageBoxAndSource()

	public function testADetectorRegionAlreadyStoredForTheFileIsNotStoredTwice(): void {
		$this->ocrWords(self::janJansen());
		$this->relations->method('findEntitiesForFile')->willReturn([]);
		$this->anonymiq->method('isInstalled')->willReturn(true);
		$this->anonymiq->method('detect')->willReturn(
			['regions' => [['box' => ['x' => 0, 'y' => 0, 'w' => 0.1, 'h' => 0.1], 'entityType' => 'FACE', 'confidence' => 0.5]], 'detector' => ['name' => 'anonymiq', 'version' => '1']]
		);
		$entity = new GdprEntity();
		$entity->setId(77);
		$this->entities->method('findOneByValueAndType')->with('FACE region p1 0.0000,0.0000,0.1000,0.1000', 'FACE')->willReturn($entity);
		$other = new EntityRelation();
		$other->setId(1);
		$other->setFileId(41);
		$mine = new EntityRelation();
		$mine->setId(2);
		$mine->setFileId(42);
		$this->relations->method('findByEntityId')->with(77)->willReturn([$other, $mine]);
		$this->relations->expects($this->never())->method('insert');

		$detection = $this->service()->detectImage($this->file('photo.png', 'image/png'));

		$this->assertSame(2, $detection->regions[0]->relationId);
	}//end testADetectorRegionAlreadyStoredForTheFileIsNotStoredTwice()

	public function testAFailingDetectorIsRecordedAsFailedWithTheReason(): void {
		$this->ocrWords(self::janJansen());
		$this->relations->method('findEntitiesForFile')->willReturn([]);
		$this->anonymiq->method('isInstalled')->willReturn(true);
		$this->anonymiq->method('detect')->willThrowException(new RuntimeException('anonymiq answered HTTP 500.'));

		$detection = $this->service()->detectImage($this->file('photo.png', 'image/png'));

		$this->assertSame(['anonymiq' => ['status' => 'failed', 'reason' => 'anonymiq answered HTTP 500.']], $detection->detectors);
		$this->assertSame('not-run', $detection->objectDetection());
	}//end testAFailingDetectorIsRecordedAsFailedWithTheReason()

	public function testAnInstalledDetectorIsNotHandedAPdfItCannotLookAt(): void {
		$this->ocrWords(self::janJansen());
		$this->relations->method('findEntitiesForFile')->willReturn([]);
		$this->anonymiq->method('isInstalled')->willReturn(true);
		$this->anonymiq->expects($this->never())->method('detect');

		$detection = $this->service()->detectImage($this->file('scan.pdf', 'application/pdf'));

		$this->assertSame('failed', $detection->detectors['anonymiq']['status']);
		$this->assertSame('page images of application/pdf files are not handed to detectors yet', $detection->detectors['anonymiq']['reason']);
	}//end testAnInstalledDetectorIsNotHandedAPdfItCannotLookAt()

	public function testARegionIsUniformFillAfterBurning(): void {
		$region = new ImageRegion(null, ['x' => 0.25, 'y' => 0.25, 'w' => 0.5, 'h' => 0.25], 'PERSON', 0.9, 'ocr-text');

		$this->service()->redactImage($this->file('form.png', 'image/png', self::gradient(100, 80, 'png')), [$region]);

		$out = $this->written['form_anonymized.png'];
		$this->assertSame([0], self::colours($out, 25, 20, 74, 39), 'Every pixel of the region is the fill.');
		$this->assertNotContains(0, self::colours($out, 0, 0, 24, 19), 'Outside the region nothing is filled.');
		$this->assertNotContains(0, self::colours($out, 75, 40, 99, 79));
	}//end testARegionIsUniformFillAfterBurning()

	public function testAJpegRegionReadsBackAsOneColour(): void {
		$region = new ImageRegion(1, ['x' => 0.3, 'y' => 0.3, 'w' => 0.2, 'h' => 0.2], 'PERSON', 0.9, 'ocr-text');

		$this->service()->redactImage($this->file('photo.jpg', 'image/jpeg', self::gradient(160, 160, 'jpeg')), [$region]);

		$this->assertCount(1, self::colours($this->written['photo_anonymized.jpg'], 48, 48, 79, 79));
	}//end testAJpegRegionReadsBackAsOneColour()

	public function testExifAndXmpAreGone(): void {
		$jpeg = self::gradient(64, 64, 'jpeg');
		$exif = "Exif\0\0" . 'GPS 52.09 5.12 Canon';
		$xmp = "http://ns.adobe.com/xap/1.0/\0" . '<x:xmpmeta>Jan Jansen</x:xmpmeta>';
		$segment = static fn (string $marker, string $data): string => $marker . pack('n', (strlen($data) + 2)) . $data;
		$withMetadata = substr($jpeg, 0, 2) . $segment("\xFF\xE1", $exif) . $segment("\xFF\xE1", $xmp) . substr($jpeg, 2);
		$this->assertNotFalse(imagecreatefromstring($withMetadata), 'The fixture is a valid JPEG.');

		$this->service()->redactImage($this->file('photo.jpeg', 'image/jpeg', $withMetadata), []);

		$out = $this->written['photo_anonymized.jpeg'];
		$this->assertStringNotContainsString('Exif', $out);
		$this->assertStringNotContainsString('ns.adobe.com/xap', $out);
		$this->assertStringNotContainsString('Jan Jansen', $out);
	}//end testExifAndXmpAreGone()

	public function testAnUnburnableRegionLeavesNoOutput(): void {
		$region = new ImageRegion(null, ['x' => 0.1, 'y' => 0.1, 'w' => 0.1, 'h' => 0.1], 'PERSON', 0.9, 'ocr-text');

		try {
			$this->service()->redactImage($this->file('broken.png', 'image/png', "\x89PNG corrupt"), [$region]);
			$this->fail('A corrupt image must be refused.');
		} catch (ImageRegionNotBurnableException $e) {
			$this->assertStringStartsWith('broken.png could not be redacted', $e->getMessage());
		}

		$this->assertSame([], $this->written, 'No _anonymized sibling is left behind.');
	}//end testAnUnburnableRegionLeavesNoOutput()

	public function testARegionOnAPageAStandaloneImageDoesNotHaveIsRefused(): void {
		$region = new ImageRegion(3, ['x' => 0.1, 'y' => 0.1, 'w' => 0.1, 'h' => 0.1], 'FACE', 0.9, 'anonymiq 1');

		$this->expectException(ImageRegionNotBurnableException::class);
		$this->expectExceptionMessage('region FACE on page 3');

		$this->service()->redactImage($this->file('a.png', 'image/png', self::gradient(10, 10, 'png')), [$region]);
	}//end testARegionOnAPageAStandaloneImageDoesNotHaveIsRefused()

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function unsupported(): array {
		return [
			'tiff' => ['scan.tif', 'image/tiff'],
			'pdf' => ['scan.pdf', 'application/pdf'],
		];
	}//end unsupported()

	/**
	 * @dataProvider unsupported
	 */
	public function testATypeThatCannotBeBurnedYetIsRefusedWithoutOutput(string $name, string $mimeType): void {
		try {
			$this->service()->redactImage($this->file($name, $mimeType), []);
			$this->fail('An unsupported type must be refused.');
		} catch (ImageRegionNotBurnableException $e) {
			$this->assertStringContainsString($mimeType, $e->getMessage());
		}

		$this->assertSame([], $this->written);
	}//end testATypeThatCannotBeBurnedYetIsRefusedWithoutOutput()

	public function testAnImageInsideADocxIsBurned(): void {
		$first = self::gradient(40, 40, 'png');
		$second = self::gradient(80, 80, 'png');
		$document = '<w:document>Jan Jansen</w:document>';
		$docx = self::archive(['word/document.xml' => $document, 'word/media/image1.png' => $first, 'word/media/image2.png' => $second]);
		$region = new ImageRegion(2, ['x' => 0.25, 'y' => 0.25, 'w' => 0.5, 'h' => 0.5], 'SIGNATURE', 0.9, 'anonymiq 1');

		$this->service()->redactImage(
			$this->file('letter.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $docx),
			[$region]
		);

		$parts = self::parts($this->written['letter_anonymized.docx']);
		$this->assertSame([0], self::colours($parts['word/media/image2.png'], 20, 20, 59, 59), 'The addressed image is filled in its pixels.');
		$this->assertNotContains(0, self::colours($parts['word/media/image2.png'], 0, 0, 19, 19));
		$this->assertSame($first, $parts['word/media/image1.png'], 'Another image is left as it was.');
		$this->assertSame($document, $parts['word/document.xml'], 'The text part is not touched by the image seam.');
	}//end testAnImageInsideADocxIsBurned()

	public function testAnImageInsideAnOdtIsBurned(): void {
		$odt = self::archive(
			[
				'mimetype' => 'application/vnd.oasis.opendocument.text',
				'content.xml' => '<office/>',
				'Pictures/photo.jpg' => self::gradient(64, 64, 'jpeg'),
			]
		);
		$region = new ImageRegion(1, ['x' => 0.0, 'y' => 0.0, 'w' => 0.5, 'h' => 0.5], 'FACE', 0.9, 'anonymiq 1');

		$this->service()->redactImage($this->file('note.odt', 'application/vnd.oasis.opendocument.text', $odt), [$region]);

		$parts = self::parts($this->written['note_anonymized.odt']);
		$this->assertCount(1, self::colours($parts['Pictures/photo.jpg'], 0, 0, 31, 31));
		$this->assertSame('application/vnd.oasis.opendocument.text', $parts['mimetype']);
	}//end testAnImageInsideAnOdtIsBurned()

	public function testARegionAddressingNoImageOfTheDocumentLeavesNoOutput(): void {
		$docx = self::archive(['word/document.xml' => '<w:document/>', 'word/media/image1.png' => self::gradient(10, 10, 'png')]);
		$region = new ImageRegion(2, ['x' => 0.1, 'y' => 0.1, 'w' => 0.1, 'h' => 0.1], 'FACE', 0.9, 'anonymiq 1');

		try {
			$this->service()->redactImage($this->file('a.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $docx), [$region]);
			$this->fail('A region beyond the last image must be refused.');
		} catch (ImageRegionNotBurnableException $e) {
			$this->assertStringContainsString('the document has 1 images', $e->getMessage());
		}

		$this->assertSame([], $this->written);
	}//end testARegionAddressingNoImageOfTheDocumentLeavesNoOutput()

	public function testAMediaFormatThatCannotBeBurnedLeavesNoOutput(): void {
		$docx = self::archive(['word/document.xml' => '<w:document/>', 'word/media/image1.emf' => 'EMF bytes']);
		$region = new ImageRegion(1, ['x' => 0.1, 'y' => 0.1, 'w' => 0.1, 'h' => 0.1], 'FACE', 0.9, 'anonymiq 1');

		try {
			$this->service()->redactImage($this->file('a.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $docx), [$region]);
			$this->fail('An EMF image must be refused.');
		} catch (ImageRegionNotBurnableException $e) {
			$this->assertStringContainsString('word/media/image1.emf is in a format that cannot be burned', $e->getMessage());
		}

		$this->assertSame([], $this->written);
	}//end testAMediaFormatThatCannotBeBurnedLeavesNoOutput()

	public function testACorruptDocumentLeavesNoOutput(): void {
		$this->expectException(ImageRegionNotBurnableException::class);
		$this->expectExceptionMessage('not a readable archive');

		$this->service()->redactImage($this->file('a.odt', 'application/vnd.oasis.opendocument.text', 'not a zip'), []);
	}//end testACorruptDocumentLeavesNoOutput()

	public function testTheMediaPartsAreListedInArchiveOrder(): void {
		$docx = self::archive(['word/document.xml' => 'x', 'word/media/b.png' => 'b', 'word/media/a.png' => 'a', 'word/styles.xml' => 'y']);

		$burner = new OfficeMediaBurner(new ImageBurner());
		$parts = $burner->mediaParts($docx, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'a.docx');

		$this->assertSame(['word/media/b.png', 'word/media/a.png'], $parts);
	}//end testTheMediaPartsAreListedInArchiveOrder()

	public function testAnExistingSiblingIsOverwritten(): void {
		$sibling = $this->createMock(File::class);
		$sibling->expects($this->once())->method('putContent')->with($this->callback(static fn (string $bytes): bool => imagecreatefromstring($bytes) !== false));
		$file = $this->file('a.png', 'image/png', self::gradient(10, 10, 'png'), true);
		$file->getParent()->method('get')->with('a_anonymized.png')->willReturn($sibling);

		$this->assertSame($sibling, $this->service()->redactImage($file, []));
		$this->assertSame([], $this->written);
	}//end testAnExistingSiblingIsOverwritten()

	public function testDecidedRegionsLeaveOutSkippedAndTextOnlyRelations(): void {
		$boxed = new EntityRelation();
		$boxed->setId(1);
		$boxed->setEntityId(10);
		$boxed->setPage(2);
		$boxed->setBox(['x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.2]);
		$boxed->setConfidence(0.7);
		$boxed->setDetectionMethod('ocr-text');
		$skipped = new EntityRelation();
		$skipped->setId(2);
		$skipped->setBox(['x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.2]);
		$skipped->setSkipAnonymization(true);
		$textOnly = new EntityRelation();
		$textOnly->setId(3);
		$this->relations->method('findByFileId')->with(42)->willReturn([$boxed, $skipped, $textOnly]);
		$entity = new GdprEntity();
		$entity->setType('PERSON');
		$this->entities->method('find')->with(10)->willReturn($entity);

		$regions = $this->service()->decidedRegions($this->file('a.png', 'image/png'));

		$this->assertCount(1, $regions);
		$this->assertSame(
			['page' => 2, 'box' => ['x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.2], 'entityType' => 'PERSON', 'confidence' => 0.7, 'source' => 'ocr-text', 'relationId' => 1],
			$regions[0]->toArray()
		);
	}//end testDecidedRegionsLeaveOutSkippedAndTextOnlyRelations()
}//end class
