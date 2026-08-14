<?php

/**
 * Unit tests for SchemaImportService — registry, dialect resolution, discovery,
 * and update-from-source orchestration over the bundled snapshots. Pure — no
 * Nextcloud/DB dependency.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\SchemaImport
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\SchemaImport;

use OCA\OpenRegister\Exception\SchemaImportException;
use OCA\OpenRegister\Service\SchemaImport\DialectDetector;
use OCA\OpenRegister\Service\SchemaImport\ImportOptions;
use OCA\OpenRegister\Service\SchemaImport\SchemaImportService;
use OCA\OpenRegister\Service\SchemaImport\ThreeWayMerge;
use PHPUnit\Framework\TestCase;

class SchemaImportServiceTest extends TestCase {
	private SchemaImportService $service;

	protected function setUp(): void {
		$resourceRoot = dirname(__DIR__, 4) . '/lib/Resources';
		$this->service = new SchemaImportService(
			new DialectDetector(),
			new ThreeWayMerge(),
			$resourceRoot
		);
	}

	public function testRegistersBundledDialects(): void {
		$dialects = $this->service->importableDialects();
		$this->assertContains('schema.org', $dialects);
		$this->assertContains('ggm', $dialects);
	}

	public function testDiscoverSchemaOrg(): void {
		$out = $this->service->discover('schema.org', 'person');
		$this->assertSame('schema.org', $out['dialect']);
		$this->assertNotEmpty($out['snapshotVersion']);
		$this->assertNotEmpty($out['results']);
	}

	public function testDiscoverGgm(): void {
		$out = $this->service->discover('ggm', 'zaak');
		$this->assertNotEmpty($out['results']);
		$this->assertSame('ZAAK', $out['results'][0]['id']);
	}

	public function testUnknownDialectThrows404(): void {
		$this->expectException(SchemaImportException::class);
		$this->service->discover('dcat', 'x');
	}

	public function testResolveUploadDialectExplicitWins(): void {
		// A doc that would detect as json-schema, but explicitly schema.org.
		$doc = ['$schema' => 'x', 'type' => 'object'];
		$this->assertSame('schema.org', $this->service->resolveUploadDialect($doc, 'schema.org'));
	}

	public function testResolveUploadDialectDetects(): void {
		$doc = ['openapi' => '3.1.0', 'components' => []];
		$this->assertSame('openapi', $this->service->resolveUploadDialect($doc, null));
	}

	public function testResolveUploadDialectUndetectableThrows422(): void {
		$this->expectException(SchemaImportException::class);
		try {
			$this->service->resolveUploadDialect(['foo' => 'bar'], null);
		} catch (SchemaImportException $e) {
			$this->assertSame(422, $e->getHttpStatus());
			throw $e;
		}
	}

	public function testResolveUploadDialectUnsupportedExplicitThrows422(): void {
		$this->expectException(SchemaImportException::class);
		try {
			$this->service->resolveUploadDialect(['type' => 'object'], 'rdf');
		} catch (SchemaImportException $e) {
			$this->assertSame(422, $e->getHttpStatus());
			throw $e;
		}
	}

	public function testPreviewUpdateFromSourcePreviewsAddedProperty(): void {
		// Import Person with a subset, then simulate a local schema that lacks
		// a property the (full) source has → update-from-source should add it.
		$imported = $this->service->import('schema.org', 'Person', new ImportOptions(propertySubset: ['email']));

		$diff = $this->service->previewUpdateFromSource(
			importSource: $imported->importSource,
			currentProperties: $imported->properties
		);

		// The full Person import has more direct properties than the subset.
		$this->assertNotEmpty($diff['added']);
		$this->assertContains('givenName', $diff['added']);
		$this->assertTrue($diff['applied']);
	}

	public function testPreviewUpdateFromSourceKeepsLocalAddition(): void {
		$imported = $this->service->import('schema.org', 'Person', new ImportOptions(propertySubset: ['email']));

		$current = $imported->properties;
		$current['internalNote'] = ['type' => 'string'];

		$diff = $this->service->previewUpdateFromSource(
			importSource: $imported->importSource,
			currentProperties: $current
		);

		$this->assertContains('internalNote', $diff['keptLocal']);
		$this->assertArrayHasKey('internalNote', $diff['merged']);
	}

	public function testPreviewWithoutSourceThrows(): void {
		$this->expectException(SchemaImportException::class);
		$this->service->previewUpdateFromSource(['dialect' => '', 'reference' => ''], []);
	}

	public function testGgmUploadImport(): void {
		$normalised = json_decode(
			(string)file_get_contents(dirname(__DIR__, 4) . '/lib/Resources/ggm/ggm-snapshot.json'),
			true
		);

		$imported = $this->service->importGgmUpload($normalised, 'ZAAK', new ImportOptions());
		$this->assertSame('Zaak', $imported->title);
		$this->assertSame('upload', $imported->importSource['source']);
	}
}
