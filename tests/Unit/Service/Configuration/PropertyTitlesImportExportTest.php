<?php

/**
 * A property's names per language survive an import and an export.
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
 * @spec openspec/changes/modelling-field-names-per-language/specs/schema-property-exploration/spec.md
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
 * A property's `titles` round-trips.
 */
class PropertyTitlesImportExportTest extends TestCase {

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
	 * An app's register file whose `klant` schema names a field in two languages.
	 *
	 * @param array<string, mixed> $schemas The schemas, by slug.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private static function registerFile(array $schemas): array {
		return [
			'appId' => 'service-app',
			'version' => '1.0.0',
			'components' => [
				'schemas' => $schemas,
				'registers' => ['service' => ['slug' => 'service', 'version' => '1.0.0', 'schemas' => array_keys($schemas)]],
			],
		];
	}//end registerFile()

	/**
	 * The `klant` property that carries its names.
	 *
	 * @return array<string, mixed>
	 */
	private static function contractEnd(): array {
		return [
			'type' => 'string',
			'title' => 'Contract end',
			'titles' => ['nl' => 'Einddatum contract', 'en' => 'Contract end date'],
		];
	}//end contractEnd()

	/**
	 * Import keeps `titles`, export writes it, and importing the export keeps it again.
	 */
	public function testTheNamesSurviveImportExportAndReimport(): void {
		$schemas = [
			'klant' => ['slug' => 'klant', 'title' => 'Klant', 'version' => '1.0.0', 'properties' => ['contractEnd' => self::contractEnd()]],
		];

		// 1. Import.
		$this->created = [];
		$result = $this->importer->importFromJson(data: self::registerFile(schemas: $schemas), configuration: new Configuration(), appId: 'service-app', version: '1.0.0');
		$this->assertSame([], $result['failed']['schemas'], 'The import rejected the schema.');
		$imported = $this->created;
		$this->assertSame(self::contractEnd()['titles'], $imported['klant']->getProperties()['contractEnd']['titles'] ?? null, 'The import dropped titles.');

		// 2. Export the register the schema belongs to.
		$register = new Register();
		$register->hydrate(['slug' => 'service', 'title' => 'Service', 'version' => '1.0.0']);
		$register->setId(1);
		$this->registerMapper->method('getSchemasByRegisterId')->willReturn(array_values($imported));
		$this->schemaMapper->method('getIdToSlugMap')->willReturn([$imported['klant']->getId() => 'klant']);
		$this->registerMapper->method('getIdToSlugMap')->willReturn([1 => 'service']);

		$export = $this->exporter->exportConfig($register);
		$exported = $export['components']['schemas']['klant']['properties']['contractEnd'] ?? [];
		$this->assertSame(self::contractEnd()['titles'], $exported['titles'] ?? null, 'The export left titles out.');
		$this->assertSame('Contract end', $exported['title'] ?? null);

		// 3. Import what was exported, as another instance would.
		$this->created = [];
		$result = $this->importer->importFromJson(
			data: self::registerFile(schemas: $export['components']['schemas']),
			configuration: new Configuration(),
			appId: 'service-app',
			version: '1.0.0'
		);
		$this->assertSame([], $result['failed']['schemas']);
		$this->assertSame(
			self::contractEnd()['titles'],
			$this->created['klant']->getProperties()['contractEnd']['titles'] ?? null,
			'titles did not survive the export and a second import.'
		);
	}//end testTheNamesSurviveImportExportAndReimport()
}//end class
