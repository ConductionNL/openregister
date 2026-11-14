<?php

/**
 * PlaceholderGroundTest
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
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler;
use OCA\OpenRegister\Files\Service\File\DocumentProcessingPlatform;
use OCA\OpenRegister\Files\Service\File\DocumentRedactionServices;
use OCA\OpenRegister\Files\Service\File\OfficeDocumentSanitizer;
use OCA\OpenRegister\Files\Service\File\Pdf\ExtractedText;
use OCA\OpenRegister\Files\Service\File\Pdf\PdfTextReplacer;
use OCA\OpenRegister\Files\Service\File\PlaceholderText;
use OCA\OpenRegister\Files\Service\File\SanitizationReport;
use OCA\OpenRegister\Files\Service\File\SanitizationResult;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Kernel\Contracts\DocumentProcessingHandlerInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationRequest;
use OCA\OpenRegister\Service\File\DocumentAnonymization;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ZipArchive;

/**
 * The exception ground in the placeholder (REQ-PIS-011) and the mask form
 * computed where the placeholder is built (REQ-PIS-010).
 *
 * @covers \OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler
 * @covers \OCA\OpenRegister\Files\Service\File\PlaceholderText
 * @covers \OCA\OpenRegister\Files\Service\File\PlaceholderLookup
 * @covers \OCA\OpenRegister\Files\Service\File\Pdf\PdfTextReplacer
 * @uses   \OCA\OpenRegister\Files\Service\File\MaskForm
 * @uses   \OCA\OpenRegister\Service\Settings\FileSettingsHandler
 * @uses   \OCA\OpenRegister\Db\EntityRelation
 * @uses   \OCA\OpenRegister\Files\Service\File\DocumentProcessingPlatform
 * @uses   \OCA\OpenRegister\Files\Service\File\DocumentRedactionServices
 * @uses   \OCA\OpenRegister\Files\Service\File\DocumentReplacement
 * @uses   \OCA\OpenRegister\Files\Service\File\PlaceholderIdTranslator
 * @uses   \OCA\OpenRegister\Files\Service\File\DocxTextReplacer
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationReport
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationResult
 * @uses   \OCA\OpenRegister\Files\Service\File\Pdf\ExtractedText
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationRequest
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\WordReplacementRequest
 * @uses   \OCA\OpenRegister\Service\File\DocumentAnonymization
 * @uses   \OCA\OpenRegister\Service\FileService
 */
class PlaceholderGroundTest extends TestCase {

	/**
	 * The sentence every fixture carries.
	 *
	 * @var string
	 */
	private const SENTENCE = 'Brief aan Maria Bakker over rekening NL91ABNA0417164300.';

	/**
	 * Output written per name.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * Temp files to remove.
	 *
	 * @var array<int, string>
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
	 * A handler whose mapper holds Maria Bakker (entity 501) and the IBAN (entity 502) on file 42.
	 *
	 * @param array<int, array<string, mixed>> $anonymisationRows The rows findEntitiesForAnonymization returns.
	 * @param array<string, mixed>             $maskForms         The stored mask forms.
	 * @param OfficeDocumentSanitizer|null     $sanitizer         The sanitiser; a non-sanitising one when null.
	 */
	private function handler(array $anonymisationRows, array $maskForms=[], ?OfficeDocumentSanitizer $sanitizer=null): DocumentProcessingHandler {
		$mapper = $this->createMock(EntityRelationMapper::class);
		$mapper->method('findSkippedEntityValuesForFile')->willReturn([]);
		$mapper->method('findEntityIdsByValueForFile')->willReturn(
			[
				'Maria Bakker' => ['id' => 501, 'type' => 'PERSON'],
				'NL91ABNA0417164300' => ['id' => 502, 'type' => 'IBAN'],
			]
		);
		$mapper->method('findEntitiesForAnonymization')->with(42)->willReturn($anonymisationRows);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => ['PERSON' => 'PERSOON'][$text] ?? $text);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn((string) json_encode(['anonymisation' => ['maskForms' => $maskForms]]));

		if ($sanitizer === null) {
			$sanitizer = $this->createMock(OfficeDocumentSanitizer::class);
			$sanitizer->method('isSanitizable')->willReturn(false);
		}

		return new DocumentProcessingHandler(
			platform: new DocumentProcessingPlatform(
				rootFolder: $this->createMock(IRootFolder::class),
				userSession: $this->createMock(IUserSession::class),
				l10n: $l10n
			),
			redaction: new DocumentRedactionServices(
				entityRelationMapper: $mapper,
				sanitizer: $sanitizer,
				fileSettings: new FileSettingsHandler($appConfig)
			),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end handler()

	/**
	 * A source file named $name in a folder that records what is written.
	 *
	 * @param string $name    The file name.
	 * @param string $content The file bytes.
	 * @param string $mime    The mime type.
	 */
	private function node(string $name, string $content, string $mime): File {
		$parent = $this->createMock(Folder::class);
		$parent->method('nodeExists')->willReturnCallback(fn (string $path): bool => array_key_exists($path, $this->written));
		$parent->method('get')->willReturnCallback(
			function (string $path): File {
				$existing = $this->createMock(File::class);
				$existing->method('delete')->willReturnCallback(
					function () use ($path): void {
						unset($this->written[$path]);
					}
				);
				return $existing;
			}
		);
		$parent->method('newFile')->willReturnCallback(
			function (string $path, $content = null): File {
				$this->written[$path] = is_resource($content) === true ? (string) stream_get_contents($content) : (string) $content;
				$out = $this->createMock(File::class);
				$out->method('getPath')->willReturn('/files/'.$path);
				$out->method('getName')->willReturn($path);
				return $out;
			}
		);

		$path = $this->tempFile(bytes: $content);
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

	private function tempFile(string $bytes): string {
		$path = (string) tempnam(sys_get_temp_dir(), 'or-ground-');
		file_put_contents($path, $bytes);
		$this->tempPaths[] = $path;
		return $path;
	}//end tempFile()

	/**
	 * The entities the anonymise endpoint and opencatalogi pass.
	 *
	 * @return array<int, array{text: string, entityType: string, key: string}>
	 */
	private static function entities(): array {
		return [
			['text' => 'Maria Bakker', 'entityType' => 'PERSON', 'key' => 'p1'],
			['text' => 'NL91ABNA0417164300', 'entityType' => 'IBAN', 'key' => 'i1'],
		];
	}//end entities()

	/**
	 * A relation row as findEntitiesForAnonymization returns it, its bases taken from a real EntityRelation.
	 *
	 * @param string             $value The entity value.
	 * @param array<int, string>|null $bases The relation's bases.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(string $value, ?array $bases): array {
		$relation = new EntityRelation();
		$relation->setBases($bases);
		$encoded = null;
		if ($relation->getBases() !== null) {
			$encoded = json_encode($relation->getBases());
		}

		return ['entity_value' => $value, 'entity_type' => 'PERSON', 'bases' => $encoded];
	}//end row()

	private function anonymiseText(DocumentProcessingHandler $handler): string {
		$handler->anonymize(request: new DocumentAnonymisationRequest(node: $this->node(name: 'brief.txt', content: self::SENTENCE, mime: 'text/plain'), entities: self::entities()));
		return $this->written['brief_anonymized.txt'];
	}//end anonymiseText()

	public function testAGroundIsPrintedInThePlaceholder(): void {
		$handler = $this->handler(anonymisationRows: [self::row(value: 'Maria Bakker', bases: ['5.1.2e']), self::row(value: 'Maria Bakker', bases: ['5.1.2e', '5.1.1d'])]);

		$output = $this->anonymiseText(handler: $handler);

		$this->assertSame('Brief aan [PERSOON: 1; 5.1.2e, 5.1.1d] over rekening [IBAN: 2].', $output);
		$this->assertSame([501 => '[PERSOON: 1; 5.1.2e, 5.1.1d]', 502 => '[IBAN: 2]'], $handler->getLastPlaceholderMap());
	}//end testAGroundIsPrintedInThePlaceholder()

	public function testNoBasesKeepsThePlaceholder(): void {
		$handler = $this->handler(anonymisationRows: [self::row(value: 'Maria Bakker', bases: null), self::row(value: 'NL91ABNA0417164300', bases: [])]);

		$this->assertSame('Brief aan [PERSOON: 1] over rekening [IBAN: 2].', $this->anonymiseText(handler: $handler));
	}//end testNoBasesKeepsThePlaceholder()

	public function testAMaskedValueCarriesItsGroundAfterIt(): void {
		$handler = $this->handler(
			anonymisationRows: [self::row(value: 'NL91ABNA0417164300', bases: ['5.1.2e'])],
			maskForms: ['IBAN' => ['form' => 'last', 'n' => 4]]
		);

		$this->assertSame('Brief aan [PERSOON: 1] over rekening **************4300 [5.1.2e].', $this->anonymiseText(handler: $handler));
		$this->assertSame('**************4300 [5.1.2e]', $handler->getLastPlaceholderMap()[502]);
	}//end testAMaskedValueCarriesItsGroundAfterIt()

	public function testAMaskThatShowsTooMuchKeepsThePlaceholder(): void {
		$handler = $this->handler(anonymisationRows: [], maskForms: ['IBAN' => ['form' => 'last', 'n' => 10]]);

		$this->assertSame('Brief aan [PERSOON: 1] over rekening [IBAN: 2].', $this->anonymiseText(handler: $handler));
	}//end testAMaskThatShowsTooMuchKeepsThePlaceholder()

	public function testAFailedGroundReadPrintsNoGround(): void {
		$mapper = $this->createMock(EntityRelationMapper::class);
		$mapper->method('findEntityIdsByValueForFile')->willReturn(['Maria Bakker' => ['id' => 501, 'type' => 'PERSON']]);
		$mapper->method('findEntitiesForAnonymization')->willThrowException(new \RuntimeException('db gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('exception grounds'));
		$sanitizer = $this->createMock(OfficeDocumentSanitizer::class);
		$handler = new DocumentProcessingHandler(
			platform: new DocumentProcessingPlatform(rootFolder: $this->createMock(IRootFolder::class), userSession: $this->createMock(IUserSession::class)),
			redaction: new DocumentRedactionServices(entityRelationMapper: $mapper, sanitizer: $sanitizer),
			logger: $logger
		);

		$this->assertStringContainsString('Brief aan [PERSON: 1] over', $this->anonymiseText(handler: $handler));
	}//end testAFailedGroundReadPrintsNoGround()

	public function testTheResidualCheckParsesTheExtendedForm(): void {
		$this->assertSame(['type' => 'PERSOON', 'id' => '1', 'grounds' => ['5.1.2e', '5.1.1d']], PlaceholderText::parse(placeholder: '[PERSOON: 1; 5.1.2e, 5.1.1d]'));
		$this->assertSame(['type' => 'PERSOON', 'id' => '1', 'grounds' => []], PlaceholderText::parse(placeholder: '[PERSOON: 1]'));
		$this->assertNull(PlaceholderText::parse(placeholder: '**************4300 [5.1.2e]'));

		$records = new ReflectionMethod(DocumentProcessingHandler::class, 'residualEntityRecords');
		$this->assertSame(
			[['text' => 'Maria Bakker', 'type' => 'PERSOON', 'id' => '1']],
			$records->invoke($this->handler(anonymisationRows: []), ['Maria Bakker'], ['Maria Bakker' => '[PERSOON: 1; 5.1.2e]'])
		);

		$leaked = '[PERSOON: 1; 5.1.2e] schreef aan Maria Bakker.';
		$clean = '[PERSOON: 1; 5.1.2e] schreef aan [PERSOON: 1; 5.1.2e].';
		$this->assertTrue((new ExtractedText(raw: $leaked, normalised: $leaked))->holds(rawNeedle: 'Maria Bakker', normalisedNeedle: 'Maria Bakker'), 'the unreplaced occurrence is reported');
		$this->assertFalse((new ExtractedText(raw: $clean, normalised: $clean))->holds(rawNeedle: 'Maria Bakker', normalisedNeedle: 'Maria Bakker'), 'the placeholder is not');
	}//end testTheResidualCheckParsesTheExtendedForm()

	public function testCollapseKeepsTheGround(): void {
		$this->assertSame('[PERSOON: 1; 5.1.2e] schreef', PdfTextReplacer::collapseAdjacentDuplicatePlaceholders(text: '[PERSOON: 1; 5.1.2e] [PERSOON: 1; 5.1.2e] schreef'));
		$this->assertSame('[PERSON: 7] schreef', PdfTextReplacer::collapseAdjacentDuplicatePlaceholders(text: '[PERSON: 7] - [PERSON: 7] schreef'));
		$different = '[PERSOON: 1; 5.1.2e] [PERSOON: 1; 5.1.1d]';
		$this->assertSame($different, PdfTextReplacer::collapseAdjacentDuplicatePlaceholders(text: $different), 'different grounds stay apart');
		$this->assertSame('[PERSOON: 1] [PERSOON: 1; 5.1.2e]', PdfTextReplacer::collapseAdjacentDuplicatePlaceholders(text: '[PERSOON: 1] [PERSOON: 1; 5.1.2e]'));
	}//end testCollapseKeepsTheGround()

	public function testGroundsAreReadAsStored(): void {
		$this->assertSame(['5.1.2e', '5.1.1d'], PlaceholderText::groundsOf(bases: '["5.1.2e"," 5.1.1d ","5.1.2e",7,""]'));
		$this->assertSame(['a b'], PlaceholderText::groundsOf(bases: ['a]b']), 'a bracket cannot end the placeholder');
		$this->assertSame([], PlaceholderText::groundsOf(bases: null));
		$this->assertSame([], PlaceholderText::groundsOf(bases: 'not json'));
		$this->assertSame('[PERSOON: 1]', PlaceholderText::placeholder(label: 'PERSOON', number: '1', grounds: []));
		$this->assertSame('**43', PlaceholderText::masked(masked: '**43', grounds: []));
	}//end testGroundsAreReadAsStored()

	/**
	 * Every output mode prints the ground, through the call opencatalogi's DocumentRedactor makes.
	 */
	public function testEveryOutputModePrintsTheGround(): void {
		$rows = [self::row(value: 'Maria Bakker', bases: ['5.1.2e'])];
		$expected = '[PERSOON: 1; 5.1.2e]';

		// Plain text.
		$this->written = [];
		$this->fileService(handler: $this->handler(anonymisationRows: $rows))->anonymizeDocument(
			$this->node(name: 'brief.txt', content: self::SENTENCE, mime: 'text/plain'),
			new DocumentAnonymization(entities: self::entities())
		);
		$this->assertStringContainsString($expected, $this->written['brief_anonymized.txt']);

		// PDF.
		$this->written = [];
		$this->fileService(handler: $this->handler(anonymisationRows: $rows))->anonymizeDocument(
			$this->node(name: 'brief.pdf', content: self::pdf(text: 'Brief aan Maria Bakker over de aanvraag.'), mime: 'application/pdf'),
			new DocumentAnonymization(entities: self::entities())
		);
		$pdfText = (new PdfExtractor(logger: $this->createMock(LoggerInterface::class)))->extractText(pdfBytes: $this->written['brief_anonymized.pdf']);
		$this->assertStringContainsString($expected, $pdfText);
		$this->assertStringNotContainsString('Maria Bakker', $pdfText);

		// DOCX.
		$this->written = [];
		$docx = (string) file_get_contents(dirname(__DIR__, 3).'/Data/Word/word-structures.docx');
		$this->fileService(handler: $this->handler(anonymisationRows: $rows))->anonymizeDocument(
			$this->node(name: 'besluit.docx', content: $docx, mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
			new DocumentAnonymization(entities: self::entities())
		);
		$documentXml = $this->part(bytes: $this->written['besluit_anonymized.docx'], name: 'word/document.xml');
		$this->assertStringContainsString($expected, $documentXml);
		$this->assertStringNotContainsString('Maria Bakker', strip_tags($documentXml));

		// ODT, through the sanitised container.
		$this->written = [];
		$odtPath = $this->container(parts: ['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => '<text:p>'.self::SENTENCE.'</text:p>']);
		$sanitizer = $this->createMock(OfficeDocumentSanitizer::class);
		$sanitizer->method('isSanitizable')->willReturn(true);
		$sanitizer->method('sanitize')->with(42)->willReturn(new SanitizationResult(path: $odtPath, report: new SanitizationReport()));
		$this->fileService(handler: $this->handler(anonymisationRows: $rows, sanitizer: $sanitizer))->anonymizeDocument(
			$this->node(name: 'brief.odt', content: (string) file_get_contents($odtPath), mime: 'application/vnd.oasis.opendocument.text'),
			new DocumentAnonymization(entities: self::entities())
		);
		$contentXml = $this->part(bytes: $this->written['brief_anonymized.odt'], name: 'content.xml');
		$this->assertStringContainsString('<text:p>Brief aan '.$expected.' over rekening [IBAN: 2].</text:p>', $contentXml);
	}//end testEveryOutputModePrintsTheGround()

	/**
	 * A FileService whose document processing handler is the real one under test.
	 *
	 * @param DocumentProcessingHandler $handler The handler.
	 */
	private function fileService(DocumentProcessingHandler $handler): FileService {
		return new FileService(...$this->argumentsFor(class: FileService::class, handler: $handler));
	}//end fileService()

	/**
	 * Constructor arguments: final bundles built, the handler interface given, every other dependency a double.
	 *
	 * @param string                    $class   The class.
	 * @param DocumentProcessingHandler $handler The handler.
	 *
	 * @return array<int, mixed>
	 */
	private function argumentsFor(string $class, DocumentProcessingHandler $handler): array {
		$arguments = [];
		foreach ((new ReflectionMethod($class, '__construct'))->getParameters() as $parameter) {
			$type = ltrim((string) $parameter->getType(), '?');
			if ($type === DocumentProcessingHandlerInterface::class) {
				$arguments[] = $handler;
				continue;
			}

			if (class_exists($type) === true && (new ReflectionClass($type))->isFinal() === true) {
				$arguments[] = new $type(...$this->argumentsFor(class: $type, handler: $handler));
				continue;
			}

			if ($parameter->isDefaultValueAvailable() === true && interface_exists($type) === false && class_exists($type) === false) {
				$arguments[] = $parameter->getDefaultValue();
				continue;
			}

			$arguments[] = $this->createMock($type);
		}

		return $arguments;
	}//end argumentsFor()

	private function container(array $parts): string {
		$path = $this->tempFile(bytes: '');
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		foreach ($parts as $name => $content) {
			$zip->addFromString($name, $content);
		}

		$zip->close();
		return $path;
	}//end container()

	private function part(string $bytes, string $name): string {
		$zip = new ZipArchive();
		$zip->open($this->tempFile(bytes: $bytes));
		$content = (string) $zip->getFromName($name);
		$zip->close();
		return $content;
	}//end part()

	/**
	 * A one-page PDF with a text layer.
	 *
	 * @param string $text The page text.
	 */
	private static function pdf(string $text): string {
		$stream = (string) gzcompress("BT\n/F1 12 Tf\n72 720 Td\n(".$text.") Tj\nET\n", 6);
		$objects = [
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '<< /Type /Pages /Kids [ 3 0 R ] /Count 1 >>',
			3 => '<< /Type /Page /Parent 2 0 R /MediaBox [ 0 0 612 792 ] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
			4 => '<< /Length '.strlen($stream)." /Filter /FlateDecode >>\nstream\n".$stream."\nendstream",
			5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
		];

		$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ($objects as $oid => $body) {
			$offsets[$oid] = strlen($pdf);
			$pdf .= $oid." 0 obj\n".$body."\nendobj\n";
		}

		$xrefStart = strlen($pdf);
		$pdf .= "xref\n0 6\n0000000000 65535 f \n";
		foreach ($offsets as $offset) {
			$pdf .= sprintf("%010d 00000 n \n", $offset);
		}

		return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xrefStart."\n%%EOF\n";
	}//end pdf()
}//end class
