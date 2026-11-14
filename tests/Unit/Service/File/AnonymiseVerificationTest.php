<?php

/**
 * AnonymiseVerificationTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.2
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Files\Db\AnonymisationLog;
use OCA\OpenRegister\Files\Db\AnonymisationLogMapper;
use OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler;
use OCA\OpenRegister\Files\Service\File\DocumentProcessingPlatform;
use OCA\OpenRegister\Files\Service\File\DocumentRedactionServices;
use OCA\OpenRegister\Files\Service\File\OfficeDocumentSanitizer;
use OCA\OpenRegister\Service\Anonymisation\RedactionVerdictStore;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionProperty;
use ZipArchive;

/**
 * The verdict is taken on the bytes `FileService::anonymizeDocument()` wrote,
 * kept on the copy and on the run, and no log line carries a redacted value.
 *
 * @covers \OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler
 * @covers \OCA\OpenRegister\Service\Anonymisation\RedactionVerdictStore
 * @covers \OCA\OpenRegister\Service\FileService
 * @covers \OCA\OpenRegister\Files\Db\AnonymisationLog
 * @uses   \OCA\OpenRegister\Service\Anonymisation\RedactionIrreversibilityVerifier
 * @uses   \OCA\OpenRegister\Service\Anonymisation\RedactionOutputMode
 * @uses   \OCA\OpenRegister\Service\Anonymisation\RedactionLeakRoute
 * @uses   \OCA\OpenRegister\Service\Anonymisation\RedactionPdfRouteReader
 * @uses   \OCA\OpenRegister\Service\Anonymisation\RedactionPackageRouteReader
 * @uses   \OCA\OpenRegister\Service\Anonymisation\RedactionRegionCutter
 * @uses   \OCA\OpenRegister\Service\Anonymisation\PipelineSteps
 * @uses   \OCA\OpenRegister\Files\Service\File\DocumentProcessingPlatform
 * @uses   \OCA\OpenRegister\Files\Service\File\DocumentRedactionServices
 * @uses   \OCA\OpenRegister\Files\Service\File\DocumentReplacement
 * @uses   \OCA\OpenRegister\Files\Service\File\PlaceholderIdTranslator
 * @uses   \OCA\OpenRegister\Files\Service\File\PlaceholderLookup
 * @uses   \OCA\OpenRegister\Files\Service\File\PlaceholderText
 * @uses   \OCA\OpenRegister\Files\Service\File\DocxTextReplacer
 * @uses   \OCA\OpenRegister\Files\Service\File\Pdf\PdfTextReplacer
 * @uses   \OCA\OpenRegister\Files\Service\File\Pdf\ExtractedText
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationRequest
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\WordReplacementRequest
 * @uses   \OCA\OpenRegister\Service\File\DocumentAnonymization
 */
class AnonymiseVerificationTest extends TestCase {

	/**
	 * Output written per name.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * Values written to the copy's metadata, by key.
	 *
	 * @var array<string, string>
	 */
	private array $metadata = [];

	/**
	 * The log rows inserted.
	 *
	 * @var list<AnonymisationLog>
	 */
	private array $logRows = [];

	/**
	 * Every log line, message and context, as JSON.
	 *
	 * @var list<string>
	 */
	private array $logLines = [];

	/**
	 * Temp files to remove.
	 *
	 * @var list<string>
	 */
	private array $tempPaths = [];

	protected function tearDown(): void {
		foreach ($this->tempPaths as $path) {
			if (file_exists($path) === true) {
				unlink($path);
			}
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * A FileService whose document handler is real and whose collaborators record.
	 */
	private function fileService(): FileService {
		$mapper = $this->createMock(EntityRelationMapper::class);
		$mapper->method('findSkippedEntityValuesForFile')->willReturn([]);
		$mapper->method('findEntityIdsByValueForFile')->willReturn([]);

		$sanitizer = $this->createMock(OfficeDocumentSanitizer::class);
		$sanitizer->method('isSanitizable')->willReturn(false);

		$logMapper = $this->createMock(AnonymisationLogMapper::class);
		$logMapper->method('insert')->willReturnCallback(
			function (AnonymisationLog $entity): AnonymisationLog {
				$this->logRows[] = $entity;
				return $entity;
			}
		);

		$fileMetadata = $this->createMock(IFilesMetadata::class);
		$fileMetadata->method('setString')->willReturnCallback(
			function (string $key, string $value) use ($fileMetadata): IFilesMetadata {
				$this->metadata[$key] = $value;
				return $fileMetadata;
			}
		);
		$metadataManager = $this->createMock(IFilesMetadataManager::class);
		$metadataManager->method('getMetadata')->with(77, true)->willReturn($fileMetadata);

		$logger = $this->recordingLogger();

		$handler = new DocumentProcessingHandler(
			platform: new DocumentProcessingPlatform(
				rootFolder: $this->createMock(IRootFolder::class),
				userSession: $this->createMock(IUserSession::class),
				l10n: null
			),
			redaction: new DocumentRedactionServices(
				entityRelationMapper: $mapper,
				sanitizer: $sanitizer,
				anonymisationLogMapper: $logMapper,
				verdicts: new RedactionVerdictStore(metadataManager: $metadataManager, logger: $logger)
			),
			logger: $logger
		);

		$service = $this->getMockBuilder(FileService::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
		(new ReflectionProperty(FileService::class, 'documentProcessingHandler'))->setValue($service, $handler);

		return $service;
	}//end fileService()

	/**
	 * A logger that keeps every line it is given.
	 */
	private function recordingLogger(): AbstractLogger {
		$lines = &$this->logLines;
		return new class ($lines) extends AbstractLogger {
			/**
			 * @param list<string> $lines Where the lines go.
			 */
			public function __construct(private array &$lines) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->lines[] = (string) json_encode(['level' => $level, 'message' => (string) $message, 'context' => $context]);
			}
		};
	}//end recordingLogger()

	/**
	 * A source file whose parent folder records what is written; the copy reads those bytes back.
	 */
	private function node(string $name, string $content, string $mime): File {
		$parent = $this->createMock(Folder::class);
		$parent->method('nodeExists')->willReturn(false);
		$parent->method('newFile')->willReturnCallback(
			function (string $path, $content = null): File {
				$this->written[$path] = is_resource($content) === true ? (string) stream_get_contents($content) : (string) $content;
				$out = $this->createMock(File::class);
				$out->method('getId')->willReturn(77);
				$out->method('getPath')->willReturn('/files/'.$path);
				$out->method('getName')->willReturn($path);
				$out->method('getContent')->willReturnCallback(fn (): string => $this->written[$path]);
				return $out;
			}
		);

		$path = (string) tempnam(sys_get_temp_dir(), 'or-verify-');
		file_put_contents($path, $content);
		$this->tempPaths[] = $path;

		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(42);
		$node->method('getName')->willReturn($name);
		$node->method('getPath')->willReturn('/files/'.$name);
		$node->method('getMimeType')->willReturn($mime);
		$node->method('getContent')->willReturn($content);
		$node->method('fopen')->willReturnCallback(static fn () => fopen($path, 'r'));
		$node->method('getParent')->willReturn($parent);

		return $node;
	}//end node()

	/**
	 * A one-page DOCX whose body names Jan Jansen, and whose title too when asked.
	 */
	private function docx(string $title = 'Besluit'): string {
		$path = (string) tempnam(sys_get_temp_dir(), 'or-docx-');
		$this->tempPaths[] = $path;
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		$zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>');
		$zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
		$zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Brief aan Jan Jansen over zijn aanvraag.</w:t></w:r></w:p></w:body></w:document>');
		$zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>'.$title.'</dc:title></cp:coreProperties>');
		$zip->close();

		return (string) file_get_contents($path);
	}//end docx()

	/**
	 * A one-page PDF whose page names Jan Jansen.
	 */
	private static function pdf(): string {
		$stream = (string) gzcompress("BT\n/F1 12 Tf\n72 720 Td\n(Brief aan Jan Jansen) Tj\nET\n", 6);
		$objects = [
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '<< /Type /Pages /Kids [ 3 0 R ] /Count 1 >>',
			3 => '<< /Type /Page /Parent 2 0 R /MediaBox [ 0 0 612 792 ] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
			4 => '<< /Length '.strlen($stream)." /Filter /FlateDecode >>\nstream\n".$stream."\nendstream",
			5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
			6 => '<< /Author (Piet Auteur) >>',
		];
		$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ($objects as $oid => $body) {
			$offsets[$oid] = strlen($pdf);
			$pdf .= $oid." 0 obj\n".$body."\nendobj\n";
		}

		$xref = strlen($pdf);
		$pdf .= "xref\n0 7\n0000000000 65535 f \n";
		foreach ($offsets as $offset) {
			$pdf .= sprintf("%010d 00000 n \n", $offset);
		}

		return $pdf."trailer\n<< /Size 7 /Root 1 0 R /Info 6 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
	}//end pdf()

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function formats(): array {
		return [
			'docx' => ['besluit.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx'],
			'pdf' => ['besluit.pdf', 'application/pdf', 'pdf'],
		];
	}//end formats()

	/**
	 * @dataProvider formats
	 */
	public function testTheVerdictIsTakenOnTheWrittenBytes(string $name, string $mime, string $mode): void {
		$content = $mode === 'pdf' ? self::pdf() : $this->docx();
		$service = $this->fileService();

		$service->anonymizeDocument(
			node: $this->node(name: $name, content: $content, mime: $mime),
			entities: [['text' => 'Jan Jansen', 'entityType' => 'PERSON', 'key' => 'p1']]
		);

		$verification = $service->getLastVerification();
		$this->assertNotNull($verification);
		$this->assertSame('clean', $verification['verdict']);
		$this->assertTrue($verification['mayBePublished']);
		$this->assertSame($mode, $verification['outputMode']);
		$this->assertCount(7, $verification['routesChecked']);
		$this->assertSame([], $verification['findings']);

		// The same verdict is kept on the copy and on the run.
		$this->assertSame($verification, json_decode($this->metadata[RedactionVerdictStore::METADATA_KEY], true));
		$this->assertCount(1, $this->logRows);
		$this->assertSame($verification, $this->logRows[0]->getVerificationArray());
	}//end testTheVerdictIsTakenOnTheWrittenBytes()

	/**
	 * The DOCX title is not touched by the text pass, so the written copy still names the person there.
	 */
	public function testAValueLeftInTheWrittenCopyIsLeaking(): void {
		$service = $this->fileService();

		$service->anonymizeDocument(
			node: $this->node(name: 'besluit.docx', content: $this->docx(title: 'Besluit Jan Jansen'), mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
			entities: [['text' => 'Jan Jansen', 'entityType' => 'PERSON', 'key' => 'p1']]
		);

		$verification = $service->getLastVerification();
		$this->assertSame('leaking', $verification['verdict']);
		$this->assertFalse($verification['mayBePublished']);
		$this->assertContains(['route' => 'exif', 'count' => 1], $verification['findings']);
	}//end testAValueLeftInTheWrittenCopyIsLeaking()

	public function testNoLogLineCarriesAValue(): void {
		$service = $this->fileService();

		$service->anonymizeDocument(
			node: $this->node(name: 'besluit.docx', content: $this->docx(title: 'Besluit Jan Jansen'), mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
			entities: [['text' => 'Jan Jansen', 'entityType' => 'PERSON', 'key' => 'p1']]
		);

		$this->assertNotSame([], $this->logLines, 'the run logs');
		foreach ($this->logLines as $line) {
			$this->assertStringNotContainsStringIgnoringCase('jan jansen', $line);
		}

		$this->assertStringNotContainsStringIgnoringCase('jan jansen', (string) $this->logRows[0]->getVerification());
		$this->assertStringNotContainsStringIgnoringCase('jan jansen', $this->metadata[RedactionVerdictStore::METADATA_KEY]);
	}//end testNoLogLineCarriesAValue()
}//end class
