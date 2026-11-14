<?php

/**
 * AnonymiseSpreadsheetTest
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
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-3.2
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
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\ITempManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use ZipArchive;

/**
 * An XLSX anonymised through `FileService::anonymizeDocument()`: the hidden
 * sheet and the hidden row do not go out, and the run's persisted report says so.
 *
 * @covers \OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler
 * @covers \OCA\OpenRegister\Files\Service\File\OfficeDocumentSanitizer
 * @covers \OCA\OpenRegister\Files\Service\File\Sanitizer\XlsxSanitizer
 * @covers \OCA\OpenRegister\Service\FileService
 * @uses   \OCA\OpenRegister\Files\Db\AnonymisationLog
 * @uses   \OCA\OpenRegister\Files\Service\File\Sanitizer\DocxSanitizer
 * @uses   \OCA\OpenRegister\Files\Service\File\Sanitizer\OdtSanitizer
 * @uses   \OCA\OpenRegister\Files\Service\File\Sanitizer\PptxSanitizer
 * @uses   \OCA\OpenRegister\Files\Service\File\Sanitizer\OoxmlPackage
 * @uses   \OCA\OpenRegister\Files\Service\File\Sanitizer\OoxmlCommonParts
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationReport
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationResult
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationHiddenCounts
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationRevisionCounts
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationStructureCounts
 * @uses   \OCA\OpenRegister\Service\Anonymisation\PipelineSteps
 * @uses   \OCA\OpenRegister\Service\Anonymisation\RedactionIrreversibilityVerifier
 * @uses   \OCA\OpenRegister\Service\Anonymisation\RedactionVerdictStore
 * @uses   \OCA\OpenRegister\Service\Anonymisation\RedactionOutputMode
 * @uses   \OCA\OpenRegister\Service\Anonymisation\RedactionLeakRoute
 * @uses   \OCA\OpenRegister\Service\Anonymisation\RedactionPackageRouteReader
 * @uses   \OCA\OpenRegister\Service\Anonymisation\RedactionRegionCutter
 * @uses   \OCA\OpenRegister\Files\Service\File\DocumentProcessingPlatform
 * @uses   \OCA\OpenRegister\Files\Service\File\DocumentRedactionServices
 * @uses   \OCA\OpenRegister\Files\Service\File\DocumentReplacement
 * @uses   \OCA\OpenRegister\Files\Service\File\PlaceholderIdTranslator
 * @uses   \OCA\OpenRegister\Files\Service\File\PlaceholderLookup
 * @uses   \OCA\OpenRegister\Files\Service\File\PlaceholderText
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationRequest
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\WordReplacementRequest
 * @uses   \OCA\OpenRegister\Service\File\DocumentAnonymization
 */
class AnonymiseSpreadsheetTest extends TestCase {

	private const NS = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

	private const RELS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

	private const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

	/**
	 * Output written per name.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * The log rows inserted.
	 *
	 * @var list<AnonymisationLog>
	 */
	private array $logRows = [];

	/**
	 * Temp files to remove.
	 *
	 * @var list<string>
	 */
	private array $paths = [];

	protected function tearDown(): void {
		foreach ($this->paths as $path) {
			if (file_exists($path) === true) {
				unlink($path);
			}
		}

		parent::tearDown();
	}//end tearDown()

	private function temp(string $suffix = ''): string {
		$path = (string) tempnam(sys_get_temp_dir(), 'or-xlsx-').$suffix;
		$this->paths[] = $path;
		return $path;
	}//end temp()

	/**
	 * A workbook with the visible sheet `Openbaar`, the hidden sheet `berekening-intern`
	 * and a hidden row on the visible sheet naming Piet Pietersen.
	 */
	private function workbook(): string {
		$rel = static fn (string $id, string $type, string $target): string => '<Relationship Id="'.$id.'" Type="'.$type.'" Target="'.$target.'"/>';
		$rels = static fn (string ...$items): string => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.implode('', $items).'</Relationships>';
		$override = static fn (string $part): string => '<Override PartName="/'.$part.'" ContentType="application/xml"/>';
		$parts = [
			'[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/>'
				.$override('xl/workbook.xml').$override('xl/worksheets/sheet1.xml').$override('xl/worksheets/sheet2.xml').'</Types>',
			'_rels/.rels' => $rels($rel('rId1', self::RELS.'/officeDocument', 'xl/workbook.xml')),
			'xl/workbook.xml' => '<?xml version="1.0"?><workbook '.self::NS.'><sheets><sheet name="Openbaar" sheetId="1" r:id="rId1"/>'
				.'<sheet name="berekening-intern" sheetId="2" state="hidden" r:id="rId2"/></sheets></workbook>',
			'xl/_rels/workbook.xml.rels' => $rels(
				$rel('rId1', self::RELS.'/worksheet', 'worksheets/sheet1.xml'),
				$rel('rId2', self::RELS.'/worksheet', 'worksheets/sheet2.xml'),
				$rel('rId3', self::RELS.'/sharedStrings', 'sharedStrings.xml')
			),
			'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet '.self::NS.'><sheetData>'
				.'<row r="1"><c r="A1" t="s"><v>0</v></c></row><row r="2" hidden="1"><c r="A2" t="s"><v>1</v></c></row></sheetData></worksheet>',
			'xl/worksheets/sheet2.xml' => '<?xml version="1.0"?><worksheet '.self::NS.'><sheetData><row r="1"><c r="A1" t="s"><v>2</v></c></row></sheetData></worksheet>',
			'xl/sharedStrings.xml' => '<?xml version="1.0"?><sst '.self::NS.' count="3" uniqueCount="3"><si><t>Aanvraag van Jan Jansen</t></si><si><t>Piet Pietersen</t></si><si><t>interne berekening</t></si></sst>',
		];

		$path = $this->temp(suffix: '.xlsx');
		$zip = new ZipArchive();
		$zip->open($path, (ZipArchive::CREATE | ZipArchive::OVERWRITE));
		foreach ($parts as $name => $bytes) {
			$zip->addFromString($name, $bytes);
		}

		$zip->close();

		return $path;
	}//end workbook()

	/**
	 * The parts of the written copy, by name.
	 *
	 * @return array<string, string>
	 */
	private function writtenParts(string $name): array {
		$path = $this->temp(suffix: '.xlsx');
		file_put_contents($path, $this->written[$name]);
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($path));
		$parts = [];
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$parts[(string) $zip->getNameIndex($index)] = (string) $zip->getFromIndex($index);
		}

		$zip->close();
		return $parts;
	}//end writtenParts()

	public function testAHiddenSheetAndRowDoNotGoOutAndTheRunReportsThem(): void {
		$source = $this->workbook();

		$parent = $this->createMock(Folder::class);
		$parent->method('nodeExists')->willReturnCallback(fn (string $path): bool => array_key_exists($path, $this->written));
		$parent->method('get')->willReturnCallback(
			function (string $path): File {
				$existing = $this->createMock(File::class);
				$existing->method('delete')->willReturnCallback(function () use ($path): void {
					unset($this->written[$path]);
				});
				return $existing;
			}
		);
		$parent->method('newFile')->willReturnCallback(
			function (string $path, $content = null): File {
				$this->written[$path] = is_resource($content) === true ? (string) stream_get_contents($content) : (string) $content;
				$out = $this->createMock(File::class);
				$out->method('getId')->willReturn(77);
				$out->method('getName')->willReturn($path);
				$out->method('getPath')->willReturn('/files/'.$path);
				$out->method('getContent')->willReturnCallback(fn (): string => $this->written[$path]);
				return $out;
			}
		);

		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(42);
		$node->method('getName')->willReturn('begroting.xlsx');
		$node->method('getPath')->willReturn('/files/begroting.xlsx');
		$node->method('getMimeType')->willReturn(self::MIME);
		$node->method('getContent')->willReturnCallback(static fn (): string => (string) file_get_contents($source));
		$node->method('fopen')->willReturnCallback(static fn () => fopen($source, 'r'));
		$node->method('getParent')->willReturn($parent);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->with(42)->willReturn([$node]);
		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturnCallback(fn (): string => $this->temp(suffix: '.xlsx'));

		$mapper = $this->createMock(EntityRelationMapper::class);
		$mapper->method('findSkippedEntityValuesForFile')->willReturn([]);
		$mapper->method('findEntityIdsByValueForFile')->willReturn([]);
		$logMapper = $this->createMock(AnonymisationLogMapper::class);
		$logMapper->method('insert')->willReturnCallback(function (AnonymisationLog $entity): AnonymisationLog {
			$this->logRows[] = $entity;
			return $entity;
		});

		$handler = new DocumentProcessingHandler(
			platform: new DocumentProcessingPlatform(rootFolder: $rootFolder, userSession: $this->createMock(IUserSession::class), l10n: null),
			redaction: new DocumentRedactionServices(
				entityRelationMapper: $mapper,
				sanitizer: new OfficeDocumentSanitizer(rootFolder: $rootFolder, tempManager: $tempManager, logger: $this->createMock(LoggerInterface::class)),
				anonymisationLogMapper: $logMapper
			),
			logger: $this->createMock(LoggerInterface::class)
		);
		$service = $this->getMockBuilder(FileService::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
		(new ReflectionProperty(FileService::class, 'documentProcessingHandler'))->setValue($service, $handler);

		$service->anonymizeDocument(node: $node, entities: [['text' => 'Jan Jansen', 'entityType' => 'PERSON', 'key' => 'p1']]);

		// The copy: no hidden sheet, no value in the hidden row, the visible name replaced.
		$parts = $this->writtenParts(name: 'begroting_anonymized.xlsx');
		$this->assertStringNotContainsString('berekening-intern', $parts['xl/workbook.xml']);
		$this->assertArrayNotHasKey('xl/worksheets/sheet2.xml', $parts);
		$everything = implode("\n", $parts);
		$this->assertStringNotContainsString('Piet Pietersen', $everything);
		$this->assertStringNotContainsString('interne berekening', $everything);
		$this->assertStringNotContainsString('Jan Jansen', $everything);

		// The run's persisted report.
		$this->assertCount(1, $this->logRows);
		$report = $this->logRows[0]->getSanitizationArray();
		$this->assertIsArray($report);
		$this->assertSame(1, $report['hiddenSheetsRemoved']);
		$this->assertSame(1, $report['hiddenRowsCleared']);
		$this->assertSame(['outcome' => 'ran'], $this->logRows[0]->getStepsArray()['xlsx-sanitiser']);

		// And the copy that went out is verified clean.
		$this->assertSame('clean', $service->getLastVerification()['verdict']);
		$this->assertSame('xlsx', $service->getLastVerification()['outputMode']);
	}//end testAHiddenSheetAndRowDoNotGoOutAndTheRunReportsThem()
}//end class
