<?php

/**
 * DocumentProcessingHandler::anonymize() sends a standalone image through the image seam.
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

use Exception;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapperInterface;
use OCA\OpenRegister\Files\Db\AnonymisationLog;
use OCA\OpenRegister\Files\Db\AnonymisationLogMapper;
use OCA\OpenRegister\Files\Db\ChunkSourceReaderInterface;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler;
use OCA\OpenRegister\Files\Service\File\DocumentProcessingPlatform;
use OCA\OpenRegister\Files\Service\File\DocumentRedactionServices;
use OCA\OpenRegister\Files\Service\File\OfficeDocumentSanitizer;
use OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationRequest;
use OCA\OpenRegister\Service\File\AnonymiqRegionDetector;
use OCA\OpenRegister\Service\File\ImageBurner;
use OCA\OpenRegister\Service\File\ImageRedactionService;
use OCA\OpenRegister\Service\File\ImageRegionDetectorRegistry;
use OCA\OpenRegister\Service\File\ImageRegionNotBurnableException;
use OCA\OpenRegister\Service\File\OcrWordLocator;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler
 * @covers \OCA\OpenRegister\Files\Service\File\DocumentRedactionServices
 * @covers \OCA\OpenRegister\Service\Anonymisation\PipelineSteps
 * @uses   \OCA\OpenRegister\Service\File\ImageRedactionService
 * @uses   \OCA\OpenRegister\Service\File\ImageBurner
 * @uses   \OCA\OpenRegister\Service\File\ImageRegion
 * @uses   \OCA\OpenRegister\Service\File\ImageRegionDetectorRegistry
 * @uses   \OCA\OpenRegister\Service\File\ImageRegionNotBurnableException
 * @uses   \OCA\OpenRegister\Service\File\OcrWords
 * @uses   \OCA\OpenRegister\Files\Service\File\DocumentProcessingPlatform
 * @uses   \OCA\OpenRegister\Files\Db\AnonymisationLog
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationRequest
 * @uses   \OCA\OpenRegister\Db\TypedFieldsJson
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-every-redaction-path-reaches-the-seam-req-ais-004
 */
final class DocumentProcessingHandlerImageTest extends TestCase {

	private EntityRelationMapperInterface&MockObject $relations;

	private ?AnonymisationLog $logged = null;

	/** @var array<string, string> Files written next to the source, by name. */
	private array $written = [];

	protected function setUp(): void {
		$this->relations = $this->createMock(EntityRelationMapperInterface::class);
	}//end setUp()

	/**
	 * The handler with the real image seam (or none) and a log mapper that keeps the row.
	 */
	private function handler(bool $withSeam = true): DocumentProcessingHandler {
		$entities = $this->createMock(GdprEntityMapperInterface::class);
		$person = new GdprEntity();
		$person->setType('PERSON');
		$entities->method('find')->willReturn($person);
		$anonymiq = $this->createMock(AnonymiqRegionDetector::class);
		$anonymiq->method('name')->willReturn('anonymiq');

		$images = null;
		if ($withSeam === true) {
			$images = new ImageRedactionService(
				$this->createMock(ChunkSourceReaderInterface::class),
				$this->relations,
				$entities,
				new ImageRegionDetectorRegistry($anonymiq),
				new OcrWordLocator(),
				new ImageBurner()
			);
		}

		$logMapper = $this->createMock(AnonymisationLogMapper::class);
		$logMapper->method('insert')->willReturnCallback(
			function (AnonymisationLog $entity): AnonymisationLog {
				$this->logged = $entity;
				return $entity;
			}
		);
		$sanitizer = $this->createMock(OfficeDocumentSanitizer::class);
		$sanitizer->method('isSanitizable')->willReturn(false);

		return new DocumentProcessingHandler(
			platform: new DocumentProcessingPlatform(
				rootFolder: $this->createMock(IRootFolder::class),
				userSession: $this->createMock(IUserSession::class),
				l10n: null
			),
			redaction: new DocumentRedactionServices(
				entityRelationMapper: $this->relations,
				sanitizer: $sanitizer,
				anonymisationLogMapper: $logMapper,
				images: $images
			),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end handler()

	/**
	 * An image file whose parent records what is written next to it.
	 */
	private function image(string $name, string $mimeType, string $content): File&MockObject {
		$parent = $this->createMock(Folder::class);
		$parent->method('nodeExists')->willReturn(false);
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
		$file->method('getPath')->willReturn('/' . $name);
		$file->method('getMimeType')->willReturn($mimeType);
		$file->method('getContent')->willReturn($content);
		$file->method('getParent')->willReturn($parent);
		return $file;
	}//end image()

	/**
	 * A PNG filled with a light colour, so no pixel is black by accident.
	 */
	private static function png(int $width, int $height): string {
		$image = imagecreatetruecolor($width, $height);
		imagefill($image, 0, 0, imagecolorallocate($image, 220, 230, 240));
		ob_start();
		imagepng($image);
		return (string)ob_get_clean();
	}//end png()

	/**
	 * A decided PERSON region over the left half of the top half.
	 */
	private function decidedRegion(): void {
		$relation = new EntityRelation();
		$relation->setId(7);
		$relation->setEntityId(3);
		$relation->setBox(['x' => 0.0, 'y' => 0.0, 'w' => 0.5, 'h' => 0.5]);
		$relation->setConfidence(0.9);
		$relation->setDetectionMethod('ocr-text');
		$this->relations->method('findByFileId')->with(42)->willReturn([$relation]);
	}//end decidedRegion()

	public function testAStandaloneImageIsBurnedNotTextReplaced(): void {
		$this->decidedRegion();
		$this->relations->expects($this->once())->method('markAsAnonymizedWithPlaceholders')->with(42, []);

		$out = $this->handler()->anonymize(new DocumentAnonymisationRequest(
			node: $this->image('form.png', 'image/png', self::png(40, 40)),
			entities: [['text' => 'Jan Jansen', 'entityType' => 'PERSON', 'key' => 'p1']]
		));

		$this->assertSame('form_anonymized.png', $out->getName());
		$burned = imagecreatefromstring($this->written['form_anonymized.png']);
		$this->assertSame(0, imagecolorat($burned, 5, 5), 'The decided region is burned into the pixels.');
		$this->assertNotSame(0, imagecolorat($burned, 30, 30), 'Outside the region nothing changes.');
	}//end testAStandaloneImageIsBurnedNotTextReplaced()

	public function testTheRunLogsTheImageBurnerStep(): void {
		$this->decidedRegion();

		$this->handler()->anonymize(new DocumentAnonymisationRequest(
			node: $this->image('form.png', 'image/png', self::png(20, 20)),
			entities: []
		));

		$this->assertInstanceOf(AnonymisationLog::class, $this->logged);
		$this->assertSame('ImageBurner', $this->logged->getEngine());
		$this->assertSame(1, $this->logged->getReplacements());
		$this->assertSame('image/png', $this->logged->getMimeType());
		$this->assertSame(
			[
				'anonymiser' => ['outcome' => 'skipped', 'reason' => 'the step did not run for this file'],
				'pdf-text-replacer' => ['outcome' => 'not-applicable'],
				'pdf-metadata-sanitiser' => ['outcome' => 'not-applicable'],
				'docx-sanitiser' => ['outcome' => 'not-applicable'],
				'odt-sanitiser' => ['outcome' => 'not-applicable'],
				'image-burner' => ['outcome' => 'ran'],
			],
			$this->logged->getStepsArray()
		);
	}//end testTheRunLogsTheImageBurnerStep()

	public function testAnImageWithoutDecidedRegionsIsStillReencodedAndMarksNothing(): void {
		$this->relations->method('findByFileId')->willReturn([]);
		$this->relations->expects($this->never())->method('markAsAnonymizedWithPlaceholders');

		$this->handler()->anonymize(new DocumentAnonymisationRequest(
			node: $this->image('blank.png', 'image/png', self::png(10, 10)),
			entities: []
		));

		$this->assertArrayHasKey('blank_anonymized.png', $this->written);
		$this->assertSame(0, $this->logged->getReplacements());
	}//end testAnImageWithoutDecidedRegionsIsStillReencodedAndMarksNothing()

	public function testWithoutTheSeamAnImageIsRefusedNotTextReplaced(): void {
		try {
			$this->handler(withSeam: false)->anonymize(new DocumentAnonymisationRequest(
				node: $this->image('form.png', 'image/png', self::png(10, 10)),
				entities: [['text' => 'Jan', 'entityType' => 'PERSON', 'key' => 'p1']]
			));
			$this->fail('An image without the seam must be refused.');
		} catch (Exception $e) {
			$this->assertSame(
				'form.png is an image, and images can only be anonymised through the image redaction seam, which is not available.',
				$e->getMessage()
			);
		}

		$this->assertSame([], $this->written, 'Nothing is written.');
		$this->assertNull($this->logged, 'No run is logged.');
	}//end testWithoutTheSeamAnImageIsRefusedNotTextReplaced()

	public function testAnImageThatCannotBeBurnedLeavesNoOutputAndNoLog(): void {
		$this->decidedRegion();

		try {
			$this->handler()->anonymize(new DocumentAnonymisationRequest(
				node: $this->image('scan.tif', 'image/tiff', 'II*'),
				entities: []
			));
			$this->fail('A TIFF cannot be burned on this instance and must be refused.');
		} catch (ImageRegionNotBurnableException $e) {
			$this->assertStringContainsString('image/tiff', $e->getMessage());
		}

		$this->assertSame([], $this->written);
		$this->assertNull($this->logged);
	}//end testAnImageThatCannotBeBurnedLeavesNoOutputAndNoLog()
}//end class
