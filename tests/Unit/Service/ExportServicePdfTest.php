<?php

/**
 * ExportServicePdfTest
 *
 * Unit tests for `ExportService::exportToPdf()` — PDF magic bytes, row-cap
 * guard (single-schema and combined multi-schema), empty result sets, and
 * column-selection/RBAC parity with the section-builder used by the PDF
 * renderer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/export-pdf-format/specs/export-pdf-format/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Service\PropertyAccessRequest;
use OCA\OpenRegister\Service\SchemaProperty;
use OCA\OpenRegister\Service\Export\RowsPdfSection;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ExportTooLargeException;
use OCA\OpenRegister\Exception\OfficeServerRequiredException;
use OCA\OpenRegister\Files\Service\File\Pdf\Fallback\NcOfficeConverterInterface;
use OCA\OpenRegister\Service\Export\DocumentStyleReader;
use OCA\OpenRegister\Service\Export\ExportRequest;
use OCA\OpenRegister\Service\Export\RowsTable;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectExportRequest;

/**
 * @uses \OCA\OpenRegister\Service\Export\Odt\OdtWriter
 * @uses \OCA\OpenRegister\Service\Export\RecordPdfRows
 * @uses \OCA\OpenRegister\Service\Export\Odt\HtmlToOdt
 * @uses \OCA\OpenRegister\Service\Export\Odt\OdtTableBuilder
 * @uses \OCA\OpenRegister\Service\Export\Odt\VisibleText
 * @uses \OCA\OpenRegister\Service\Export\Odt\OdtLogo
 * @uses \OCA\OpenRegister\Service\Export\Odt\OdtFont
 * @uses \OCA\OpenRegister\Service\Export\DocumentStyleReader
 */
class ExportServicePdfTest extends TestCase {

	/**
	 * Office converter double that records the ODT it was given.
	 *
	 * @var NcOfficeConverterInterface
	 */
	private NcOfficeConverterInterface $converter;

	/**
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper $registerMapper;

	/**
	 * @var IUserManager&MockObject
	 */
	private IUserManager $userManager;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * @var CacheHandler&MockObject
	 */
	private CacheHandler $cacheHandler;

	/**
	 * @var PropertyRbacHandler&MockObject
	 */
	private PropertyRbacHandler $propertyRbacHandler;

	private ExportService $service;

	protected function setUp(): void {
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->cacheHandler = $this->createMock(CacheHandler::class);
		$this->propertyRbacHandler = $this->createMock(PropertyRbacHandler::class);

		$this->converter = new class implements NcOfficeConverterInterface {
			public bool $available = true;

			public string $lastOdt = '';

			public function isAvailable(): bool {
				return $this->available;
			}

			public function pdfToOdt(string $pdfBytes): string {
				return '';
			}

			public function odtToPdf(string $odtBytes): string {
				$this->lastOdt = $odtBytes;
				return '%PDF-1.7 converted';
			}
		};

		$this->service = $this->makeService(converter: $this->converter);
	}

	/**
	 * Build the service with the given office converter.
	 */
	private function makeService(?NcOfficeConverterInterface $converter): ExportService {
		return new ExportService(
			$this->registerMapper,
			$this->groupManager,
			$this->objectService,
			$this->cacheHandler,
			$this->propertyRbacHandler,
			$converter
		);
	}

	/**
	 * Text of content.xml in the ODT the converter received.
	 */
	private function convertedOdtText(): string {
		$file = tempnam(sys_get_temp_dir(), 'odt');
		file_put_contents($file, $this->converter->lastOdt);
		$zip = new \ZipArchive();
		$zip->open($file);
		$xml = (string) $zip->getFromName('content.xml');
		$zip->close();
		unlink($file);

		return strip_tags(str_replace('<', ' <', $xml));
	}

	// --- office server ---

	/**
	 * The PDF is the office server's conversion of an ODT holding the rows.
	 */
	public function testRenderRowsToPdfConvertsAnOdtOfTheRows(): void {
		$pdf = $this->service->renderTableToPdf(table: new RowsTable(title: 'Uitkeringen', columns: ['naam' => 'Naam'], rows: [['naam' => 'Łukasz Żółć']]));

		$this->assertSame('%PDF-1.7 converted', $pdf);
		$this->assertStringContainsString('Uitkeringen', $this->convertedOdtText());
		$this->assertStringContainsString('Łukasz Żółć', $this->convertedOdtText());
	}

	/**
	 * Without an office server, PDF export refuses with a clear error before reading anything.
	 */
	public function testWithoutOfficeServerPdfExportIsRefused(): void {
		$this->converter->available = false;
		$this->objectService->expects($this->never())->method('searchObjects');

		$this->assertFalse($this->service->isPdfAvailable());

		$this->expectException(OfficeServerRequiredException::class);
		$this->service->exportToPdf(request: new ObjectExportRequest(schema: new Schema()));
	}

	/**
	 * Without any converter bound, PDF is not available either.
	 */
	public function testWithoutConverterPdfIsNotAvailable(): void {
		$service = $this->makeService(converter: null);

		$this->assertFalse($service->isPdfAvailable());
		$this->expectException(OfficeServerRequiredException::class);
		$service->renderTableToPdf(table: new RowsTable(title: 'T', columns: ['a' => 'A'], rows: []));
	}

	/**
	 * Create a real ObjectEntity instance with the given data.
	 */
	private function createObjectEntity(string $uuid, ?string $name, array $objectData): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setName($name);
		$entity->setObject($objectData);
		return $entity;
	}

	/**
	 * Invoke the private `buildPdfSection()` helper directly so column
	 * selection / RBAC parity and truncation can be asserted on the raw
	 * HTML fragment, without needing to parse rendered PDF bytes.
	 */
	private function invokeBuildPdfSection(
		?Register $register,
		?Schema $schema,
		array $objects,
		?\OCP\IUser $currentUser = null,
	): string {
		$reflection = new ReflectionClass(ExportService::class);
		$method = $reflection->getMethod('buildPdfSection');
		$method->setAccessible(true);

		return $method->invoke($this->service, new ExportRequest(register: $register, schema: $schema, currentUser: $currentUser), $objects);
	}

	// --- exportToPdf: happy path ---

	public function testExportToPdfProducesValidPdfMagicBytes(): void {
		$schema = new Schema();
		$schema->setSlug('test-schema');
		$schema->setProperties([
			'title' => ['type' => 'string'],
		]);

		$object = $this->createObjectEntity('uuid-123', 'Test Object', ['title' => 'Test Object']);

		$this->propertyRbacHandler->method('canReadProperty')->willReturn(true);
		$this->objectService->method('searchObjects')->willReturn([$object]);

		$pdf = $this->service->exportToPdf(request: new ObjectExportRequest(schema: $schema));

		$this->assertIsString($pdf);
		$this->assertStringStartsWith('%PDF', $pdf);
	}

	public function testExportToPdfEmptyResultSetStillYieldsValidPdf(): void {
		$schema = new Schema();
		$schema->setSlug('empty-schema');
		$schema->setProperties([
			'title' => ['type' => 'string'],
		]);

		$this->propertyRbacHandler->method('canReadProperty')->willReturn(true);
		$this->objectService->method('searchObjects')->willReturn([]);

		$pdf = $this->service->exportToPdf(request: new ObjectExportRequest(schema: $schema));

		$this->assertStringStartsWith('%PDF', $pdf);
	}

	public function testExportToPdfWithRegisterRendersOneSectionPerSchema(): void {
		$register = new Register();
		$reflection = new ReflectionClass($register);
		$idProp = $reflection->getProperty('id');
		$idProp->setAccessible(true);
		$idProp->setValue($register, 1);

		$schema1 = new Schema();
		$schema1->setSlug('schema-a');
		$schema1->setProperties(['field1' => ['type' => 'string']]);

		$schema2 = new Schema();
		$schema2->setSlug('schema-b');
		$schema2->setProperties(['field2' => ['type' => 'string']]);

		$this->registerMapper
			->expects($this->once())
			->method('getSchemasByRegisterId')
			->with(1)
			->willReturn([$schema1, $schema2]);

		$this->propertyRbacHandler->method('canReadProperty')->willReturn(true);
		$this->objectService->method('searchObjects')->willReturn([]);

		$pdf = $this->service->exportToPdf(request: new ObjectExportRequest(register: $register));

		$this->assertStringStartsWith('%PDF', $pdf);
	}

	// --- exportToPdf: row cap ---
	//
	// NOTE: the boundary conditions ("N rows over the cap throws" / "N rows
	// at the cap doesn't throw") are asserted directly against the
	// extracted `guardPdfRowCap()` helper via reflection, rather than by
	// building a document of MAX_PDF_EXPORT_ROWS (5000) rows, which would
	// make this a slow test rather than a fast unit assertion. The "under cap succeeds end to
	// end" path is still covered at realistic scale by
	// `testExportToPdfProducesValidPdfMagicBytes()` above, and the
	// over-cap short-circuit (no rendering work happens) is covered by
	// `testExportToPdfShortCircuitsBeforeRenderingWhenOverCap()` below
	// using a cheap mocked object count.

	public function testGuardPdfRowCapThrowsWhenOverCap(): void {
		$method = new ReflectionMethod(ExportService::class, 'guardPdfRowCap');
		$method->setAccessible(true);

		$overCap = ExportService::MAX_PDF_EXPORT_ROWS + 1;

		$this->expectException(ExportTooLargeException::class);

		try {
			$method->invoke($this->service, $overCap);
		} catch (ExportTooLargeException $e) {
			$this->assertSame($overCap, $e->getRowCount());
			$this->assertSame(ExportService::MAX_PDF_EXPORT_ROWS, $e->getMaxRows());
			$this->assertSame(400, ExportTooLargeException::HTTP_STATUS);
			throw $e;
		}
	}

	public function testGuardPdfRowCapAllowsExactlyAtCap(): void {
		$method = new ReflectionMethod(ExportService::class, 'guardPdfRowCap');
		$method->setAccessible(true);

		// The pair is testGuardPdfRowCapThrowsWhenOverCap: one row more is refused.
		$refusedAt = null;
		try {
			$method->invoke($this->service, ExportService::MAX_PDF_EXPORT_ROWS);
		} catch (ExportTooLargeException $e) {
			$refusedAt = $e->getRowCount();
		}

		$this->assertNull($refusedAt, 'An export of exactly MAX_PDF_EXPORT_ROWS rows must not be refused.');
	}

	public function testExportToPdfShortCircuitsBeforeRenderingWhenOverCap(): void {
		$schema = new Schema();
		$schema->setSlug('big-schema');
		$schema->setProperties(['title' => ['type' => 'string']]);

		// A small array whose *count* (via a stub with a custom count) simulates
		// an over-cap fetch cheaply: since the guard runs on count($objects)
		// immediately after the fetch and before any document is built, a real
		// over-cap array of lightweight ObjectEntity references is itself cheap
		// (no rendering happens) — this proves the short-circuit rather than the
		// guard math already covered above.
		$overCap = ExportService::MAX_PDF_EXPORT_ROWS + 1;
		$object = $this->createObjectEntity('uuid-1', 'Object', ['title' => 'x']);
		$objects = array_fill(0, $overCap, $object);

		$this->propertyRbacHandler->method('canReadProperty')->willReturn(true);
		$this->objectService->method('searchObjects')->willReturn($objects);

		$this->expectException(ExportTooLargeException::class);

		try {
			$this->service->exportToPdf(request: new ObjectExportRequest(schema: $schema));
		} catch (ExportTooLargeException $e) {
			$this->assertSame($overCap, $e->getRowCount());
			$this->assertSame(ExportService::MAX_PDF_EXPORT_ROWS, $e->getMaxRows());
			throw $e;
		}
	}

	public function testExportToPdfThrowsWhenCombinedMultiSchemaRowCountExceedsCap(): void {
		$register = new Register();
		$reflection = new ReflectionClass($register);
		$idProp = $reflection->getProperty('id');
		$idProp->setAccessible(true);
		$idProp->setValue($register, 1);

		$schema1 = new Schema();
		$schema1->setSlug('schema-a');
		$schema1->setProperties(['field1' => ['type' => 'string']]);

		$schema2 = new Schema();
		$schema2->setSlug('schema-b');
		$schema2->setProperties(['field2' => ['type' => 'string']]);

		$this->registerMapper
			->method('getSchemasByRegisterId')
			->willReturn([$schema1, $schema2]);

		$half = (int)(ExportService::MAX_PDF_EXPORT_ROWS / 2) + 1;
		$object = $this->createObjectEntity('uuid-1', 'Object', ['field1' => 'x']);
		$objects = array_fill(0, $half, $object);

		// Both schema fetches return `$half` objects each — combined total
		// exceeds MAX_PDF_EXPORT_ROWS even though neither call alone does.
		// The guard runs after both fetches but before any section is built,
		// so this stays cheap (no rendering happens).
		$this->propertyRbacHandler->method('canReadProperty')->willReturn(true);
		$this->objectService->method('searchObjects')->willReturn($objects);

		$this->expectException(ExportTooLargeException::class);
		$this->service->exportToPdf(request: new ObjectExportRequest(register: $register));
	}

	// --- buildPdfSection: column selection / RBAC parity + truncation ---

	public function testBuildPdfSectionOmitsHiddenOnCollectionProperties(): void {
		$schema = new Schema();
		$schema->setSlug('test');
		$schema->setProperties([
			'visible' => ['type' => 'string'],
			'hidden' => ['type' => 'string', 'hideOnCollection' => true],
		]);

		$this->propertyRbacHandler->method('canReadProperty')->willReturn(true);

		$object = $this->createObjectEntity('uuid-1', 'Object', ['visible' => 'shown-value', 'hidden' => 'secret-value']);

		$html = $this->invokeBuildPdfSection(null, $schema, [$object], null);

		$this->assertStringContainsString('visible', $html);
		$this->assertStringContainsString('shown-value', $html);
		$this->assertStringNotContainsString('hidden', $html);
		$this->assertStringNotContainsString('secret-value', $html);
	}

	public function testBuildPdfSectionOmitsPropertyDeniedByRbac(): void {
		$schema = new Schema();
		$schema->setSlug('test');
		$schema->setProperties([
			'public' => ['type' => 'string'],
			'restricted' => ['type' => 'string'],
		]);

		$this->propertyRbacHandler
			->method('canReadProperty')
			->willReturnCallback(static fn (SchemaProperty $read, PropertyAccessRequest $request) => $read->name !== 'restricted');

		$object = $this->createObjectEntity('uuid-1', 'Object', ['public' => 'ok', 'restricted' => 'nope']);

		$html = $this->invokeBuildPdfSection(null, $schema, [$object], null);

		$this->assertStringContainsString('public', $html);
		$this->assertStringNotContainsString('restricted', $html);
		$this->assertStringNotContainsString('nope', $html);
	}

	/**
	 * One record exports with each readable field's label and value, and a field the user may not read stays out.
	 *
	 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-single-record-exports-as-a-pdf
	 */
	public function testARecordExportsItsReadableFieldsAndLeavesOutAHiddenOne(): void {
		$schema = new Schema();
		$schema->setSlug('zaak');
		$schema->setProperties([
			'omschrijving' => ['type' => 'string', 'title' => 'Omschrijving'],
			'status' => ['type' => 'string', 'title' => 'Status'],
			'bsn' => ['type' => 'string', 'title' => 'BSN'],
			'intern' => ['type' => 'string', 'title' => 'Intern', 'visible' => false],
		]);

		$seen = [];
		$this->propertyRbacHandler
			->method('canReadProperty')
			->willReturnCallback(static function (SchemaProperty $read, PropertyAccessRequest $request) use (&$seen): bool {
				$seen[$read->name] = $request->object;
				return $read->name !== 'bsn';
			});

		$object = $this->createObjectEntity('uuid-42', 'Z-2026-0042', ['omschrijving' => 'Bouwvergunning', 'status' => 'open', 'bsn' => '999999990', 'intern' => 'geheim']);

		$pdf = $this->service->recordToPdf(object: $object, schema: $schema, currentUser: null);
		$text = $this->convertedOdtText();

		$this->assertSame('%PDF-1.7 converted', $pdf);
		$this->assertStringContainsString('Z-2026-0042', $text);
		$this->assertStringContainsString('Omschrijving', $text);
		$this->assertStringContainsString('Bouwvergunning', $text);
		$this->assertStringContainsString('Status', $text);
		$this->assertStringContainsString('uuid-42', $text);
		$this->assertStringNotContainsString('BSN', $text);
		$this->assertStringNotContainsString('999999990', $text);
		$this->assertStringNotContainsString('geheim', $text);
		$this->assertSame('999999990', $seen['bsn']['bsn'], 'Conditional rules see the record itself.');
	}

	/**
	 * Without an office server the record export is refused before anything renders.
	 */
	public function testARecordExportWithoutOfficeServerIsRefused(): void {
		$this->converter->available = false;
		$this->propertyRbacHandler->expects($this->never())->method('canReadProperty');

		$this->expectException(OfficeServerRequiredException::class);
		$this->service->recordToPdf(object: $this->createObjectEntity('u', 'n', []), schema: new Schema(), currentUser: null);
	}

	public function testBuildPdfSectionEscapesHtmlInCellValues(): void {
		$schema = new Schema();
		$schema->setSlug('test');
		$schema->setProperties(['title' => ['type' => 'string']]);

		$this->propertyRbacHandler->method('canReadProperty')->willReturn(true);

		$object = $this->createObjectEntity('uuid-1', 'Object', ['title' => '<script>alert(1)</script>']);

		$html = $this->invokeBuildPdfSection(null, $schema, [$object], null);

		$this->assertStringNotContainsString('<script>alert(1)</script>', $html);
		$this->assertStringContainsString('&lt;script&gt;', $html);
	}

	public function testBuildPdfSectionTruncatesLongCellValues(): void {
		$schema = new Schema();
		$schema->setSlug('test');
		$schema->setProperties(['title' => ['type' => 'string']]);

		$this->propertyRbacHandler->method('canReadProperty')->willReturn(true);

		$longValue = str_repeat('a', 500);
		$object = $this->createObjectEntity('uuid-1', 'Object', ['title' => $longValue]);

		$html = $this->invokeBuildPdfSection(null, $schema, [$object], null);

		$this->assertStringNotContainsString($longValue, $html);
		$this->assertStringContainsString(str_repeat('a', 200) . '…', $html);
	}

	public function testBuildPdfSectionIncludesTitleTimestampAndObjectCount(): void {
		$register = new Register();
		$register->setSlug('my-register');
		$register->setTitle('My Register');

		$schema = new Schema();
		$schema->setSlug('my-schema');
		$schema->setTitle('My Schema');
		$schema->setProperties(['title' => ['type' => 'string']]);

		$this->propertyRbacHandler->method('canReadProperty')->willReturn(true);

		$objects = [
			$this->createObjectEntity('uuid-1', 'One', ['title' => 'One']),
			$this->createObjectEntity('uuid-2', 'Two', ['title' => 'Two']),
		];

		$html = $this->invokeBuildPdfSection($register, $schema, $objects, null);

		$this->assertStringContainsString('My Register', $html);
		$this->assertStringContainsString('My Schema', $html);
		$this->assertStringContainsString('Objects: 2', $html);
	}

	/**
	 * A caller that fetched its own rows gets them rendered as a PDF (portaliq#765).
	 */
	public function testRenderRowsToPdfRendersRowsTheCallerFetched(): void {
		$pdf = $this->service->renderTableToPdf(table: new RowsTable(title: 'My statements', columns: ['period' => 'Period', 'amount' => 'Amount'], rows: [['period' => '2026-08', 'amount' => 12.5], ['period' => '2026-09', 'amount' => null]]
		));

		$this->assertStringStartsWith('%PDF-', $pdf);
	}//end testRenderRowsToPdfRendersRowsTheCallerFetched()

	/**
	 * Rows another component built through the table PDF port render as the same table would,
	 * whole cells included, and the row cap still applies.
	 */
	public function testRenderRowsToPdfThroughThePortRendersTheTable(): void {
		$long = str_repeat('lang ', 200);
		$pdf  = $this->service->renderRowsToPdf(title: 'Journal: Zaak', columns: ['message' => 'Note'], rows: [['message' => $long]], wholeCells: true);

		$this->assertSame('%PDF-1.7 converted', $pdf);
		$this->assertStringContainsString('Journal: Zaak', $this->convertedOdtText());
		$this->assertStringContainsString(trim($long), $this->convertedOdtText());

		$this->expectException(ExportTooLargeException::class);
		$this->service->renderRowsToPdf(title: 'T', columns: ['a' => 'A'], rows: array_fill(0, ExportService::MAX_PDF_EXPORT_ROWS + 1, ['a' => 'x']));
	}//end testRenderRowsToPdfThroughThePortRendersTheTable()

	/**
	 * The row cap applies to rows handed in, before anything is rendered.
	 */
	public function testRenderRowsToPdfRefusesMoreRowsThanTheCap(): void {
		$rows = array_fill(0, ExportService::MAX_PDF_EXPORT_ROWS + 1, ['a' => 'x']);

		$this->expectException(ExportTooLargeException::class);

		$this->service->renderTableToPdf(table: new RowsTable(title: 'Too long', columns: ['a' => 'A'], rows: $rows));
	}//end testRenderRowsToPdfRefusesMoreRowsThanTheCap()

	/**
	 * The table holds the labels in column order, one cell per column, escaped,
	 * and a list of column keys doubles as their labels.
	 */
	public function testRowsSectionFollowsTheColumnsAndEscapesEveryCell(): void {
		$html = (new RowsPdfSection())->build(table: new RowsTable(
			title: 'Cases <b>',
			columns: ['status', 'title'],
			rows: [['title' => '<script>x</script>', 'status' => ['open', 'late'], 'extra' => 'not a column'], ['status' => true]]
		));

		$this->assertStringContainsString('<h1>Cases &lt;b&gt;</h1>', $html);
		$this->assertStringContainsString('<th>status</th><th>title</th>', $html);
		$this->assertStringContainsString('<td>open, late</td><td>&lt;script&gt;x&lt;/script&gt;</td>', $html);
		$this->assertStringContainsString('<td>true</td><td></td>', $html);
		$this->assertStringNotContainsString('not a column', $html);
	}//end testRowsSectionFollowsTheColumnsAndEscapesEveryCell()

	// --- house style (export-pdf-house-style) ---

	private function convertedOdtPart(string $part): string {
		$file = tempnam(sys_get_temp_dir(), 'odt');
		file_put_contents($file, $this->converter->lastOdt);
		$zip = new \ZipArchive();
		$zip->open($file);
		$xml = (string) $zip->getFromName($part);
		$zip->close();
		unlink($file);

		return $xml;
	}

	private function serviceWithStyle(?array $profile, ?string $expectedUid): ExportService {
		$reader = $this->createMock(DocumentStyleReader::class);
		$reader->expects($this->once())->method('forUser')->with($expectedUid)->willReturn($profile);

		return new ExportService(
			$this->registerMapper,
			$this->groupManager,
			$this->objectService,
			$this->cacheHandler,
			$this->propertyRbacHandler,
			$this->converter,
			null,
			$reader
		);
	}

	public function testATableExportWearsTheInstanceHouseStyle(): void {
		$service = $this->serviceWithStyle(
			profile: ['colours' => ['primary' => '#00529b'], 'footer' => ['lines' => ['Gemeente Hilversum, Dudokpark 1']]],
			expectedUid: null
		);

		$service->renderTableToPdf(table: new RowsTable(title: 'Vergunningen', columns: ['naam' => 'Naam'], rows: [['naam' => 'A']]));

		$this->assertStringContainsString('Gemeente Hilversum, Dudokpark 1', $this->convertedOdtPart(part: 'styles.xml'));
		$this->assertStringContainsString('fo:background-color="#00529b"', $this->convertedOdtPart(part: 'content.xml'));
	}

	public function testAUserExportReadsThatUsersHouseStyle(): void {
		$schema = new Schema();
		$schema->setSlug('vergunning');
		$schema->setProperties(['title' => ['type' => 'string']]);
		$this->propertyRbacHandler->method('canReadProperty')->willReturn(true);
		$this->objectService->method('searchObjects')->willReturn([$this->createObjectEntity('uuid-1', 'V1', ['title' => 'V1'])]);
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('behandelaar');

		$service = $this->serviceWithStyle(profile: ['footer' => ['privacyUrl' => 'https://hilversum.nl/privacy']], expectedUid: 'behandelaar');
		$service->pdfFor(request: new ExportRequest(schema: $schema, currentUser: $user));

		$this->assertStringContainsString('https://hilversum.nl/privacy', $this->convertedOdtPart(part: 'styles.xml'));
	}

	public function testTheProfilesLogoIsEmbeddedInThePageHeader(): void {
		require_once __DIR__ . '/Export/Odt/OdtLogoTest.php';
		$png = \OCA\OpenRegister\Tests\Unit\Service\Export\Odt\OdtLogoTest::png(width: 400, height: 100);
		$profile = ['logo' => ['url' => '/apps/thematiq/document-style/asset/logo', 'mime' => 'image/png'], 'footer' => ['lines' => ['Gemeente Hilversum']]];
		$reader = $this->createMock(DocumentStyleReader::class);
		$reader->expects($this->once())->method('forUser')->with(null)->willReturn($profile);
		$reader->expects($this->once())->method('logo')->with($profile)
			->willReturn(\OCA\OpenRegister\Service\Export\Odt\OdtLogo::fromBytes(bytes: $png));
		$service = new ExportService(
			$this->registerMapper,
			$this->groupManager,
			$this->objectService,
			$this->cacheHandler,
			$this->propertyRbacHandler,
			$this->converter,
			null,
			$reader
		);

		$service->renderTableToPdf(table: new RowsTable(title: 'Vergunningen', columns: ['naam' => 'Naam'], rows: [['naam' => 'A']]));

		$this->assertSame($png, $this->convertedOdtPart(part: 'Pictures/logo.png'));
		$styles = $this->convertedOdtPart(part: 'styles.xml');
		$this->assertStringContainsString('<draw:image xlink:href="Pictures/logo.png"', $styles);
		$this->assertStringContainsString('Gemeente Hilversum', $styles);
	}

	/**
	 * The profile's fonts are embedded per role; a font that fails to load (WOFF2)
	 * leaves its role on the default font and the export still succeeds
	 * (export-pdf-house-style, task 2.2).
	 */
	public function testTheProfilesFontsAreEmbeddedAndAFontThatFailsToLoadFallsBack(): void {
		require_once __DIR__ . '/Export/Stubs/ThematiqDocumentStyleService.php';
		require_once __DIR__ . '/Export/Stubs/ThematiqFontService.php';
		require_once __DIR__ . '/Export/Odt/OdtFontTest.php';
		$ttf = \OCA\OpenRegister\Tests\Unit\Service\Export\Odt\OdtFontTest::sfnt();
		$profile = ['fonts' => [
			'heading' => ['family' => 'Gemeente Kop', 'url' => '/apps/thematiq/fonts/custom-kop.woff2'],
			'body' => ['family' => 'Gemeente Tekst', 'url' => '/apps/thematiq/fonts/custom-tekst.woff2'],
		]];
		$styleService = new class($profile) {

			public function __construct(private array $profile) {
			}

			public function forUser(?string $uid): array {
				return $this->profile;
			}
		};
		$fontService = new class(['custom-kop' => 'wOF2' . substr($ttf, 4), 'custom-tekst' => $ttf]) {

			public function __construct(private array $stored) {
			}

			public function readFontBytes(string $id): ?string {
				return ($this->stored[$id] ?? null);
			}
		};
		$appManager = $this->createMock(\OCP\App\IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(true);
		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): ?object => match ($id) {
			DocumentStyleReader::STYLE_SERVICE => $styleService,
			DocumentStyleReader::FONT_SERVICE => $fontService,
			default => null,
		});
		$service = new ExportService(
			$this->registerMapper,
			$this->groupManager,
			$this->objectService,
			$this->cacheHandler,
			$this->propertyRbacHandler,
			$this->converter,
			null,
			new DocumentStyleReader($appManager, $container, new \Psr\Log\NullLogger())
		);

		$pdf = $service->renderTableToPdf(table: new RowsTable(title: 'Vergunningen', columns: ['naam' => 'Naam'], rows: [['naam' => 'A']]));

		$this->assertSame('%PDF-1.7 converted', $pdf);
		$this->assertSame($ttf, $this->convertedOdtPart(part: 'Fonts/body.ttf'));
		$this->assertSame('', $this->convertedOdtPart(part: 'Fonts/heading.ttf'));
		$content = $this->convertedOdtPart(part: 'content.xml');
		$this->assertStringContainsString('<style:font-face style:name="OR_BodyFont" svg:font-family="\'Gemeente Tekst\'">', $content);
		$this->assertStringNotContainsString('OR_HeadingFont', $content);
		$this->assertStringContainsString('<style:style style:name="OR_H1" style:family="paragraph"><style:paragraph-properties fo:margin-bottom="0.15cm"/><style:text-properties style:font-name="Liberation Sans"', $content);
		$this->assertStringContainsString('<style:style style:name="OR_Cell" style:family="paragraph"><style:paragraph-properties/><style:text-properties style:font-name="OR_BodyFont"', $content);
	}

	public function testWithoutAProfileTheExportKeepsThePlainLayout(): void {
		$service = $this->serviceWithStyle(profile: null, expectedUid: null);

		$this->assertSame('%PDF-1.7 converted', $service->renderTableToPdf(table: new RowsTable(title: 'T', columns: ['a' => 'A'], rows: [['a' => 'x']])));
		$this->assertStringNotContainsString('OR_FooterLine', $this->convertedOdtPart(part: 'styles.xml'));
		$this->assertStringContainsString('fo:background-color="#2c3e50"', $this->convertedOdtPart(part: 'content.xml'));
	}

	/**
	 * The PDF report summarises the objects by status with counts and
	 * percentages, and names the applied filters (rapportage-bi-export).
	 */
	public function testBuildPdfSectionSummarisesTheStatusAndTheFilters(): void {
		$schema = new Schema();
		$schema->setSlug('vergunningen');
		$schema->setProperties(['title' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['open', 'verleend']]]);
		$this->propertyRbacHandler->method('canReadProperty')->willReturn(true);
		$objects = [];
		foreach (['open', 'verleend', 'verleend', 'verleend', null] as $i => $status) {
			$objects[] = $this->createObjectEntity('uuid-' . $i, 'V' . $i, ['title' => 'V' . $i, 'status' => $status]);
		}

		$method = (new ReflectionClass(ExportService::class))->getMethod('buildPdfSection');
		$method->setAccessible(true);
		$html = $method->invoke(
			$this->service,
			new ExportRequest(schema: $schema, filters: ['status!' => 'afgehandeld', '_limit' => 50, 'zone' => ['a']]),
			$objects
		);

		$this->assertStringContainsString('<p class="meta">Filters: status! = afgehandeld</p>', $html);
		$this->assertStringContainsString('<tr><td>verleend</td><td>3</td><td>60.0%</td></tr>', $html);
		$this->assertStringContainsString('<tr><td>open</td><td>1</td><td>20.0%</td></tr>', $html);
		$this->assertStringContainsString('<tr><td>(none)</td><td>1</td><td>20.0%</td></tr>', $html);
		$this->assertStringContainsString('<tr><td>Total</td><td>5</td><td>100.0%</td></tr>', $html);
		$this->assertLessThan(strpos($html, '<table><thead>'), strpos($html, '<table class="summary">'));
	}

	/**
	 * A status the caller may not read is not summarised, and without a status
	 * column or filters there is no summary block at all.
	 */
	public function testBuildPdfSectionDoesNotSummariseAnUnreadableStatus(): void {
		$schema = new Schema();
		$schema->setSlug('vergunningen');
		$schema->setProperties(['title' => ['type' => 'string'], 'status' => ['type' => 'string']]);
		$this->propertyRbacHandler
			->method('canReadProperty')
			->willReturnCallback(static fn (SchemaProperty $read, PropertyAccessRequest $request) => $read->name !== 'status');

		$html = $this->invokeBuildPdfSection(null, $schema, [$this->createObjectEntity('uuid-1', 'V', ['title' => 'V', 'status' => 'geheim'])]);

		$this->assertStringNotContainsString('class="summary"', $html);
		$this->assertStringNotContainsString('geheim', $html);
		$this->assertStringNotContainsString('Filters:', $html);
	}
}//end class
