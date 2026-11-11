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

use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject\BuildsObjectSlugHandler;
use GuzzleHttp\Client;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicDroppedProperties;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\SeedImport;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\ImportHandler
 * @uses \OCA\OpenRegister\Service\Configuration\SeedImport
 * @uses \OCA\OpenRegister\Service\Configuration\SeedDataObject
 * @uses   \OCA\OpenRegister\Service\Configuration\ListedSeed
 * @uses \OCA\OpenRegister\Service\Configuration\SeedReferenceIndex
 * @uses   \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicDroppedProperties
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Configuration
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugBatch
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugClientValue
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugLog
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugScope
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugSource
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugStore
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugWant
 * @uses \OCA\OpenRegister\Service\Configuration\SeedRelatedItems
 * @uses \OCA\OpenRegister\Service\Configuration\ImportDuplicates
 * @uses \OCA\OpenRegister\Service\Configuration\ShippedSchemaBaseline
 * @uses \OCA\OpenRegister\Service\Configuration\AppConfigurationRecord
 * @uses \OCA\OpenRegister\Service\Configuration\ImportedSchemaShape
 * @uses \OCA\OpenRegister\Service\Configuration\RejectedSchemaReport
 * @uses \OCA\OpenRegister\Service\Configuration\ForeignSeedSchema
 * @uses \OCA\OpenRegister\Service\Configuration\ArrayStructure
 */
class ImportHandlerSeedMetadataKeysTest extends TestCase {
	use BuildsObjectSlugHandler;

	private const SEED_UUID = '5f0c6d0e-2b1a-4c7e-9a43-0d1f6c2b8e11';

	/** @var string|null When set, only this slug is held (so its suffixed variant is free). */
	private ?string $onlyHeldSlug = null;

	/** @var list<string> Warnings the slug handler logged. */
	private array $slugWarnings = [];

	/**
	 * How often the plain object mapper was asked to insert.
	 *
	 * @var int
	 */
	private int $plainInserts = 0;

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
		$this->assertStringContainsString('slug', $warnings[0]);
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
	public function testARoutingMapperWritesTheSeedOnce(): void {
		$this->importSeed(schema: $this->makeSchema(['naam' => ['type' => 'string']]));

		$this->assertCount(1, $this->inserted);
		$this->assertSame(0, $this->plainInserts);
	}//end testARoutingMapperWritesTheSeedOnce()

	public function testTheLookupStillUsesTheSeedUuid(): void {
		$schema = $this->makeSchema(['naam' => ['type' => 'string']]);

		$lookups = [];
		$this->importSeed($schema, $lookups);

		$this->assertSame([self::SEED_UUID], $lookups);
	}//end testTheLookupStillUsesTheSeedUuid()

	/**
	 * Review finding save-f5: a seed on a schema with managed slugs goes through
	 * the slug handler, so it cannot take a slug another live object holds.
	 */
	public function testASeedCannotTakeASlugAnotherLiveObjectHolds(): void {
		$schema = $this->makeSchema(['naam' => ['type' => 'string']]);
		$schema->setConfiguration(['objectSlugField' => 'naam']);

		$lookups = [];
		$this->importSeed($schema, $lookups, ['other-uuid']);

		$this->assertCount(1, $this->inserted);
		$this->assertNull($this->inserted[0]->getSlug(), 'the seed is imported without the slug another object holds');
	}//end testASeedCannotTakeASlugAnotherLiveObjectHolds()

	/**
	 * A seed whose slug is free keeps it, managed or not.
	 */
	public function testASeedKeepsAFreeSlugOnAManagedSchema(): void {
		$schema = $this->makeSchema(['naam' => ['type' => 'string']]);
		$schema->setConfiguration(['objectSlugField' => 'naam']);

		$lookups = [];
		$this->importSeed($schema, $lookups, []);

		$this->assertSame('school-de-linde', $this->inserted[0]->getSlug());
	}//end testASeedKeepsAFreeSlugOnAManagedSchema()

	/**
	 * Review finding r2save-f4: a seed that takes the suffixed slug says so.
	 */
	public function testASeedThatTakesTheSuffixedSlugLogsTheCollision(): void {
		$schema = $this->makeSchema(['naam' => ['type' => 'string']]);
		$schema->setConfiguration(['objectSlugField' => 'naam', 'objectSlugSuffix' => true]);
		$this->onlyHeldSlug = 'school-de-linde';

		$lookups = [];
		$this->importSeed($schema, $lookups, ['other-uuid']);

		$this->assertCount(1, $this->inserted);
		$this->assertMatchesRegularExpression('/^school-de-linde-\d{14}$/', (string)$this->inserted[0]->getSlug());
		$this->assertCount(1, $this->slugWarnings);
		$this->assertStringContainsString('other-uuid', $this->slugWarnings[0]);
		$this->assertStringContainsString('got the suffixed slug', $this->slugWarnings[0]);
	}//end testASeedThatTakesTheSuffixedSlugLogsTheCollision()

	/**
	 * Review finding pp-f5: a first import that lost the slug to another object stored the seed
	 * under the suffixed slug; the next import finds it there instead of copying it again.
	 */
	public function testAUuidLessSeedStoredUnderASuffixedSlugIsNotCopiedAgain(): void {
		$schema = $this->makeSchema(['naam' => ['type' => 'string'], 'title' => ['type' => 'string']]);
		$schema->setConfiguration(['objectSlugField' => 'naam', 'objectSlugSuffix' => true]);
		// The configured source (`naam`) decides the slug, not the title (review Robert, 30-10).
		$this->onlyHeldSlug = 'de-linde';

		$lookups = [];
		$this->importSeed($schema, $lookups, ['other-uuid'], ['title' => 'School De Linde', 'naam' => 'De Linde'], 2);

		$this->assertCount(1, $this->inserted, 'the second import finds the suffixed copy');
		$this->assertMatchesRegularExpression('/^de-linde-\d{14}$/', (string)$this->inserted[0]->getSlug());
	}//end testAUuidLessSeedStoredUnderASuffixedSlugIsNotCopiedAgain()

	/**
	 * Review finding r2save-f3: a uuid-less seed is looked up by the slug it was
	 * stored under, so importing it twice yields one object.
	 */
	public function testAUuidLessSeedWithACapitalisedTitleIsImportedOnce(): void {
		$schema = $this->makeSchema(['naam' => ['type' => 'string'], 'title' => ['type' => 'string']]);
		$schema->setConfiguration(['objectSlugField' => 'naam']);

		$lookups = [];
		$this->importSeed($schema, $lookups, [], ['title' => 'School De Linde', 'naam' => 'De Linde'], 2);

		$this->assertCount(1, $this->inserted, 'the second import finds the first object');
		// The configured source (`naam`) decides the slug, not the title (review Robert, 30-10).
		$this->assertSame('de-linde', $this->inserted[0]->getSlug());
		$this->assertSame(['de-linde', 'de-linde'], $lookups, 'the lookup uses the stored form');
	}//end testAUuidLessSeedWithACapitalisedTitleIsImportedOnce()

	/**
	 * Review finding (Robert, 30-10): an explicit seed `slug` is still the client
	 * slug and wins over the configured source, as it does through the API.
	 */
	public function testAnExplicitSeedSlugStillWinsOverTheConfiguredSource(): void {
		$schema = $this->makeSchema(['naam' => ['type' => 'string'], 'title' => ['type' => 'string']]);
		$schema->setConfiguration(['objectSlugField' => 'naam']);

		$lookups = [];
		$this->importSeed($schema, $lookups, [], ['slug' => 'eigen-slug', 'title' => 'School De Linde', 'naam' => 'De Linde']);

		$this->assertSame('eigen-slug', $this->inserted[0]->getSlug());
	}//end testAnExplicitSeedSlugStillWinsOverTheConfiguredSource()

	/**
	 * A real slug handler over a mapper that reports the given holders, or none.
	 *
	 * @param MagicMapper                    $routing        The routing mapper mock.
	 * @param RegisterMapper                 $registerMapper The register mapper.
	 * @param array<int, string>|null        $holders        Holders of any slug; null = no handler.
	 *
	 * @return ObjectSlugHandler|null The handler.
	 */
	private function slugHandlerFor(MagicMapper $routing, RegisterMapper $registerMapper, ?array $holders): ?ObjectSlugHandler {
		if ($holders === null) {
			return null;
		}

		$routing->method('findSlugHolders')->willReturnCallback(
			fn (RegisterSchema $table, string $slug): array => ($this->onlyHeldSlug === null || $this->onlyHeldSlug === $slug) ? $holders : []
		);
		return $this->buildSlugHandler(
			magicMapper: $routing,
			hydrator: new MetadataHydrationHandler(
				logger: $this->createMock(LoggerInterface::class),
				cacheHandler: $this->createMock(CacheHandler::class)
			),
			registerMapper: $registerMapper,
			logger: $this->slugLogger()
		);
	}//end slugHandlerFor()

	/**
	 * A logger that collects the slug warnings in $this->slugWarnings.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function slugLogger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function ($message): void {
				$this->slugWarnings[] = (string)$message;
			}
		);
		return $logger;
	}//end slugLogger()

	/**
	 * Run the real importSeedData() for one seed object of the given schema.
	 *
	 * @param Schema                  $schema      The target schema.
	 * @param array<int, string>      $lookups     Filled with every identifier find() received.
	 * @param array<int, string>|null $slugHolders Live holders of any slug (null = no slug handler wired).
	 * @param array|null              $seed        The seed object (default: a seed with uuid and slug).
	 * @param int                     $runs        How many times the same import runs.
	 *
	 * @return void
	 */
	private function importSeed(Schema $schema, array &$lookups = [], ?array $slugHolders = null, ?array $seed = null, int $runs = 1): void {
		$register = new Register();
		$register->setSlug('learniq');
		$register->setSchemas([(int)$schema->getId()]);
		$this->setId($register, 7);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$routing = $this->createMock(MagicMapper::class);
		$routing->method('find')->willReturnCallback(
			function ($identifier) use (&$lookups) {
				$lookups[] = (string)$identifier;
				// Behave like a table: an earlier insert is found by its uuid or stored slug.
				foreach ($this->inserted as $entity) {
					if ($entity->getUuid() === (string)$identifier || $entity->getSlug() === (string)$identifier) {
						return $entity;
					}
				}

				throw new DoesNotExistException('not found');
			}
		);
		$routing->method('findSlugsByPrefix')->willReturnCallback(
			function (RegisterSchema $table, string $prefix): array {
				[$register, $schema] = [$table->register, $table->schema];
				$matches = [];
				foreach ($this->inserted as $entity) {
					if (str_starts_with((string)$entity->getSlug(), $prefix) === true) {
						$matches[] = ['slug' => (string)$entity->getSlug(), 'uuid' => (string)$entity->getUuid()];
					}
				}

				return $matches;
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
		$plainMapper->method('insert')->willReturnCallback(
			function (ObjectEntity $entity): ObjectEntity {
				$this->plainInserts++;
				return $entity;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$handler = new ImportHandler(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(
				registers: $registerMapper,
				schemas: $this->createMock(SchemaMapper::class),
				tables: $plainMapper
			),
			records: new \OCA\OpenRegister\Service\Configuration\ConfigurationImportRecords(
				configurations: $this->createMock(ConfigurationMapper::class),
				mappings: $this->createMock(MappingMapper::class),
				appConfig: $appConfig
			),
			schemaSteps: new \OCA\OpenRegister\Service\Configuration\SchemaImportSteps(),
			logger: $this->createMock(LoggerInterface::class),
			appDataPath: '/tmp',
			objectService: $this->createMock(ObjectService::class)
		);
		$handler->setObjectMapper($routing);
		$handler->setSlugHandler($this->slugHandlerFor(routing: $routing, registerMapper: $registerMapper, holders: $slugHolders));

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
							($seed ?? ['uuid' => self::SEED_UUID, 'slug' => 'school-de-linde', 'naam' => 'De Linde']),
						],
					],
				],
			],
		];

		$result = ['objects' => []];
		$method = $ref->getMethod('importSeedData');
		for ($run = 0; $run < $runs; $run++) {
			$seed = new SeedImport(configData: $configData, result: $result);
			$seed->appId = 'learniq';
			$method->invokeArgs($handler, [$seed, $configuration]);
		}
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

		(new MagicDroppedProperties($logger))->report($data, $schema);

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
