<?php

/**
 * A schema's draft lifetime survives an import and an export.
 *
 * An unknown schema configuration key is dropped on save without a word, and
 * it has cost this repo whole features before (`exportable`, `importSource`,
 * `uniqueConstraints`). So this test does not trust the entity alone: it runs
 * the real import handler on an app's register file, hands what the handler
 * asks the mapper to create to a real Schema the way the mapper does, runs
 * the real export handler over that schema, and imports the export again.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/modelling-schema-exportable-flag/specs/data-import-export/spec.md#requirement-a-schema-keeps-and-serves-its-exportable-flag
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use GuzzleHttp\Client;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ExportHandler;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An imported register keeps its schemas' exportable flag.
 *
 * @covers \OCA\OpenRegister\Db\Schema
 */
class SchemaExportableImportTest extends TestCase {

	private SchemaMapper&MockObject $schemaMapper;

	private RegisterMapper&MockObject $registerMapper;

	private ImportHandler $importer;

	private ExportHandler $exporter;

	/**
	 * The schemas the import created, hydrated as SchemaMapper::createFromArray() hydrates them, by slug.
	 *
	 * @var array<string, Schema>
	 */
	private array $created = [];

	private int $nextSchemaId = 100;

	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$objectEntityMapper = $this->createMock(MagicMapper::class);
		$configurationMapper = $this->createMock(ConfigurationMapper::class);
		$mappingMapper = $this->createMock(MappingMapper::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$logger = $this->createMock(LoggerInterface::class);

		// A fresh instance: nothing imported before, nothing in the database.
		$appConfig->method('getValueString')->willReturn('');
		$this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
		$this->registerMapper->method('getSlugToIdMap')->willReturn([]);
		$mappingMapper->method('getSlugToIdMap')->willReturn([]);
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('not found'));
		$this->schemaMapper->method('find')->willThrowException(new DoesNotExistException('not found'));
		$this->schemaMapper->method('findBySlugInIds')->willReturn(null);
		$this->schemaMapper->method('findByApplicationAndSlug')->willReturn(null);

		// What the real mapper does with the array: hydrate a Schema from it.
		$this->schemaMapper->method('createFromArray')->willReturnCallback(
			function (array $data): Schema {
				$schema = new Schema();
				$schema->hydrate($data);
				$schema->setId($this->nextSchemaId);
				$this->nextSchemaId++;
				$this->created[(string)$schema->getSlug()] = $schema;

				return $schema;
			}
		);
		$this->schemaMapper->method('updateFromArray')->willReturnCallback(
			function (int $id, array $data): Schema {
				$schema = new Schema();
				$schema->hydrate($data);
				$schema->setId($id);
				$this->created[(string)$schema->getSlug()] = $schema;

				return $schema;
			}
		);
		$this->schemaMapper->method('update')->willReturnArgument(0);

		$this->registerMapper->method('createFromArray')->willReturnCallback(
			static function (array $data): Register {
				$register = new Register();
				$register->hydrate(['slug' => $data['slug'], 'title' => $data['slug'], 'version' => '1.0.0']);
				$register->setId(1);

				return $register;
			}
		);
		$this->registerMapper->method('update')->willReturnArgument(0);

		$this->importer = new ImportHandler(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(
				registers: $this->registerMapper,
				schemas: $this->schemaMapper,
				tables: $objectEntityMapper
			),
			records: new \OCA\OpenRegister\Service\Configuration\ConfigurationImportRecords(
				configurations: $configurationMapper,
				mappings: $mappingMapper,
				appConfig: $appConfig
			),
			schemaSteps: new \OCA\OpenRegister\Service\Configuration\SchemaImportSteps(),
			logger: $logger,
			appDataPath: '/tmp',
			objectService: $this->createMock(ObjectService::class)
		);

		$this->exporter = new ExportHandler(
			$this->schemaMapper,
			$this->registerMapper,
			$objectEntityMapper,
			$configurationMapper,
			$mappingMapper,
			$logger
		);
	}//end setUp()

	/**
	 * An app's register file: a top-level flag, a flag in configuration, and no flag.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function registerFile(): array {
		return [
			'appId' => 'insight-app',
			'version' => '1.0.0',
			'components' => [
				'schemas' => [
					'catalogContract' => [
						'slug' => 'catalogContract',
						'title' => 'Catalog contract',
						'version' => '1.0.0',
						'exportable' => true,
						'properties' => ['name' => ['type' => 'string']],
					],
					'report' => [
						'slug' => 'report',
						'title' => 'Report',
						'version' => '1.0.0',
						'properties' => ['name' => ['type' => 'string']],
						'configuration' => ['exportable' => true],
					],
					'note' => [
						'slug' => 'note',
						'title' => 'Note',
						'version' => '1.0.0',
						'properties' => ['text' => ['type' => 'string']],
					],
				],
				'registers' => [
					'insight' => ['slug' => 'insight', 'version' => '1.0.0', 'schemas' => ['catalogContract', 'report', 'note']],
				],
			],
		];
	}//end registerFile()

	/**
	 * Import a register file and return the schemas it created.
	 *
	 * @param array<string, mixed> $file The payload.
	 *
	 * @return array<string, Schema> By slug.
	 */
	private function import(array $file): array {
		$this->created = [];
		$result = $this->importer->importFromJson(data: $file, configuration: new Configuration(), appId: 'insight-app', version: '1.0.0');
		$this->assertSame([], $result['failed']['schemas'], 'The import rejected a schema.');

		return $this->created;
	}//end import()

	/**
	 * The flag from the top level and from configuration is stored once and served in both places; no flag reads false.
	 */
	public function testTheImportKeepsTheFlagAndServesItInBothPlaces(): void {
		$imported = $this->import(file: $this->registerFile());

		foreach (['catalogContract', 'report'] as $slug) {
			$this->assertTrue($imported[$slug]->getConfiguration()['exportable'] ?? null, $slug . ': the import dropped the flag.');
			$read = $imported[$slug]->jsonSerialize();
			$this->assertTrue($read['exportable'], $slug . ': the read does not serve the top-level flag.');
			$this->assertTrue($read['configuration']['exportable'], $slug . ': the read does not serve configuration.exportable.');
		}

		$this->assertArrayNotHasKey('exportable', ($imported['note']->getConfiguration() ?? []));
		$this->assertFalse($imported['note']->jsonSerialize()['exportable']);
	}//end testTheImportKeepsTheFlagAndServesItInBothPlaces()

	/**
	 * Exporting the register and importing it again keeps the flag.
	 */
	public function testTheFlagSurvivesExportAndReimport(): void {
		$imported = $this->import(file: $this->registerFile());
		$register = new Register();
		$register->hydrate(['slug' => 'insight', 'title' => 'Insight', 'version' => '1.0.0']);
		$register->setId(1);
		$this->registerMapper->method('getSchemasByRegisterId')->willReturn(array_values($imported));
		$idToSlug = [];
		foreach ($imported as $slug => $schema) {
			$idToSlug[$schema->getId()] = $slug;
		}

		$this->schemaMapper->method('getIdToSlugMap')->willReturn($idToSlug);
		$this->registerMapper->method('getIdToSlugMap')->willReturn([1 => 'insight']);

		$export = $this->exporter->exportConfig($register);
		$reimported = $this->import(
			file: [
				'appId' => 'insight-app',
				'version' => '1.0.0',
				'components' => [
					'schemas' => $export['components']['schemas'],
					'registers' => ['insight' => ['slug' => 'insight', 'version' => '1.0.0', 'schemas' => ['catalogContract', 'report', 'note']]],
				],
			]
		);

		$this->assertTrue($reimported['catalogContract']->jsonSerialize()['exportable'], 'The flag did not survive the export and a second import.');
		$this->assertTrue($reimported['report']->jsonSerialize()['exportable']);
		$this->assertFalse($reimported['note']->jsonSerialize()['exportable']);
	}//end testTheFlagSurvivesExportAndReimport()
}//end class
