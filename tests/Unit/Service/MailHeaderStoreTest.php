<?php

/**
 * MailHeaderStoreTest
 *
 * Text extraction of an `.eml` file stores its thread headers, one row per
 * file, replaced on re-extraction (REQ-RTH-010).
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
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\EmlParseException;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Db\MailHeader;
use OCA\OpenRegister\Files\Db\MailHeaderMapper;
use OCA\OpenRegister\Files\Service\RiskLevelService;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor;
use OCA\OpenRegister\Files\Service\TextExtractionService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Tests\Unit\Service\Support\TextExtractionServiceFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The header row is written through extractFile(), the only way extraction runs.
 *
 * @covers \OCA\OpenRegister\Files\Service\TextExtractionService
 * @uses   \OCA\OpenRegister\Files\Db\MailHeader
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\EmlParser
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionStorage
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-collected-mail-files-carry-their-thread-headers-req-rth-010
 */
class MailHeaderStoreTest extends TestCase {
	/**
	 * The file id every test extracts.
	 *
	 * @var integer
	 */
	private const FILE_ID = 1408;

	/**
	 * A reply whose parent is a1 and whose ancestry is a0, a1.
	 *
	 * Without a Date header: on the current base a parsed date makes
	 * EmlFlattener::flatten() throw (pinned by EmlParserGoldenTest). The sent
	 * date is covered in MailHeaderTest.
	 *
	 * @var string
	 */
	private const REPLY = "From: Alice <alice@example.com>\r\n"
		. "Subject: Re: uw verzoek\r\n"
		. "Message-ID: <a2@gemeente.nl>\r\n"
		. "In-Reply-To: <a1@gemeente.nl>\r\n"
		. "References: <a0@gemeente.nl> <a1@gemeente.nl>\r\n"
		. "\r\nDank voor uw bericht, hierbij de gevraagde stukken.\r\n";

	private FileMapper&MockObject $fileMapper;
	private ChunkMapper&MockObject $chunkMapper;
	private IRootFolder&MockObject $rootFolder;
	private LoggerInterface&MockObject $logger;
	private MailHeaderMapper&MockObject $mailHeaders;

	/**
	 * Mocks for the stores; the file is an `.eml` in bob's home.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->fileMapper  = $this->createMock(FileMapper::class);
		$this->chunkMapper = $this->createMock(ChunkMapper::class);
		$this->rootFolder  = $this->createMock(IRootFolder::class);
		$this->logger      = $this->createMock(LoggerInterface::class);
		$this->mailHeaders = $this->createMock(MailHeaderMapper::class);

		$this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(null);
	}//end setUp()

	/**
	 * The service over the mocks with this EML parser.
	 *
	 * @param EmlParser $parser The parser.
	 *
	 * @return TextExtractionService
	 */
	private function service(EmlParser $parser): TextExtractionService {
		return TextExtractionServiceFactory::create(
			$this->fileMapper,
			$this->chunkMapper,
			$this->rootFolder,
			$this->createMock(IDBConnection::class),
			$this->logger,
			$this->createMock(MagicMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(EntityRecognitionHandler::class),
			$this->createMock(SettingsService::class),
			$this->createMock(RiskLevelService::class),
			$parser,
			new SpreadsheetExtractor($this->logger),
			new PdfExtractor($this->logger),
			new WordExtractor($this->logger),
			$this->mailHeaders
		);
	}//end service()

	/**
	 * Put a file of this mime type and content in bob's home.
	 *
	 * @param string $mimeType The mime type.
	 * @param string $content  The content.
	 *
	 * @return void
	 */
	private function arrangeFile(string $mimeType, string $content): void {
		$this->fileMapper->method('getFile')->willReturn(
			['mtime' => 100, 'mimetype' => $mimeType, 'path' => 'files/mail/reply.eml', 'name' => 'reply.eml', 'storage_id' => 'home::bob']
		);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(self::FILE_ID);
		$file->method('getContent')->willReturn($content);
		$file->method('fopen')->willReturnCallback(
			static function () use ($content) {
				$stream = fopen('php://memory', 'r+');
				fwrite($stream, $content);
				rewind($stream);
				return $stream;
			}
		);

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->with(self::FILE_ID)->willReturn([$file]);
		$this->rootFolder->method('getUserFolder')->with('bob')->willReturn($folder);
	}//end arrangeFile()

	/**
	 * Extracting an `.eml` stores its id, parent, ancestry and sent date for that file.
	 *
	 * @return void
	 */
	public function testExtractionStoresTheHeaderRow(): void {
		$this->arrangeFile(mimeType: 'message/rfc822', content: self::REPLY);

		$stored = null;
		$this->mailHeaders->expects($this->once())
			->method('replaceForFile')
			->willReturnCallback(
				static function (MailHeader $row) use (&$stored): MailHeader {
					$stored = $row;
					return $row;
				}
			);
		$this->mailHeaders->expects($this->never())->method('deleteByFileId');

		$this->service(parser: new EmlParser(logger: $this->logger))->extractFile(self::FILE_ID);

		$this->assertInstanceOf(MailHeader::class, $stored);
		$this->assertSame(self::FILE_ID, $stored->getFileId());
		$this->assertSame('a2@gemeente.nl', $stored->getMessageId());
		$this->assertSame('a1@gemeente.nl', $stored->getInReplyTo());
		$this->assertSame(['a0@gemeente.nl', 'a1@gemeente.nl'], $stored->references());
		$this->assertNull($stored->getSentAt());
	}//end testExtractionStoresTheHeaderRow()

	/**
	 * The text is still indexed after the header row is written.
	 *
	 * @return void
	 */
	public function testTheTextIsStillIndexed(): void {
		$this->arrangeFile(mimeType: 'message/rfc822', content: self::REPLY);
		$this->mailHeaders->method('replaceForFile')->willReturnArgument(0);

		$this->chunkMapper->expects($this->once())->method('deleteBySource')->with('file', self::FILE_ID);
		$this->chunkMapper->expects($this->atLeastOnce())->method('insert');

		$this->service(parser: new EmlParser(logger: $this->logger))->extractFile(self::FILE_ID);
	}//end testTheTextIsStillIndexed()

	/**
	 * A file that no longer parses forgets the row an earlier extraction wrote.
	 *
	 * @return void
	 */
	public function testAFileThatNoLongerParsesForgetsItsRow(): void {
		$this->arrangeFile(mimeType: 'message/rfc822', content: 'not mail');

		$parser = $this->createMock(EmlParser::class);
		$parser->method('parse')->willThrowException(new EmlParseException('broken'));

		$this->mailHeaders->expects($this->once())->method('deleteByFileId')->with(self::FILE_ID);
		$this->mailHeaders->expects($this->never())->method('replaceForFile');

		try {
			$this->service(parser: $parser)->extractFile(self::FILE_ID);
		} catch (\Exception $e) {
			// No text is the extraction's own failure; the row is what this test is about.
			$this->assertSame('Text extraction returned no result for source.', $e->getMessage());
		}
	}//end testAFileThatNoLongerParsesForgetsItsRow()

	/**
	 * A failing header store is logged and does not cost the file its text index.
	 *
	 * @return void
	 */
	public function testAHeaderStoreFailureKeepsTheTextIndex(): void {
		$this->arrangeFile(mimeType: 'message/rfc822', content: self::REPLY);
		$this->mailHeaders->method('replaceForFile')->willThrowException(new RuntimeException('db down'));

		$this->logger->expects($this->once())
			->method('warning')
			->with('[TextExtractionService] Could not store the mail thread headers', $this->callback(
				static fn (array $context): bool => $context['fileId'] === self::FILE_ID && $context['exception'] === RuntimeException::class
			));
		$this->chunkMapper->expects($this->atLeastOnce())->method('insert');

		$this->service(parser: new EmlParser(logger: $this->logger))->extractFile(self::FILE_ID);
	}//end testAHeaderStoreFailureKeepsTheTextIndex()

	/**
	 * A file that is not mail never touches the header store.
	 *
	 * @return void
	 */
	public function testAPlainTextFileStoresNoHeaders(): void {
		$this->arrangeFile(mimeType: 'text/plain', content: 'de inhoud van een testdocument');

		$this->mailHeaders->expects($this->never())->method('replaceForFile');
		$this->mailHeaders->expects($this->never())->method('deleteByFileId');

		$this->service(parser: new EmlParser(logger: $this->logger))->extractFile(self::FILE_ID);
	}//end testAPlainTextFileStoresNoHeaders()
}//end class
