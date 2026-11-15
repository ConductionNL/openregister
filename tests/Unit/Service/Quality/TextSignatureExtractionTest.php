<?php

/**
 * Unit tests for the text signature kept by text extraction.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Quality
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Quality;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\TextSignature as TextSignatureRow;
use OCA\OpenRegister\Db\TextSignatureMapper;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Service\RiskLevelService;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionSources;
use OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionStorage;
use OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor;
use OCA\OpenRegister\Files\Service\TextExtractionService;
use OCA\OpenRegister\Service\Quality\TextSignature;
use OCA\OpenRegister\Service\Quality\TextSignatureStore;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Tests\Unit\Service\Support\TextExtractionServiceFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A file's signature follows its text through TextExtractionService::extractFile() (REQ-NDT-001).
 *
 * @covers \OCA\OpenRegister\Service\Quality\TextSignatureStore
 * @covers \OCA\OpenRegister\Files\Service\TextExtractionService
 * @uses   \OCA\OpenRegister\Service\Quality\TextSignature
 * @uses   \OCA\OpenRegister\Db\TextSignature
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionReaders
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionSources
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionStorage
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionObjects
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionAnalysis
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\SourceText
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\ChunkSource
 * @uses   \OCA\OpenRegister\Files\Service\ExtractionSource
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\EmlParser
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor
 * @uses   \OCA\OpenRegister\Files\Db\Chunk
 */
final class TextSignatureExtractionTest extends TestCase {

	/**
	 * The stored signature rows, by file id, as the mapper would hold them.
	 *
	 * @var array<int, TextSignatureRow>
	 */
	private array $rows = [];

	/**
	 * The file's current text.
	 *
	 * @var string
	 */
	private string $text = '';

	/**
	 * Its mtime, raised on every edit so the chunks read as stale.
	 *
	 * @var int
	 */
	private int $mtime = 1700000000;

	/**
	 * Eighty distinct words: well above the shingle floor.
	 *
	 * @param string $variant Prefix that makes the words of one text differ from another's.
	 *
	 * @return string The text.
	 */
	private static function longText(string $variant): string {
		$words = [];
		for ($i = 0; $i < 80; $i++) {
			$words[] = $variant.'woord'.$i;
		}

		return implode(' ', $words);
	}//end longText()

	/**
	 * The signature mapper over the in-memory rows.
	 *
	 * @param bool $failing Whether every write throws.
	 *
	 * @return TextSignatureMapper The mapper.
	 */
	private function mapper(bool $failing=false): TextSignatureMapper {
		$mapper = $this->createMock(TextSignatureMapper::class);
		$mapper->method('replaceForFile')->willReturnCallback(
			function (TextSignatureRow $row) use ($failing): TextSignatureRow {
				if ($failing === true) {
					throw new RuntimeException('database gone');
				}

				$this->rows[(int) $row->getFileId()] = $row;
				return $row;
			}
		);
		$mapper->method('deleteByFileId')->willReturnCallback(
			function (int $fileId): int {
				$had = isset($this->rows[$fileId]);
				unset($this->rows[$fileId]);
				return (int) $had;
			}
		);

		return $mapper;
	}//end mapper()

	/**
	 * Extract file 91 with the current text.
	 *
	 * @param TextSignatureMapper $mapper The signature mapper the store writes to.
	 *
	 * @return void
	 */
	private function extract(TextSignatureMapper $mapper): void {
		$fileMapper = $this->createMock(FileMapper::class);
		$fileMapper->method('getFile')->willReturnCallback(
			fn (): array => [
				'fileid' => 91, 'mimetype' => 'text/plain', 'name' => 'mail.txt', 'path' => '/admin/files/mail.txt',
				'size' => strlen($this->text), 'mtime' => $this->mtime,
			]
		);
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn('text/plain');
		$file->method('getName')->willReturn('mail.txt');
		$file->method('getContent')->willReturnCallback(fn (): string => $this->text);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->with(91)->willReturn([$file]);

		$chunkMapper = $this->createMock(ChunkMapper::class);
		$chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(null);
		$chunkMapper->method('insert')->willReturnArgument(0);

		$logger = new NullLogger();
		$db     = $this->createMock(IDBConnection::class);
		$args   = TextExtractionServiceFactory::args(
			$fileMapper,
			$chunkMapper,
			$rootFolder,
			$db,
			$logger,
			$this->createMock(MagicMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(EntityRecognitionHandler::class),
			$this->createMock(SettingsService::class),
			$this->createMock(RiskLevelService::class),
			new EmlParser($logger),
			new SpreadsheetExtractor($logger),
			new PdfExtractor($logger),
			new WordExtractor($logger)
		);
		$sources = $args[0];
		$args[0] = new TextExtractionSources(
			new TextExtractionStorage($fileMapper, $rootFolder, $db, null, new TextSignatureStore($mapper, $logger)),
			$sources->objects
		);

		(new TextExtractionService(...$args))->extractFile(91);
	}//end extract()

	/**
	 * A re-extracted file keeps exactly one signature, the one of its new text.
	 *
	 * @return void
	 */
	public function testReExtractionReplacesTheSignature(): void {
		$mapper     = $this->mapper();
		$this->text = self::longText(variant: 'eerste');
		$this->extract(mapper: $mapper);

		$signer = new TextSignature();
		$this->assertCount(1, $this->rows);
		$this->assertSame($signer->sign(text: $this->text)['signature'], $this->rows[91]->getSignatureValues());

		$this->text  = self::longText(variant: 'tweede');
		$this->mtime = ($this->mtime + 60);
		$this->extract(mapper: $mapper);

		$expected = $signer->sign(text: $this->text);
		$this->assertCount(1, $this->rows);
		$this->assertSame($expected['signature'], $this->rows[91]->getSignatureValues());
		$this->assertSame($expected['textSha256'], $this->rows[91]->getTextSha256());
		$this->assertSame(76, $this->rows[91]->getShingleCount());
	}//end testReExtractionReplacesTheSignature()

	/**
	 * A cover note gets a row with its shingle count and no signature, so a grouping can call it tooShort.
	 *
	 * @return void
	 */
	public function testAShortTextIsStoredWithoutASignature(): void {
		$this->text = 'Zie bijlage, met vriendelijke groet.';
		$this->extract(mapper: $this->mapper());

		$this->assertNull($this->rows[91]->getSignatureValues());
		$this->assertSame(1, $this->rows[91]->getShingleCount());
	}//end testAShortTextIsStoredWithoutASignature()

	/**
	 * A signature that cannot be written does not fail the extraction.
	 *
	 * @return void
	 */
	public function testAFailedSignatureWriteLeavesTheExtractionStanding(): void {
		$this->text = self::longText(variant: 'derde');
		$this->extract(mapper: $this->mapper(failing: true));

		$this->assertSame([], $this->rows);
	}//end testAFailedSignatureWriteLeavesTheExtractionStanding()

	/**
	 * The store says whether each write ran.
	 *
	 * @return void
	 */
	public function testTheStoreReportsWhatItDid(): void {
		$store = new TextSignatureStore($this->mapper(), new NullLogger());
		$this->assertTrue($store->store(fileId: 5, text: self::longText(variant: 'x')));
		$this->assertArrayHasKey(5, $this->rows);
		$this->assertTrue($store->forget(fileId: 5));
		$this->assertSame([], $this->rows);

		$failing = $this->createMock(TextSignatureMapper::class);
		$failing->method('deleteByFileId')->willThrowException(new RuntimeException('database gone'));
		$failing->method('replaceForFile')->willThrowException(new RuntimeException('database gone'));
		$broken = new TextSignatureStore($failing, new NullLogger());
		$this->assertFalse($broken->forget(fileId: 5));
		$this->assertFalse($broken->store(fileId: 5, text: 'kort'));
	}//end testTheStoreReportsWhatItDid()
}//end class
