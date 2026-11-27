<?php

/**
 * Tests for TextExtractionService::reextractFile(): a file is extracted again even when its chunks are up to date.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Files\Service\RiskLevelService;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor;
use OCA\OpenRegister\Files\Service\TextExtractionService;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCA\OpenRegister\Tests\Unit\Service\Support\TextExtractionServiceFactory;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

#[CoversClass(TextExtractionService::class)]
final class TextExtractionReextractFileTest extends TestCase {

	private FileMapperInterface&MockObject $fileMapper;

	private ChunkMapper&MockObject $chunkMapper;

	private SettingsServiceInterface&MockObject $settings;

	protected function setUp(): void {
		$this->fileMapper = $this->createMock(FileMapperInterface::class);
		$this->chunkMapper = $this->createMock(ChunkMapper::class);
		$this->settings = $this->createMock(SettingsServiceInterface::class);
	}

	private function service(): TextExtractionService {
		return TextExtractionServiceFactory::create(
			$this->fileMapper,
			$this->chunkMapper,
			$this->createMock(IRootFolder::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(MagicMapperInterface::class),
			$this->createMock(SchemaMapperInterface::class),
			$this->createMock(RegisterMapperInterface::class),
			$this->createMock(EntityRecognitionHandler::class),
			$this->settings,
			$this->createMock(RiskLevelService::class),
			$this->createMock(EmlParser::class),
			$this->createMock(SpreadsheetExtractor::class),
			$this->createMock(PdfExtractor::class),
			$this->createMock(WordExtractor::class)
		);
	}

	public function testAnUpToDateFileIsLeftAloneByAnOrdinaryExtraction(): void {
		$this->fileMapper->method('getFile')->with(91)->willReturn(['fileid' => 91, 'mtime' => 100, 'size' => 10, 'mimetype' => 'text/plain']);
		$this->chunkMapper->expects($this->once())->method('getLatestUpdatedTimestamp')->with('file', 91)->willReturn(200);
		$this->settings->expects($this->never())->method($this->anything());

		$this->service()->extractFile(91);
	}

	public function testAReextractionIgnoresUpToDateChunksAndGoesOnToExtract(): void {
		$this->fileMapper->method('getFile')->with(91)->willReturn(['fileid' => 91, 'mtime' => 100, 'size' => 10, 'mimetype' => 'text/plain']);
		$this->chunkMapper->expects($this->never())->method('getLatestUpdatedTimestamp');
		// Past the up-to-date check the extraction opens the file; the empty
		// root folder stops it there, which proves it did not return early.
		$stopped = null;
		try {
			$this->service()->reextractFile(91);
		} catch (Throwable $thrown) {
			$stopped = $thrown;
		}

		$this->assertNotNull($stopped);
		$this->assertSame('File not found in Nextcloud file system', $stopped->getMessage());
	}

	public function testAReextractionOfAMissingFileSaysSo(): void {
		$this->fileMapper->method('getFile')->willReturn(null);

		$this->expectException(NotFoundException::class);
		$this->service()->reextractFile(404);
	}
}
