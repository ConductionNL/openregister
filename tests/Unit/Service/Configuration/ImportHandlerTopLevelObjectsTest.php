<?php

/**
 * Seed objects listed in a configuration's top-level `objects` array import like `components.objects`.
 *
 * An app that authored its seed objects at the top level (78 in one app's
 * register JSON) had its schemas imported and 0 objects: the object loop read
 * `components.objects` only. Both locations now feed the same loop, merged and
 * de-duplicated by `@self` identity.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/config-import-seed-objects
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
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Configuration\ConfigurationImportRecords;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\SchemaImportSteps;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The top-level `objects` array as a seed-object source of importFromJson().
 */
class ImportHandlerTopLevelObjectsTest extends TestCase {

	private SchemaMapper&MockObject $schemaMapper;

	private RegisterMapper&MockObject $registerMapper;

	private ObjectService&MockObject $objectService;

	private ImportHandler $handler;

	/**
	 * The objects passed to saveObject(), in order.
	 *
	 * @var list<array{object: array<string, mixed>, uuid: string|null}>
	 */
	private array $saved = [];

	/**
	 * The stored objects searchObjects() finds, by slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * Slugs whose save fails.
	 *
	 * @var list<string>
	 */
	private array $failing = [];

	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$mappingMapper = $this->createMock(MappingMapper::class);
		$appConfig = $this->createMock(IAppConfig::class);

		// No previously-imported version and no remembered seed: nothing is skipped on that.
		$appConfig->method('getValueString')->willReturn('');
		$this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
		$this->registerMapper->method('getSlugToIdMap')->willReturn([]);
		$mappingMapper->method('getSlugToIdMap')->willReturn([]);

		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('not found'));
		$this->registerMapper->method('createFromArray')->willReturnCallback(fn (array $d) => $this->makeRegister(1, $d['slug']));
		$this->registerMapper->method('update')->willReturnArgument(0);
		$this->schemaMapper->method('createFromArray')->willReturnCallback(fn (array $d) => $this->makeSchema(10, $d['slug']));
		$this->schemaMapper->method('update')->willReturnArgument(0);

		$this->objectService->method('searchObjects')->willReturnCallback(
			function (array $query) {
				$slug = $query['@self']['slug'] ?? '';
				if (isset($this->stored[$slug]) === true) {
					return [$this->stored[$slug]];
				}

				return [];
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions()) {
				$slug = $object['@self']['slug'] ?? '';
				if (in_array($slug, $this->failing, true) === true) {
					throw new RuntimeException('name is required');
				}

				$this->saved[] = ['object' => $object, 'uuid' => $options->uuid];
				$entity = new ObjectEntity();
				$entity->setUuid($options->uuid ?? 'uuid-' . $slug);
				return $entity;
			}
		);

		$this->handler = new ImportHandler(
			catalog: new SchemaCatalog(
				registers: $this->registerMapper,
				schemas: $this->schemaMapper,
				tables: $this->createMock(MagicMapper::class)
			),
			records: new ConfigurationImportRecords(
				configurations: $this->createMock(ConfigurationMapper::class),
				mappings: $mappingMapper,
				appConfig: $appConfig
			),
			schemaSteps: new SchemaImportSteps(),
			logger: $this->createMock(LoggerInterface::class),
			appDataPath: '/tmp',
			objectService: $this->objectService
		);
	}//end setUp()

	/**
	 * Build a hydrated Register entity with a given id + slug.
	 */
	private function makeRegister(int $id, string $slug): Register {
		$register = new Register();
		$register->hydrate(['slug' => $slug, 'version' => '1.0.0']);
		$register->setId($id);
		return $register;
	}//end makeRegister()

	/**
	 * Build a hydrated Schema entity with a given id + slug.
	 */
	private function makeSchema(int $id, string $slug): Schema {
		$schema = new Schema();
		$schema->hydrate(['slug' => $slug, 'title' => $slug, 'version' => '1.0.0', 'properties' => ['title' => ['type' => 'string']]]);
		$schema->setId($id);
		return $schema;
	}//end makeSchema()

	/**
	 * A seed object for the `line` schema of the `sales` register.
	 *
	 * @param string               $slug  The object slug.
	 * @param array<string, mixed> $extra Extra `@self` keys.
	 *
	 * @return array<string, mixed>
	 */
	private static function seed(string $slug, array $extra = []): array {
		return [
			'@self' => array_merge(['register' => 'sales', 'schema' => 'line', 'slug' => $slug, 'version' => '1.0.0'], $extra),
			'title' => 'Line ' . $slug,
		];
	}//end seed()

	/**
	 * Run a forced import of a configuration with the given seed lists.
	 *
	 * @param list<array>|null $topLevel   The top-level `objects`, or null to leave the key out.
	 * @param list<array>|null $components The `components.objects`, or null to leave the key out.
	 *
	 * @return array The import result.
	 */
	private function import(?array $topLevel, ?array $components = null): array {
		$data = [
			'appId' => 'seedapp',
			'version' => '1.0.0',
			'components' => [
				'schemas' => ['line' => ['slug' => 'line', 'title' => 'Line', 'properties' => ['title' => ['type' => 'string']]]],
				'registers' => ['sales' => ['slug' => 'sales', 'version' => '1.0.0', 'schemas' => ['line']]],
			],
		];
		if ($components !== null) {
			$data['components']['objects'] = $components;
		}

		if ($topLevel !== null) {
			$data['objects'] = $topLevel;
		}

		return $this->handler->importFromJson(data: $data, configuration: new Configuration(), appId: 'seedapp', version: '1.0.0', force: true);
	}//end import()

	/**
	 * The slugs saved, in order.
	 *
	 * @return list<string>
	 */
	private function savedSlugs(): array {
		return array_map(static fn (array $save): string => $save['object']['@self']['slug'], $this->saved);
	}//end savedSlugs()

	/**
	 * Top-level seed objects are saved and reported, and the schemas and registers still import.
	 *
	 * @return void
	 */
	public function testTopLevelSeedObjectsMaterialise(): void {
		$result = $this->import([self::seed('l1'), self::seed('l2')]);

		$this->assertSame(['l1', 'l2'], $this->savedSlugs());
		$this->assertCount(2, $result['objects']);
		$this->assertContainsOnlyInstancesOf(ObjectEntity::class, $result['objects']);
		$this->assertSame('uuid-l1', $result['objects'][0]->getUuid());
		$this->assertCount(1, $result['schemas']);
		$this->assertCount(1, $result['registers']);
		$this->assertSame(0, $result['skipped']['objects']);
	}//end testTopLevelSeedObjectsMaterialise()

	/**
	 * A top-level seed is saved into the resolved register and schema ids, its data intact.
	 *
	 * @return void
	 */
	public function testTopLevelSeedIsSavedWithResolvedIds(): void {
		$this->import([self::seed('l1')]);

		$this->assertCount(1, $this->saved);
		$this->assertSame(1, $this->saved[0]['object']['@self']['register']);
		$this->assertSame(10, $this->saved[0]['object']['@self']['schema']);
		$this->assertSame('Line l1', $this->saved[0]['object']['title']);
	}//end testTopLevelSeedIsSavedWithResolvedIds()

	/**
	 * Re-importing an unchanged top-level seed matches the stored object and creates nothing.
	 *
	 * @return void
	 */
	public function testReimportAtTheSameVersionCreatesNoDuplicate(): void {
		$this->import([self::seed('l1')]);
		$this->assertSame(['l1'], $this->savedSlugs());

		$this->stored['l1'] = [
			'@self' => ['id' => 'uuid-l1', 'register' => 1, 'schema' => 10, 'slug' => 'l1', 'version' => '1.0.0'],
			'title' => 'Line l1',
		];
		$this->saved = [];

		$result = $this->import([self::seed('l1')]);

		$this->assertSame([], $this->saved, 'an unchanged seed is not saved again');
		$this->assertSame([], $result['objects']);
		$this->assertSame(1, $result['unchanged']['objects']);
	}//end testReimportAtTheSameVersionCreatesNoDuplicate()

	/**
	 * A re-import at a higher version updates the stored object under its own uuid.
	 *
	 * @return void
	 */
	public function testReimportAtAHigherVersionUpdatesTheStoredObject(): void {
		$this->stored['l1'] = [
			'@self' => ['id' => 'uuid-stored', 'register' => 1, 'schema' => 10, 'slug' => 'l1', 'version' => '1.0.0'],
			'title' => 'Line l1',
		];

		$result = $this->import([self::seed('l1', ['version' => '2.0.0'])]);

		$this->assertCount(1, $this->saved);
		$this->assertSame('uuid-stored', $this->saved[0]['uuid'], 'the stored object is updated, not duplicated');
		$this->assertCount(1, $result['objects']);
	}//end testReimportAtAHigherVersionUpdatesTheStoredObject()

	/**
	 * The same seed in both keys imports once; seeds unique to either key each import.
	 *
	 * @return void
	 */
	public function testASeedInBothKeysImportsOnce(): void {
		$result = $this->import(
			[self::seed('shared'), self::seed('top-only')],
			[self::seed('shared'), self::seed('nested-only')]
		);

		$this->assertSame(['shared', 'nested-only', 'top-only'], $this->savedSlugs());
		$this->assertCount(3, $result['objects']);
	}//end testASeedInBothKeysImportsOnce()

	/**
	 * Seeds carrying the same `@self.id` collapse even when their slugs differ.
	 *
	 * @return void
	 */
	public function testASeedIdIsItsIdentity(): void {
		$this->import(
			[self::seed('renamed', ['id' => 'b0c1d2e3-0000-4000-8000-000000000001'])],
			[self::seed('original', ['id' => 'b0c1d2e3-0000-4000-8000-000000000001'])]
		);

		$this->assertSame(['original'], $this->savedSlugs());
	}//end testASeedIdIsItsIdentity()

	/**
	 * The same seeds listed at the top level or under components.objects import the same.
	 *
	 * @return void
	 */
	public function testTopLevelAndComponentsObjectsAreEquivalent(): void {
		$nested = $this->import(null, [self::seed('l1'), self::seed('l2')]);
		$nestedSaved = $this->saved;
		$this->saved = [];

		$topLevel = $this->import([self::seed('l1'), self::seed('l2')]);

		$this->assertEquals($nestedSaved, $this->saved);
		$this->assertCount(count($nested['objects']), $topLevel['objects']);
		$this->assertSame($nested['skipped'], $topLevel['skipped']);
	}//end testTopLevelAndComponentsObjectsAreEquivalent()

	/**
	 * A configuration with components.objects only imports exactly those.
	 *
	 * @return void
	 */
	public function testComponentsObjectsOnlyIsUnchanged(): void {
		$result = $this->import(null, [self::seed('n1')]);

		$this->assertSame(['n1'], $this->savedSlugs());
		$this->assertCount(1, $result['objects']);
	}//end testComponentsObjectsOnlyIsUnchanged()

	/**
	 * A slug-less top-level seed is passed over; the slugged one imports.
	 *
	 * @return void
	 */
	public function testASluglessTopLevelSeedIsSkipped(): void {
		$slugless = ['@self' => ['register' => 'sales', 'schema' => 'line'], 'title' => 'No slug'];

		$result = $this->import([$slugless, self::seed('l1')]);

		$this->assertSame(['l1'], $this->savedSlugs());
		$this->assertCount(1, $result['objects']);
	}//end testASluglessTopLevelSeedIsSkipped()

	/**
	 * A failing top-level seed is counted as skipped and does not stop its siblings.
	 *
	 * @return void
	 */
	public function testAFailingTopLevelSeedIsSkippedNotFatal(): void {
		$this->failing = ['bad'];

		$result = $this->import([self::seed('bad'), self::seed('good')]);

		$this->assertSame(['good'], $this->savedSlugs());
		$this->assertCount(1, $result['objects']);
		$this->assertSame(1, $result['skipped']['objects']);
	}//end testAFailingTopLevelSeedIsSkippedNotFatal()

	/**
	 * Entries of the top-level array that are not objects are ignored.
	 *
	 * @return void
	 */
	public function testNonArrayTopLevelEntriesAreIgnored(): void {
		$result = $this->import(['not-an-object', 42, self::seed('l1')]);

		$this->assertSame(['l1'], $this->savedSlugs());
		$this->assertCount(1, $result['objects']);
	}//end testNonArrayTopLevelEntriesAreIgnored()
}//end class
