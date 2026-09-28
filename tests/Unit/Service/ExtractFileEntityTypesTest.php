<?php

/**
 * extractFile() carries the caller's entity type filter to detection (or#4115).
 *
 * filinq lets an operator switch entity types off for automatic detection and
 * passes the list as a third argument to `extractFile()`, which took two. PHP
 * drops an extra argument without an error, so detection always ran for every
 * type. The filter now arrives at `processSourceChunks()` as `entity_types`,
 * the option `EntityRecognitionHandler::extractFromChunk()` reads.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/text-extraction/spec.md#requirement-file-and-object-chunk-extraction-lifecycle
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\RiskLevelService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Service\TextExtraction\SpreadsheetExtractor;
use OCA\OpenRegister\Service\TextExtraction\WordExtractor;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\TextExtractionService
 */
class ExtractFileEntityTypesTest extends TestCase {

	/**
	 * Run extractFile() and return the options processSourceChunks() received.
	 *
	 * @param array $arguments The arguments after the file id.
	 *
	 * @return array|null The options, or null when detection was not reached.
	 */
	private function optionsFor(array $arguments): ?array {
		$fileMapper = $this->createMock(FileMapper::class);
		$fileMapper->method('getFile')->willReturn(
			['mtime' => 300, 'path' => '/files/a.txt', 'name' => 'a.txt', 'mimetype' => 'text/plain', 'size' => 500]
		);

		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn(str_repeat('Jan de Vries woont in Utrecht. ', 10));
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->willReturn([$file]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getFileSettingsOnly')->willReturn(
			['entityRecognitionEnabled' => true, 'entityRecognitionMethod' => 'openanonymiser']
		);

		$received = null;
		$entityHandler = $this->createMock(EntityRecognitionHandler::class);
		$entityHandler->method('processSourceChunks')->willReturnCallback(
			function (string $sourceType, int $sourceId, array $options) use (&$received): array {
				$received = $options;
				return ['entities_found' => 0, 'relations_created' => 0];
			}
		);

		$logger  = $this->createMock(LoggerInterface::class);
		$service = new TextExtractionService(
			$fileMapper,
			$this->createMock(ChunkMapper::class),
			$rootFolder,
			$this->createMock(IDBConnection::class),
			$logger,
			$this->createMock(MagicMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$entityHandler,
			$this->createMock(GdprEntityMapper::class),
			$this->createMock(EntityRelationMapper::class),
			$settings,
			$this->createMock(RiskLevelService::class),
			$this->createMock(EmlParser::class),
			new SpreadsheetExtractor($logger),
			new PdfExtractor($logger),
			new WordExtractor($logger)
		);

		$service->extractFile(1, ...$arguments);

		return $received;

	}//end optionsFor()

	/**
	 * THE DEFECT: filinq's third argument never reached detection.
	 *
	 * @return void
	 */
	public function testTheEntityTypeFilterReachesDetection(): void {
		$options = $this->optionsFor([true, ['PERSON', 'IBAN']]);

		$this->assertNotNull($options, 'detection must run');
		$this->assertSame(['PERSON', 'IBAN'], ($options['entity_types'] ?? null));

	}//end testTheEntityTypeFilterReachesDetection()

	/**
	 * Without a filter every type is detected, as before.
	 *
	 * @return void
	 */
	public function testNoFilterMeansEveryType(): void {
		$options = $this->optionsFor([true]);

		$this->assertNotNull($options);
		$this->assertArrayNotHasKey('entity_types', $options);

	}//end testNoFilterMeansEveryType()

}//end class
