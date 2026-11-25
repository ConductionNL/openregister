<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

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
use OCA\OpenRegister\Service\File\AnonymiqRegionDetector;
use OCA\OpenRegister\Service\File\ImageBurner;
use OCA\OpenRegister\Service\File\ImageRedactionService;
use OCA\OpenRegister\Service\File\ImageRegionDetectorRegistry;
use OCA\OpenRegister\Service\File\OcrWordLocator;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * A real DocumentProcessingHandler with the real image seam over a PDF held in memory.
 *
 * The relation mapper, sanitizer and log mapper are doubles; the handler, the
 * PDF text replacer, the image redaction service and its burners are real, so
 * a test sees the bytes the anonymisation would write.
 */
trait PdfAnonymiseHarness {

	private EntityRelationMapper&MockObject $relations;

	private ?AnonymisationLog $logged = null;

	private ?string $output = null;

	private bool $outputDeleted = false;

	/**
	 * Start every test with no decided region and nothing written.
	 *
	 * @return void
	 */
	private function startHarness(): void {
		$this->relations = $this->createMock(EntityRelationMapper::class);
		$this->logged = null;
		$this->output = null;
		$this->outputDeleted = false;
	}//end startHarness()

	/**
	 * Give the file one decided region, or none.
	 *
	 * @param int|null $page The region's page, or null for no region.
	 *
	 * @return void
	 */
	private function decidedRegion(?int $page): void {
		if ($page === null) {
			$this->relations->method('findByFileId')->willReturn([]);
			return;
		}

		$relation = new EntityRelation();
		$relation->setId(7);
		$relation->setEntityId(3);
		$relation->setPage($page);
		$relation->setBox(['x' => 0.0, 'y' => 0.0, 'w' => 0.25, 'h' => 0.25]);
		$relation->setConfidence(0.9);
		$relation->setDetectionMethod('ocr-text');
		$this->relations->method('findByFileId')->willReturn([$relation]);
	}//end decidedRegion()

	/**
	 * The handler over the harness's doubles.
	 *
	 * @return DocumentProcessingHandler The handler.
	 */
	private function pdfHandler(): DocumentProcessingHandler {
		$logMapper = $this->createMock(AnonymisationLogMapper::class);
		$logMapper->method('insert')->willReturnCallback(
			function (AnonymisationLog $entity): AnonymisationLog {
				$this->logged = $entity;
				return $entity;
			}
		);

		$entities = $this->createMock(GdprEntityMapperInterface::class);
		$person = new GdprEntity();
		$person->setType('PERSON');
		$entities->method('find')->willReturn($person);
		$anonymiq = $this->createMock(AnonymiqRegionDetector::class);
		$anonymiq->method('name')->willReturn('anonymiq');

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
				images: new ImageRedactionService(
					$this->createMock(ChunkSourceReaderInterface::class),
					$this->relations,
					$entities,
					new ImageRegionDetectorRegistry($anonymiq),
					new OcrWordLocator(),
					new ImageBurner()
				)
			),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end pdfHandler()

	/**
	 * A PDF file node whose `_anonymized` sibling is captured in `$this->output`.
	 *
	 * @param string $pdf The PDF bytes.
	 *
	 * @return File&MockObject The node.
	 */
	private function pdfNode(string $pdf): File&MockObject {
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
				$out->method('getPath')->willReturn('/' . $path);
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

		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(17);
		$node->method('getName')->willReturn('scan.pdf');
		$node->method('getPath')->willReturn('/scan.pdf');
		$node->method('getMimeType')->willReturn('application/pdf');
		$node->method('getParent')->willReturn($folder);
		$node->method('getContent')->willReturn($pdf);
		$node->method('fopen')->willReturnCallback(
			static function () use ($pdf) {
				$stream = fopen('php://memory', 'r+');
				fwrite($stream, $pdf);
				rewind($stream);
				return $stream;
			}
		);

		return $node;
	}//end pdfNode()

	/**
	 * The decoded samples of the output's page image.
	 *
	 * @return string The samples.
	 */
	private function outputSamples(): string {
		return (string)PdfImageFixture::pageImages((string)$this->output)['Im1']->get_stream(false);
	}//end outputSamples()
}//end trait
