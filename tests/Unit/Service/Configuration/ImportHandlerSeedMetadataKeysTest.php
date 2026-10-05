<?php

/**
 * A seed object's top-level uuid and slug are metadata, not data.
 *
 * Measured on lqmov-nc: learniq's example set wrote about 8,000 lines of
 * "[MagicMapper] Discarding 2 properties the schema ... does not declare:
 * uuid, slug" for one import. The importer read both keys, set them as
 * metadata, and then handed them to MagicMapper as data as well. These tests
 * drive the real importSeedData() and hand what it stores to the real
 * MagicMapper discard report, so the defect between the two is visible.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-seed-metadata-keys-are-not-stored-as-data
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use Closure;
use GuzzleHttp\Client;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\ImportHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Configuration
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 */
class ImportHandlerSeedMetadataKeysTest extends TestCase {

	private const SEED_UUID = '5f0c6d0e-2b1a-4c7e-9a43-0d1f6c2b8e11';

	/** @var array<int, ObjectEntity> Every entity handed to the routing mapper's insert(). */
	private array $inserted = [];

	/**
	 * The measured defect: neither key is declared, so neither may reach the
	 * data, while the metadata still carries the seed's values.
	 */
	public function testUndeclaredUuidAndSlugAreNotStoredAsData(): void {
		$schema = $this->makeSchema(['naam' => ['type' => 'string']]);

		$this->importSeed($schema);

		$this->assertCount(1, $this->inserted);
		$entity = $this->inserted[0];
		$this->assertSame(self::SEED_UUID, $entity->getUuid(), 'the seed uuid is the object uuid');
		$this->assertSame('school-de-linde', $entity->getSlug(), 'the seed slug is the object slug');
		// getObject() echoes the uuid back as `id`, which MagicMapper ignores.
		$this->assertSame(['id' => self::SEED_UUID, 'naam' => 'De Linde'], $entity->getObject(), 'only declared data is stored');
	}//end testUndeclaredUuidAndSlugAreNotStoredAsData()

	/**
	 * What the importer stores, handed to MagicMapper's own discard report,
	 * names nothing.
	 */
	public function testTheStoredDataMakesMagicMapperDiscardNothing(): void {
		$schema = $this->makeSchema(['naam' => ['type' => 'string']]);
		$this->importSeed($schema);

		$warnings = $this->discardWarnings(data: $this->inserted[0]->getObject(), schema: $schema);

		$this->assertSame([], $warnings, 'no Discarding warning for a seeded object');
	}//end testTheStoredDataMakesMagicMapperDiscardNothing()

	/**
	 * Control for the instrument: MagicMapper's report does warn when it is
	 * handed the keys, so the empty result above is not a silent report.
	 */
	public function testMagicMapperStillReportsAnUndeclaredKey(): void {
		$schema = $this->makeSchema(['naam' => ['type' => 'string']]);

		$warnings = $this->discardWarnings(
			data: ['naam' => 'De Linde', 'uuid' => self::SEED_UUID, 'slug' => 'school-de-linde'],
			schema: $schema
		);

		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('uuid, slug', $warnings[0]);
	}//end testMagicMapperStillReportsAnUndeclaredKey()

	/**
	 * A schema that declares `slug` keeps it as data; `uuid` still goes.
	 */
	public function testADeclaredSlugStaysInTheData(): void {
		$schema = $this->makeSchema(['naam' => ['type' => 'string'], 'slug' => ['type' => 'string']]);

		$this->importSeed($schema);

		$this->assertSame(
			['id' => self::SEED_UUID, 'slug' => 'school-de-linde', 'naam' => 'De Linde'],
			$this->inserted[0]->getObject()
		);
		$this->assertSame(self::SEED_UUID, $this->inserted[0]->getUuid());
	}//end testADeclaredSlugStaysInTheData()

	/**
	 * The idempotency lookup still uses the seed's top-level uuid.
	 */
	public function testTheLookupStillUsesTheSeedUuid(): void {
		$schema = $this->makeSchema(['naam' => ['type' => 'string']]);

		$lookups = [];
		$this->importSeed($schema, $lookups);

		$this->assertSame([self::SEED_UUID], $lookups);
	}//end testTheLookupStillUsesTheSeedUuid()

	/**
	 * Run the real importSeedData() for one seed object of the given schema.
	 *
	 * @param Schema             $schema  The target schema.
	 * @param array<int, string> $lookups Filled with every identifier find() received.
	 *
	 * @return void
	 */
	private function importSeed(Schema $schema, array &$lookups = []): void {
		$register = new Register();
		$register->setSlug('learniq');
		$register->setSchemas([(int)$schema->getId()]);
		$this->setId($register, 7);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$routing = $this->createMock(MagicMapper::class);
		$routing->method('find')->willReturnCallback(
			static function ($identifier) use (&$lookups) {
				$lookups[] = (string)$identifier;
				throw new DoesNotExistException('not found');
			}
		);
		$routing->method('insert')->willReturnCallback(
			function (ObjectEntity $entity): ObjectEntity {
				$this->inserted[] = $entity;
				$this->setId($entity, 100 + count($this->inserted));
				return $entity;
			}
		);

		$plainMapper = $this->createMock(MagicMapper::class);
		$plainMapper->method('insert')->willReturnArgument(0);

		$appConfig = $this->createMock(IAppConfig::class);
		$handler = new ImportHandler(
			schemaMapper:        $this->createMock(SchemaMapper::class),
			registerMapper:      $registerMapper,
			objectEntityMapper:  $plainMapper,
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper:       $this->createMock(MappingMapper::class),
			client:              $this->createMock(Client::class),
			appConfig:           $appConfig,
			logger:              $this->createMock(LoggerInterface::class),
			appDataPath:         '/tmp',
			uploadHandler:       $this->createMock(UploadHandler::class),
			objectService:       $this->createMock(ObjectService::class)
		);
		$handler->setObjectMapper($routing);

		$ref = new ReflectionClass($handler);
		$map = $ref->getProperty('schemasMap');
		$map->setValue($handler, ['school' => $schema]);

		$configuration = new Configuration();
		$configuration->setRegisters([7]);

		$configData = [
			'info' => ['title' => 'Voorbeeldset'],
			'x-openregister' => [
				'seedData' => [
					'objects' => [
						'school' => [
							['uuid' => self::SEED_UUID, 'slug' => 'school-de-linde', 'naam' => 'De Linde'],
						],
					],
				],
			],
		];

		$result = ['objects' => []];
		$method = $ref->getMethod('importSeedData');
		$method->invokeArgs($handler, [$configData, 'admin', 'learniq', $configuration, &$result]);
	}//end importSeed()

	/**
	 * Hand data to the real MagicMapper::reportDroppedProperties() and return what it warned.
	 *
	 * @param array  $data   The object data as it would reach the table write.
	 * @param Schema $schema The schema written to.
	 *
	 * @return array<int, string> The warning messages.
	 */
	private function discardWarnings(array $data, Schema $schema): array {
		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function ($message) use (&$warnings): void {
				$warnings[] = (string)$message;
			}
		);

		$mapper = (new ReflectionClass(MagicMapper::class))->newInstanceWithoutConstructor();
		Closure::bind(
			function (LoggerInterface $logger): void {
				$this->logger = $logger;
			},
			$mapper,
			MagicMapper::class
		)($logger);

		$report = new \ReflectionMethod(MagicMapper::class, 'reportDroppedProperties');
		$report->invoke($mapper, $data, $schema->getProperties(), $schema);

		return $warnings;
	}//end discardWarnings()

	/**
	 * @param array<string, array> $properties The declared properties.
	 *
	 * @return Schema The schema `school`.
	 */
	private function makeSchema(array $properties): Schema {
		$schema = new Schema();
		$schema->setSlug('school');
		$schema->setTitle('School');
		$schema->setProperties($properties);
		$this->setId($schema, 42);
		return $schema;
	}//end makeSchema()

	private function setId(object $entity, int $id): void {
		$prop = (new ReflectionClass($entity))->getProperty('id');
		$prop->setValue($entity, $id);
	}//end setId()
}//end class
