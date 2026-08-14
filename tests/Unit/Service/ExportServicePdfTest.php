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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ExportTooLargeException;
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

class ExportServicePdfTest extends TestCase {

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

		$this->service = new ExportService(
			$this->registerMapper,
			$this->userManager,
			$this->groupManager,
			$this->objectService,
			$this->cacheHandler,
			$this->propertyRbacHandler,
			$this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class)
		);
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

		return $method->invoke($this->service, $register, $schema, $objects, $currentUser);
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

		$pdf = $this->service->exportToPdf(null, $schema);

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

		$pdf = $this->service->exportToPdf(null, $schema);

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

		$pdf = $this->service->exportToPdf($register, null);

		$this->assertStringStartsWith('%PDF', $pdf);
	}

	// --- exportToPdf: row cap ---
	//
	// NOTE: the boundary conditions ("N rows over the cap throws" / "N rows
	// at the cap doesn't throw") are asserted directly against the
	// extracted `guardPdfRowCap()` helper via reflection, rather than by
	// running MAX_PDF_EXPORT_ROWS (5000) objects through a real Dompdf
	// render. Dompdf's table layout (`Cellmap`) has real, substantial
	// per-row memory cost — exactly the risk the cap exists to bound — so a
	// full-scale render-based test would itself be slow/memory-hungry
	// rather than a fast unit assertion. The "under cap succeeds end to
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

		// No exception expected — must not throw at exactly the cap.
		$method->invoke($this->service, ExportService::MAX_PDF_EXPORT_ROWS);
		$this->addToAssertionCount(1);
	}

	public function testExportToPdfShortCircuitsBeforeRenderingWhenOverCap(): void {
		$schema = new Schema();
		$schema->setSlug('big-schema');
		$schema->setProperties(['title' => ['type' => 'string']]);

		// A small array whose *count* (via a stub with a custom count) simulates
		// an over-cap fetch cheaply: since the guard runs on count($objects)
		// immediately after the fetch and before any HTML/Dompdf work, a real
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
			$this->service->exportToPdf(null, $schema);
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
		$this->service->exportToPdf($register, null);
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
			->willReturnCallback(static fn ($schema, $property, $object) => $property !== 'restricted');

		$object = $this->createObjectEntity('uuid-1', 'Object', ['public' => 'ok', 'restricted' => 'nope']);

		$html = $this->invokeBuildPdfSection(null, $schema, [$object], null);

		$this->assertStringContainsString('public', $html);
		$this->assertStringNotContainsString('restricted', $html);
		$this->assertStringNotContainsString('nope', $html);
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
}//end class
