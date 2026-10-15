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
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-draft-lifetime-must-default-to-thirty-days-and-a-schema-must-be-able-to-change-it-or-opt-out
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
use OCA\OpenRegister\Service\Object\DraftStatusPolicy;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Decision 216: `configuration.draftLifetimeDays` round-trips.
 */
class DraftLifetimeImportExportTest extends TestCase {

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
			schemaMapper:        $this->schemaMapper,
			registerMapper:      $this->registerMapper,
			objectEntityMapper:  $objectEntityMapper,
			configurationMapper: $configurationMapper,
			mappingMapper:       $mappingMapper,
			client:              $this->createMock(Client::class),
			appConfig:           $appConfig,
			logger:              $logger,
			appDataPath:         '/tmp',
			uploadHandler:       $this->createMock(UploadHandler::class),
			objectService:       $this->createMock(ObjectService::class)
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
	 * An app's register file: one schema that sets a draft lifetime, one that does not.
	 *
	 * @param array<string, mixed> $ticketConfiguration The ticket schema's configuration block.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function registerFile(array $ticketConfiguration): array {
		return [
			'appId' => 'service-app',
			'version' => '1.0.0',
			'components' => [
				'schemas' => [
					'ticket' => [
						'slug' => 'ticket',
						'title' => 'Ticket',
						'version' => '1.0.0',
						'properties' => ['subject' => ['type' => 'string']],
						'configuration' => $ticketConfiguration,
					],
					'note' => [
						'slug' => 'note',
						'title' => 'Note',
						'version' => '1.0.0',
						'properties' => ['text' => ['type' => 'string']],
					],
					'memo' => [
						'slug' => 'memo',
						'title' => 'Memo',
						'version' => '1.0.0',
						'properties' => ['text' => ['type' => 'string']],
						'configuration' => ['draftLifetimeDays' => false],
					],
				],
				'registers' => [
					'service' => ['slug' => 'service', 'version' => '1.0.0', 'schemas' => ['ticket', 'note', 'memo']],
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
		$result = $this->importer->importFromJson(data: $file, configuration: new Configuration(), appId: 'service-app', version: '1.0.0');
		$this->assertSame([], $result['failed']['schemas'], 'The import rejected a schema.');
		$this->assertArrayHasKey('ticket', $this->created);
		$this->assertArrayHasKey('note', $this->created);

		return $this->created;
	}//end import()

	/**
	 * Import keeps the lifetime, export writes it, and importing the export keeps it again.
	 */
	public function testTheLifetimeSurvivesImportExportAndReimport(): void {
		$policy = new DraftStatusPolicy();

		// 1. Import.
		$imported = $this->import(file: $this->registerFile(ticketConfiguration: ['draftLifetimeDays' => 7, 'draftsListed' => false]));
		$this->assertSame(7, $imported['ticket']->getConfiguration()['draftLifetimeDays'] ?? null, 'The import dropped the draft lifetime.');
		$this->assertFalse($policy->ownDraftsListed(schema: $imported['ticket']), 'The import dropped draftsListed.');
		$this->assertTrue($policy->ownDraftsListed(schema: $imported['note']));
		$this->assertSame(7, $policy->draftLifetimeDays(schema: $imported['ticket']), 'The cleanup would not see the imported lifetime.');
		// Control: a schema that sets none gets the default of 30 days (decision 228).
		$this->assertSame(30, $policy->draftLifetimeDays(schema: $imported['note']));
		// The opt-out survives the import: this schema keeps its drafts.
		$this->assertFalse($imported['memo']->getConfiguration()['draftLifetimeDays'] ?? null, 'The import dropped the opt-out.');
		$this->assertNull($policy->draftLifetimeDays(schema: $imported['memo']));

		// 2. Export the register those schemas belong to.
		$register = new Register();
		$register->hydrate(['slug' => 'service', 'title' => 'Service', 'version' => '1.0.0']);
		$register->setId(1);
		$this->registerMapper->method('getSchemasByRegisterId')->willReturn(array_values($imported));
		$this->schemaMapper->method('getIdToSlugMap')->willReturn(
			[$imported['ticket']->getId() => 'ticket', $imported['note']->getId() => 'note', $imported['memo']->getId() => 'memo']
		);
		$this->registerMapper->method('getIdToSlugMap')->willReturn([1 => 'service']);

		$export = $this->exporter->exportConfig($register);

		$this->assertSame(7, $export['components']['schemas']['ticket']['configuration']['draftLifetimeDays'] ?? null, 'The export left the draft lifetime out.');
		$this->assertArrayNotHasKey('draftLifetimeDays', ($export['components']['schemas']['note']['configuration'] ?? []));
		$this->assertFalse($export['components']['schemas']['memo']['configuration']['draftLifetimeDays'] ?? null, 'The export left the opt-out out, so the next import would remove drafts after 30 days.');

		// 3. Import what was exported, as another instance would.
		$reimported = $this->import(
			file: [
				'appId' => 'service-app',
				'version' => '1.0.0',
				'components' => [
					'schemas' => $export['components']['schemas'],
					'registers' => ['service' => ['slug' => 'service', 'version' => '1.0.0', 'schemas' => ['ticket', 'note', 'memo']]],
				],
			]
		);
		$this->assertSame(7, $policy->draftLifetimeDays(schema: $reimported['ticket']), 'The lifetime did not survive the export and a second import.');
		$this->assertFalse($policy->ownDraftsListed(schema: $reimported['ticket']), 'draftsListed did not survive the export and a second import.');
		$this->assertSame(30, $policy->draftLifetimeDays(schema: $reimported['note']));
		$this->assertNull($policy->draftLifetimeDays(schema: $reimported['memo']), 'The opt-out did not survive the export and a second import.');
	}//end testTheLifetimeSurvivesImportExportAndReimport()

	/**
	 * Control: the same path drops a key nobody declared, so the assertion above can fail.
	 */
	public function testAnUndeclaredKeyIsStillDroppedOnTheSamePath(): void {
		$imported = $this->import(file: $this->registerFile(ticketConfiguration: ['draftLifetimeDays' => 7, 'draftLifetimeWeeks' => 1]));

		$this->assertSame(['draftLifetimeDays' => 7], $imported['ticket']->getConfiguration());
	}//end testAnUndeclaredKeyIsStillDroppedOnTheSamePath()
}//end class
