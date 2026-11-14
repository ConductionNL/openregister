<?php

/**
 * DocumentProcessingHandler::anonymize() burns the images inside a DOCX in the same call as its text.
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
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapperInterface;
use OCA\OpenRegister\Files\Db\AnonymisationLog;
use OCA\OpenRegister\Files\Db\AnonymisationLogMapper;
use OCA\OpenRegister\Files\Db\ChunkSourceReaderInterface;
use OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler;
use OCA\OpenRegister\Files\Service\File\DocumentProcessingPlatform;
use OCA\OpenRegister\Files\Service\File\DocumentRedactionServices;
use OCA\OpenRegister\Files\Service\File\OfficeDocumentSanitizer;
use OCA\OpenRegister\Files\Service\File\SanitizationReport;
use OCA\OpenRegister\Files\Service\File\SanitizationResult;
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
use ZipArchive;

/**
 * @covers \OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler
 * @covers \OCA\OpenRegister\Service\File\ImageRedactionService
 * @covers \OCA\OpenRegister\Service\File\OfficeMediaBurner
 */
final class DocumentProcessingHandlerOfficeImageTest extends TestCase {

	private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

	/**
	 * The entity relations of the file.
	 *
	 * @var EntityRelationMapper&MockObject
	 */
	private EntityRelationMapper&MockObject $relations;

	/**
	 * The anonymisation log row the run wrote.
	 *
	 * @var AnonymisationLog|null
	 */
	private ?AnonymisationLog $logged = null;

	/**
	 * The bytes of the `_anonymized` output, as last written.
	 *
	 * @var string|null
	 */
	private ?string $output = null;

	/**
	 * Whether the output was deleted.
	 *
	 * @var boolean
	 */
	private bool $outputDeleted = false;

	/**
	 * The DOCX the run reads: the fixture with one PNG added under word/media/.
	 *
	 * @var string
	 */
	private string $docx = '';

	protected function setUp(): void {
		$this->relations = $this->createMock(EntityRelationMapper::class);
		$this->docx = (string)tempnam(sys_get_temp_dir(), 'docx');
		copy(dirname(__DIR__, 3) . '/Data/Word/word-structures.docx', $this->docx);
		$zip = new ZipArchive();
		$zip->open($this->docx);
		$zip->addFromString('word/media/image1.png', self::png(40, 40));
		$zip->close();
	}//end setUp()

	protected function tearDown(): void {
		if (is_file($this->docx) === true) {
			unlink($this->docx);
		}
	}//end tearDown()

	private static function png(int $width, int $height): string {
		$image = imagecreatetruecolor($width, $height);
		imagefill($image, 0, 0, imagecolorallocate($image, 220, 230, 240));
		ob_start();
		imagepng($image);
		return (string)ob_get_clean();
	}//end png()

	/**
	 * The parts of the written output.
	 *
	 * @return array<string, string>
	 */
	private function outputParts(): array {
		$path = (string)tempnam(sys_get_temp_dir(), 'out');
		file_put_contents($path, (string)$this->output);
		$zip = new ZipArchive();
		$zip->open($path);
		$parts = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$parts[(string)$zip->getNameIndex($i)] = (string)$zip->getFromIndex($i);
		}

		$zip->close();
		unlink($path);
		return $parts;
	}//end outputParts()

	private function decidedRegion(int $page): void {
		$relation = new EntityRelation();
		$relation->setId(7);
		$relation->setEntityId(3);
		$relation->setPage($page);
		$relation->setBox(['x' => 0.0, 'y' => 0.0, 'w' => 0.5, 'h' => 0.5]);
		$relation->setConfidence(0.9);
		$relation->setDetectionMethod('anonymiq 1');
		$this->relations->method('findByFileId')->willReturn([$relation]);
	}//end decidedRegion()

	private function anonymise(): File {
		$sanitised = (string)tempnam(sys_get_temp_dir(), 'docx');
		copy($this->docx, $sanitised);
		$sanitizer = $this->createMock(OfficeDocumentSanitizer::class);
		$sanitizer->method('isSanitizable')->willReturnCallback(static fn (string $mime): bool => $mime === self::DOCX);
		$sanitizer->method('sanitize')->willReturn(new SanitizationResult(path: $sanitised, report: new SanitizationReport()));

		$logMapper = $this->createMock(AnonymisationLogMapper::class);
		$logMapper->method('insert')->willReturnCallback(
			function (AnonymisationLog $entity): AnonymisationLog {
				$this->logged = $entity;
				return $entity;
			}
		);

		$entities = $this->createMock(GdprEntityMapperInterface::class);
		$face = new GdprEntity();
		$face->setType('FACE');
		$entities->method('find')->willReturn($face);
		$anonymiq = $this->createMock(AnonymiqRegionDetector::class);
		$anonymiq->method('name')->willReturn('anonymiq');
		$images = new ImageRedactionService(
			$this->createMock(ChunkSourceReaderInterface::class),
			$this->relations,
			$entities,
			new ImageRegionDetectorRegistry($anonymiq),
			new OcrWordLocator(),
			new ImageBurner()
		);

		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturnCallback(
			function (string $path, $content = null): File {
				if (is_resource($content) === true) {
					$content = stream_get_contents($content);
				}

				$this->output = (string)$content;
				$out = $this->createMock(File::class);
				$out->method('getName')->willReturn($path);
				$out->method('getContent')->willReturnCallback(fn (): string => (string)$this->output);
				$out->method('putContent')->willReturnCallback(
					function ($bytes): void {
						$this->output = (string)$bytes;
					}
				);
				$out->method('delete')->willReturnCallback(
					function (): void {
						$this->outputDeleted = true;
					}
				);
				return $out;
			}
		);

		$docx = $this->docx;
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(17);
		$node->method('getName')->willReturn('letter.docx');
		$node->method('getPath')->willReturn('/letter.docx');
		$node->method('getMimeType')->willReturn(self::DOCX);
		$node->method('getParent')->willReturn($folder);
		$node->method('fopen')->willReturnCallback(static fn () => fopen($docx, 'r'));
		$node->method('getContent')->willReturnCallback(static fn () => file_get_contents($docx));

		$handler = new DocumentProcessingHandler(
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

		return $handler->anonymize(new DocumentAnonymisationRequest(
			node: $node,
			entities: [['text' => 'Jan Jansen', 'entityType' => 'PERSON', 'key' => 'p1']]
		));
	}//end anonymise()

	public function testADocxRunReplacesTheTextAndBurnsItsImageInOneOutput(): void {
		$this->decidedRegion(page: 1);

		$this->anonymise();

		$parts = $this->outputParts();
		$burned = imagecreatefromstring($parts['word/media/image1.png']);
		$this->assertSame(0, imagecolorat($burned, 5, 5), 'The decided region is burned into the image inside the DOCX.');
		$this->assertNotSame(0, imagecolorat($burned, 30, 30), 'Outside the region nothing changes.');
		$this->assertStringNotContainsString('Jan Jansen', $parts['word/document.xml'], 'The text is replaced in the same output.');
		$this->assertFalse($this->outputDeleted);
		$this->assertSame('ran', $this->logged->getStepsArray()['image-burner']['outcome']);
	}//end testADocxRunReplacesTheTextAndBurnsItsImageInOneOutput()

	public function testADocxWithoutDecidedRegionsKeepsItsImage(): void {
		$this->relations->method('findByFileId')->willReturn([]);

		$this->anonymise();

		$this->assertSame(self::png(40, 40), $this->outputParts()['word/media/image1.png']);
		$this->assertSame('skipped', $this->logged->getStepsArray()['image-burner']['outcome']);
	}//end testADocxWithoutDecidedRegionsKeepsItsImage()

	public function testARegionThatCannotBeBurnedDeletesTheTextOutput(): void {
		$this->decidedRegion(page: 4);

		try {
			$this->anonymise();
			$this->fail('A region addressing no image must be refused.');
		} catch (ImageRegionNotBurnableException $e) {
			$this->assertStringContainsString('letter.docx could not be redacted', $e->getMessage());
		}

		$this->assertTrue($this->outputDeleted, 'No half-redacted _anonymized copy is left behind.');
	}//end testARegionThatCannotBeBurnedDeletesTheTextOutput()
}//end class
