<?php

/**
 * SchemaImportService: snapshot versions read from (or defaulted for) the resource
 * root, registering a dialect importer, snapshot info, and a preview whose recorded
 * baseline is not a property map.
 *
 * SchemaImportServiceTest covers discovery, dialect detection and the merge preview
 * against the bundled snapshots.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\SchemaImport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\SchemaImport;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\SchemaImport\SourceUpdate;
use OCA\OpenRegister\Service\SchemaImport\BundledImporters;
use OCA\OpenRegister\Service\SchemaImport\DialectDetector;
use OCA\OpenRegister\Service\SchemaImport\ImportedSchema;
use OCA\OpenRegister\Service\SchemaImport\ImportedSchemaDefinition;
use OCA\OpenRegister\Service\SchemaImport\ImportedSchemaHeading;
use OCA\OpenRegister\Service\SchemaImport\ImportOptions;
use OCA\OpenRegister\Service\SchemaImport\MergeInstruction;
use OCA\OpenRegister\Service\SchemaImport\SchemaDialectImporter;
use OCA\OpenRegister\Service\SchemaImport\SchemaImportService;
use OCA\OpenRegister\Service\SchemaImport\ThreeWayMerge;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\SchemaImport\SchemaImportService
 */
class SchemaImportServiceRegistryTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/or-schema-import-' . bin2hex(random_bytes(4));
		mkdir($this->root . '/schemaorg', 0777, true);
		mkdir($this->root . '/ggm', 0777, true);
	}

	protected function tearDown(): void {
		foreach (['schemaorg/version.json', 'ggm/version.json'] as $file) {
			if (is_file($this->root . '/' . $file) === true) {
				unlink($this->root . '/' . $file);
			}
		}

		rmdir($this->root . '/schemaorg');
		rmdir($this->root . '/ggm');
		rmdir($this->root);
		parent::tearDown();
	}

	private function service(): SchemaImportService {
		$service = new SchemaImportService(new DialectDetector(), new ThreeWayMerge());
		foreach (BundledImporters::at($this->root) as $importer) {
			$service->register($importer);
		}

		return $service;
	}

	public function testAMissingVersionFileFallsBackToUnknown(): void {
		$this->assertSame(['dialect' => 'schema.org', 'snapshotVersion' => 'schemaorg-unknown'], $this->service()->snapshotInfo('schema.org'));
	}

	public function testAVersionFileWithoutAStringVersionFallsBackToUnknown(): void {
		file_put_contents($this->root . '/schemaorg/version.json', json_encode(['version' => 3]));

		$this->assertSame('schemaorg-unknown', $this->service()->snapshotInfo('schema.org')['snapshotVersion']);
	}

	public function testAVersionFileNamesTheSnapshot(): void {
		file_put_contents($this->root . '/schemaorg/version.json', json_encode(['version' => 'schemaorg-29.0']));

		$this->assertSame('schemaorg-29.0', $this->service()->snapshotInfo('schema.org')['snapshotVersion']);
	}

	public function testARegisteredImporterAnswersForItsDialectAndReplacesASameNamedOne(): void {
		$service = $this->service();
		$importer = $this->importer('schema.org', 'custom-1');
		$service->register($importer);

		$this->assertSame(['schema.org', 'ggm'], $service->importableDialects());
		$this->assertSame($importer, $service->importerFor('schema.org'));
		$this->assertSame('custom-1', $service->snapshotInfo('schema.org')['snapshotVersion']);
	}

	public function testAPreviewWithANonMapBaselineMergesAgainstNoBaseline(): void {
		$service = $this->service();
		$service->register($this->importer('fake', 'v1'));

		$preview = $service->previewUpdateFromSource(new SourceUpdate(['dialect' => 'fake', 'reference' => 'Thing', 'baseline' => 'corrupt'], ['name' => ['type' => 'string']]));

		$this->assertSame(['dialect' => 'fake', 'reference' => 'Thing'], $preview['incomingSource']);
		$this->assertSame(['@type' => 'Thing'], $preview['jsonld']);
		$this->assertSame($preview, $preview + (new ThreeWayMerge())->compute(new MergeInstruction([], ['name' => ['type' => 'string']], ['name' => ['type' => 'string'], 'url' => ['type' => 'string']])));
	}

	private function importer(string $dialect, string $version): SchemaDialectImporter {
		$importer = $this->createMock(SchemaDialectImporter::class);
		$importer->method('dialect')->willReturn($dialect);
		$importer->method('snapshotVersion')->willReturn($version);
		$importer->method('import')->willReturn(new ImportedSchema(
			new ImportedSchemaHeading('Thing', ''),
			new ImportedSchemaDefinition(['name' => ['type' => 'string'], 'url' => ['type' => 'string']], ['@type' => 'Thing']),
			['dialect' => $dialect, 'reference' => 'Thing']
		));
		return $importer;
	}
}
