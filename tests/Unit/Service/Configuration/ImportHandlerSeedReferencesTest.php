<?php

/**
 * Seed objects store the uuid of the object a reference slug names.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

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
use OCA\OpenRegister\Service\Configuration\SeedImport;
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
 * @uses \OCA\OpenRegister\Db\RegisterSchema
 * @uses \OCA\OpenRegister\Db\Register\RegisterSchemaIds
 * @uses \OCA\OpenRegister\Db\SchemaCatalog
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 * @uses \OCA\OpenRegister\Service\Configuration\ConfigurationImportRecords
 * @uses \OCA\OpenRegister\Service\Configuration\SchemaImportSteps
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugClash
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugDecision
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugWrite
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SlugRequest
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\TemplateSource
 * @uses \OCA\OpenRegister\Service\Configuration\SeedReferenceResolver
 *
 * @spec openspec/changes/aggregation-dialect-repair/tasks.md#6-adjacent-repair-a--seed-ref-resolution
 */
final class ImportHandlerSeedReferencesTest extends TestCase {

	private const ANNA = '0b6e3c1a-1111-4a2b-9c3d-000000000001';
	private const STORED = '0b6e3c1a-1111-4a2b-9c3d-0000000000ff';

	/**
	 * The entities inserted, in order.
	 *
	 * @var list<ObjectEntity>
	 */
	private array $inserted = [];

	/**
	 * The warnings logged.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * A reference to a person seeded earlier in the same pass stores that person's uuid;
	 * an unknown slug is kept as written with a warning, and the object is still imported.
	 *
	 * @return void
	 */
	public function testAnInRunSlugResolvesAndAnUnknownOneIsKept(): void {
		$this->importSeed(
			committee: ['slug' => 'board', 'naam' => 'Board', 'chair' => 'anna', 'members' => ['anna', 'zed']]
		);

		self::assertCount(2, $this->inserted);
		$stored = $this->inserted[1]->getObject();
		self::assertSame(self::ANNA, $stored['chair']);
		self::assertSame([self::ANNA, 'zed'], $stored['members']);
		$unresolved = array_values(array_filter($this->warnings, static fn (string $w): bool => str_contains($w, 'did not resolve')));
		self::assertCount(1, $unresolved);
		self::assertStringContainsString('"zed" on property "members" of schema "committee"', $unresolved[0]);
	}//end testAnInRunSlugResolvesAndAnUnknownOneIsKept()

	/**
	 * A slug not seeded in this pass is looked up in the register.
	 *
	 * @return void
	 */
	public function testASlugNotSeededInThisPassIsLookedUpInTheRegister(): void {
		$this->importSeed(committee: ['slug' => 'board', 'naam' => 'Board', 'chair' => 'kees']);

		self::assertSame(self::STORED, $this->inserted[1]->getObject()['chair']);
	}//end testASlugNotSeededInThisPassIsLookedUpInTheRegister()

	/**
	 * A uuid in a reference property is stored as it is.
	 *
	 * @return void
	 */
	public function testAUuidIsStoredAsItIs(): void {
		$this->importSeed(committee: ['slug' => 'board', 'naam' => 'Board', 'chair' => self::STORED]);

		self::assertSame(self::STORED, $this->inserted[1]->getObject()['chair']);
		self::assertSame([], array_values(array_filter($this->warnings, static fn (string $w): bool => str_contains($w, 'did not resolve'))));
	}//end testAUuidIsStoredAsItIs()

	/**
	 * Seed a person and then a committee that references it.
	 *
	 * @param array<string, mixed> $committee The committee seed.
	 *
	 * @return void
	 */
	private function importSeed(array $committee): void {
		$person = $this->schema(id: 41, slug: 'person', properties: ['naam' => ['type' => 'string']]);
		$committeeSchema = $this->schema(
			id: 42,
			slug: 'committee',
			properties: [
				'naam' => ['type' => 'string'],
				'chair' => ['type' => 'string', '$ref' => '#/components/schemas/person'],
				'members' => ['type' => 'array', 'items' => ['$ref' => 'person']],
			]
		);

		$register = new Register();
		$register->setSlug('council');
		$register->setSchemas([41, 42]);
		$this->setId($register, 7);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$routing = $this->createMock(MagicMapper::class);
		$routing->method('find')->willReturnCallback(
			function ($identifier, $register = null, $schema = null): ObjectEntity {
				foreach ($this->inserted as $entity) {
					if ($entity->getUuid() === (string)$identifier) {
						return $entity;
					}
				}

				// The register already holds person "kees".
				if ($identifier === 'kees' && $schema instanceof Schema && $schema->getId() === 41) {
					$kees = new ObjectEntity();
					$kees->setUuid(self::STORED);
					return $kees;
				}

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

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willThrowException(new DoesNotExistException('not found'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function ($message): void {
			$this->warnings[] = (string)$message;
		});

		$handler = new ImportHandler(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(
				registers: $registerMapper,
				schemas: $schemaMapper,
				tables: $this->createMock(MagicMapper::class)
			),
			records: new \OCA\OpenRegister\Service\Configuration\ConfigurationImportRecords(
				configurations: $this->createMock(ConfigurationMapper::class),
				mappings: $this->createMock(MappingMapper::class),
				appConfig: $this->createMock(IAppConfig::class)
			),
			schemaSteps: new \OCA\OpenRegister\Service\Configuration\SchemaImportSteps(),
			logger: $logger,
			appDataPath: '/tmp',
			objectService: $this->createMock(ObjectService::class)
		);
		$handler->setObjectMapper($routing);

		$ref = new ReflectionClass($handler);
		$ref->getProperty('schemasMap')->setValue($handler, ['person' => $person, 'committee' => $committeeSchema]);

		$configuration = new Configuration();
		$configuration->setRegisters([7]);
		$configData = [
			'info' => ['title' => 'Council'],
			'x-openregister' => [
				'seedData' => [
					'objects' => [
						'person' => [['uuid' => self::ANNA, 'slug' => 'anna', 'naam' => 'Anna']],
						'committee' => [$committee],
					],
				],
			],
		];

		$result = ['objects' => []];
		$seed = new SeedImport(configData: $configData, result: $result);
		$seed->appId = 'council';
		$ref->getMethod('importSeedData')->invokeArgs($handler, [$seed, $configuration]);
	}//end importSeed()

	/**
	 * A schema with an id.
	 *
	 * @param int                  $id         The id.
	 * @param string               $slug       The slug.
	 * @param array<string, mixed> $properties The properties.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id, string $slug, array $properties): Schema {
		$schema = new Schema();
		$schema->setSlug($slug);
		$schema->setTitle(ucfirst($slug));
		$schema->setProperties($properties);
		$this->setId($schema, $id);
		return $schema;
	}//end schema()

	/**
	 * Set an entity's id.
	 *
	 * @param object $entity The entity.
	 * @param int    $id     The id.
	 *
	 * @return void
	 */
	private function setId(object $entity, int $id): void {
		(new ReflectionClass($entity))->getProperty('id')->setValue($entity, $id);
	}//end setId()
}//end class
