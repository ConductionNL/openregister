<?php

declare(strict_types=1);

/**
 * A schema change from a configuration import is classified, versioned and
 * written to the changelog, like an edit through the schema API (openregister#4102).
 *
 * Before this change only `SchemasController::update()` called the versioning
 * service; an app's register import changed schemas with no classification, no
 * version bump and no changelog entry. The versioning service here is the real
 * one over the real diff service; only its two mappers are doubles.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://www.OpenRegister.nl
 *
 * @spec openspec/specs/schema-migration/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use GuzzleHttp\Client;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaChangelog;
use OCA\OpenRegister\Db\SchemaChangelogMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaRunEntryMapper;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Schema\SchemaDiffService;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * Schema versioning on the import path.
 */
class ImportHandlerSchemaVersioningTest extends TestCase {

	/** @var SchemaMapper&MockObject */
	private SchemaMapper $schemaMapper;

	/** @var SchemaChangelogMapper&MockObject */
	private SchemaChangelogMapper $changelogMapper;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private ImportHandler $handler;

	/** @var array<string, mixed>|null What the import handed updateFromArray(). */
	private ?array $written = null;

	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->changelogMapper = $this->createMock(SchemaChangelogMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->schemaMapper->method('updateFromArray')->willReturnCallback(
			function (int $id, array $object): Schema {
				$this->written = $object;
				return $this->schema(id: $id, version: (string)($object['version'] ?? '1.0.0'), properties: $object['properties'] ?? [], required: $object['required'] ?? []);
			}
		);
		$this->schemaMapper->method('update')->willReturnArgument(0);

		$this->handler = $this->handlerOver(schemaMapper: $this->schemaMapper);
	}//end setUp()

	/**
	 * An import handler over the given schema mapper, with the real versioning service.
	 *
	 * @param SchemaMapper $schemaMapper The schema mapper double.
	 *
	 * @return ImportHandler
	 */
	private function handlerOver(SchemaMapper $schemaMapper): ImportHandler {
		$handler = new ImportHandler(
			schemaMapper: $schemaMapper,
			registerMapper: $this->createMock(RegisterMapper::class),
			objectEntityMapper: $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper: $this->createMock(MappingMapper::class),
			client: $this->createMock(Client::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->logger,
			appDataPath: '/tmp',
			uploadHandler: $this->createMock(UploadHandler::class),
			objectService: $this->createMock(ObjectService::class)
		);

		$handler->setSchemaVersioning(
			new SchemaVersioningService(
				diffService: new SchemaDiffService(),
				changelogMapper: $this->changelogMapper,
				runMapper: $this->createMock(SchemaRunMapper::class),
				runEntryMapper: $this->createMock(SchemaRunEntryMapper::class),
				userSession: $this->createMock(IUserSession::class),
				logger: $this->logger
			)
		);

		return $handler;
	}//end handlerOver()

	/**
	 * A stored schema.
	 *
	 * @param int $id The schema id.
	 * @param string $version The schema version.
	 * @param array<string, mixed> $properties The properties.
	 * @param array<int, string> $required The required property names.
	 *
	 * @return Schema
	 */
	private function schema(int $id, string $version, array $properties, array $required): Schema {
		$schema = new Schema();
		(new ReflectionProperty($schema, 'id'))->setValue($schema, $id);
		$schema->setSlug('case');
		$schema->setTitle('Case');
		$schema->setVersion($version);
		$schema->setProperties($properties);
		$schema->setRequired($required);

		return $schema;
	}//end schema()

	/**
	 * The stored case schema: a title and a required status.
	 *
	 * @return Schema
	 */
	private function storedCase(): Schema {
		$stored = $this->schema(
			id: 12,
			version: '1.0.0',
			properties: ['title' => ['type' => 'string'], 'status' => ['type' => 'string']],
			required: ['status']
		);
		$this->schemaMapper->method('find')->willReturn($stored);

		return $stored;
	}//end storedCase()

	/**
	 * An import that drops a required property is recorded as breaking, bumps the major version, and is logged, not refused.
	 *
	 * @return void
	 */
	public function testAnImportThatDropsARequiredPropertyIsRecordedAsBreaking(): void {
		$this->storedCase();

		$this->changelogMapper->expects($this->once())
			->method('createFromArray')
			->with(
				$this->callback(
					static fn (array $entry): bool => $entry['schemaId'] === 12
						&& $entry['classification'] === 'breaking'
						&& $entry['version'] === '2.0.0'
						&& isset($entry['acknowledgedBy']) === false
				)
			)
			->willReturn(new SchemaChangelog());
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$result = $this->handler->importSchema(
			data: ['slug' => 'case', 'title' => 'Case', 'version' => '1.0.0', 'properties' => ['title' => ['type' => 'string']], 'required' => []],
			slugsAndIdsMap: []
		);

		$this->assertSame('2.0.0', $this->written['version']);
		$this->assertSame(12, $result->getId());
	}//end testAnImportThatDropsARequiredPropertyIsRecordedAsBreaking()

	/**
	 * An import that adds an optional property is compatible: a minor bump and a changelog entry.
	 *
	 * @return void
	 */
	public function testAnImportThatAddsAPropertyIsACompatibleMinorBump(): void {
		$this->storedCase();

		$this->changelogMapper->expects($this->once())
			->method('createFromArray')
			->with($this->callback(static fn (array $entry): bool => $entry['classification'] === 'compatible' && $entry['version'] === '1.1.0'))
			->willReturn(new SchemaChangelog());

		$this->handler->importSchema(
			data: [
				'slug' => 'case',
				'title' => 'Case',
				'version' => '1.0.0',
				'properties' => ['title' => ['type' => 'string'], 'status' => ['type' => 'string'], 'note' => ['type' => 'string']],
				'required' => ['status'],
			],
			slugsAndIdsMap: []
		);

		$this->assertSame('1.1.0', $this->written['version']);
	}//end testAnImportThatAddsAPropertyIsACompatibleMinorBump()

	/**
	 * A newer version the app ships is kept as it is; the change is still classified and recorded.
	 *
	 * @return void
	 */
	public function testAVersionTheAppShipsIsKeptAndTheChangeStillRecorded(): void {
		$this->storedCase();

		$this->changelogMapper->expects($this->once())
			->method('createFromArray')
			->with($this->callback(static fn (array $entry): bool => $entry['classification'] === 'breaking' && $entry['version'] === '1.5.0'))
			->willReturn(new SchemaChangelog());

		$this->handler->importSchema(
			data: ['slug' => 'case', 'title' => 'Case', 'version' => '1.5.0', 'properties' => ['title' => ['type' => 'string']], 'required' => []],
			slugsAndIdsMap: []
		);

		$this->assertSame('1.5.0', $this->written['version']);
	}//end testAVersionTheAppShipsIsKeptAndTheChangeStillRecorded()

	/**
	 * A newer version with the same definition writes no changelog entry.
	 *
	 * @return void
	 */
	public function testANewerVersionWithTheSameDefinitionRecordsNothing(): void {
		$this->storedCase();

		$this->changelogMapper->expects($this->never())->method('createFromArray');

		$this->handler->importSchema(
			data: [
				'slug' => 'case',
				'title' => 'Case, renamed',
				'version' => '1.0.1',
				'properties' => ['title' => ['type' => 'string'], 'status' => ['type' => 'string']],
				'required' => ['status'],
			],
			slugsAndIdsMap: []
		);

		$this->assertSame('1.0.1', $this->written['version']);
	}//end testANewerVersionWithTheSameDefinitionRecordsNothing()

	/**
	 * Pass 2 of a configuration import keeps the version Pass 1 bumped to (#4163).
	 *
	 * importFromJson() imports every schema twice: Pass 1 classifies and bumps,
	 * Pass 2 re-imports the same incoming data with force to resolve references.
	 * By then the stored definition equals the incoming one, nothing is
	 * classified, and the incoming (older) version was written back over the
	 * bump, so the schema and its changelog disagreed. The mapper double here
	 * keeps state between the passes, as the table does.
	 *
	 * @return void
	 */
	public function testPassTwoOfAnImportKeepsTheBumpPassOneRecorded(): void {
		$stored = $this->schema(
			id: 12,
			version: '1.0.0',
			properties: ['title' => ['type' => 'string'], 'status' => ['type' => 'string']],
			required: ['status']
		);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(static function () use (&$stored): Schema {
			return $stored;
		});
		// Pass 2 resolves the schema within the ids Pass 1 left, as the real mapper does.
		$schemaMapper->method('findBySlugInIds')->willReturnCallback(static function () use (&$stored): ?Schema {
			return $stored;
		});
		$schemaMapper->method('updateFromArray')->willReturnCallback(
			function (int $id, array $object) use (&$stored): Schema {
				$stored = $this->schema(id: $id, version: (string)($object['version'] ?? '0.0.0'), properties: $object['properties'] ?? [], required: $object['required'] ?? []);
				return $stored;
			}
		);
		$schemaMapper->method('update')->willReturnArgument(0);
		$handler = $this->handlerOver(schemaMapper: $schemaMapper);

		$this->changelogMapper->expects($this->once())
			->method('createFromArray')
			->with($this->callback(static fn (array $entry): bool => $entry['classification'] === 'breaking' && $entry['version'] === '2.0.0'))
			->willReturn(new SchemaChangelog());

		$incoming = ['slug' => 'case', 'title' => 'Case', 'version' => '1.0.0', 'properties' => ['title' => ['type' => 'string']], 'required' => []];

		// Pass 1, then Pass 2 exactly as importFromJson() calls it.
		$handler->importSchema(data: $incoming, slugsAndIdsMap: []);
		$result = $handler->importSchema(data: $incoming, slugsAndIdsMap: [], force: true, registerSchemaIds: [12]);

		$this->assertSame('2.0.0', $result->getVersion());
		$this->assertSame('2.0.0', $stored->getVersion());
	}//end testPassTwoOfAnImportKeepsTheBumpPassOneRecorded()
}//end class
