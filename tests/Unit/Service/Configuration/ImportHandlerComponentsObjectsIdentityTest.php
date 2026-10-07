<?php

/**
 * A configuration's components.objects carries identity the way seed data does.
 *
 * 🔴 LIVE DEFECT (live pass, 5 Oct, O6): objects listed under components.objects
 * with a top-level `uuid` and `slug` (the seed format) got a random uuid, and
 * the top-level `slug` reached MagicMapper as data: "Discarding 2 properties
 * the schema does not declare: slug, notInSchema". #4315 stripped those keys on
 * the x-openregister.seedData path only.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-seed-metadata-keys-are-not-stored-as-data
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
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Resilience + no-user-context fallback regression tests for ImportHandler.
 */
class ImportHandlerComponentsObjectsIdentityTest extends TestCase {

	private SchemaMapper&MockObject $schemaMapper;

	private RegisterMapper&MockObject $registerMapper;

	private MagicMapper&MockObject $objectEntityMapper;

	private ConfigurationMapper&MockObject $configurationMapper;

	private MappingMapper&MockObject $mappingMapper;

	private Client&MockObject $client;

	private IAppConfig&MockObject $appConfig;

	private LoggerInterface&MockObject $logger;

	private UploadHandler&MockObject $uploadHandler;

	private ObjectService&MockObject $objectService;

	private ImportHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->objectEntityMapper = $this->createMock(MagicMapper::class);
		$this->configurationMapper = $this->createMock(ConfigurationMapper::class);
		$this->mappingMapper = $this->createMock(MappingMapper::class);
		$this->client = $this->createMock(Client::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->uploadHandler = $this->createMock(UploadHandler::class);
		$this->objectService = $this->createMock(ObjectService::class);

		// No previously-imported version -> never skip on version check.
		$this->appConfig->method('getValueString')->willReturn('');
		$this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
		$this->registerMapper->method('getSlugToIdMap')->willReturn([]);
		$this->mappingMapper->method('getSlugToIdMap')->willReturn([]);

		$this->handler = new ImportHandler(
			schemaMapper:        $this->schemaMapper,
			registerMapper:      $this->registerMapper,
			objectEntityMapper:  $this->objectEntityMapper,
			configurationMapper: $this->configurationMapper,
			mappingMapper:       $this->mappingMapper,
			client:              $this->client,
			appConfig:           $this->appConfig,
			logger:              $this->logger,
			appDataPath:         '/tmp',
			uploadHandler:       $this->uploadHandler,
			objectService:       $this->objectService
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
		$schema->hydrate(['slug' => $slug, 'title' => $slug, 'version' => '1.0.0', 'properties' => []]);
		$schema->setId($id);
		return $schema;
	}//end makeSchema()

	/**
	 * A failing register MUST be skipped (logged warning + counter) while the
	 * valid register AND all schemas are still created.
	 */
	/**
	 * Import one object through components.objects and capture the save.
	 *
	 * @param array<string, mixed> $properties The schema's declared properties.
	 * @param array<string, mixed> $object     The listed object.
	 *
	 * @return array<int, array{object: array<string, mixed>, uuid: string|null}>
	 */
	private function importListed(array $properties, array $object): array {
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('not found'));
		$this->registerMapper->method('createFromArray')->willReturnCallback(fn (array $d) => $this->makeRegister(1, $d['slug']));
		$this->registerMapper->method('update')->willReturnArgument(0);
		$this->schemaMapper->method('createFromArray')->willReturnCallback(
			function (array $d) use ($properties) {
				$schema = $this->makeSchema(10, $d['slug']);
				$schema->setProperties($properties);
				return $schema;
			}
		);
		$this->schemaMapper->method('update')->willReturnArgument(0);
		$this->objectService->method('searchObjects')->willReturn([]);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) use (&$saved) {
				$saved[] = ['object' => $object, 'uuid' => $uuid];
				$entity = new \OCA\OpenRegister\Db\ObjectEntity();
				$entity->setUuid($uuid ?? 'random');
				return $entity;
			}
		);

		$data = [
			'appId' => 'livepass',
			'version' => '9.9.9',
			'components' => [
				'schemas' => ['seed' => ['slug' => 'seed', 'title' => 'Seed', 'properties' => $properties]],
				'registers' => ['reg' => ['slug' => 'reg', 'version' => '1.0.0', 'schemas' => ['seed']]],
				'objects' => [$object],
			],
		];

		$this->handler->importFromJson(data: $data, configuration: new Configuration(), appId: 'livepass', version: '9.9.9');

		return $saved;
	}//end importListed()

	/**
	 * 🔴 The live case: the top-level uuid is the object's uuid, and neither key is data.
	 *
	 * @return void
	 */
	public function testTopLevelUuidAndSlugAreIdentityNotData(): void {
		$saved = $this->importListed(
			['title' => ['type' => 'string']],
			[
				'@self' => ['register' => 'reg', 'schema' => 'seed', 'slug' => 'livepass-lane12-a1', 'version' => '1.0.0'],
				'uuid' => '6a1d1f9e-3c55-4e0b-8b8e-2f6b0f6f1a01',
				'slug' => 'livepass-lane12-a1',
				'title' => 'A1',
			]
		);

		$this->assertCount(1, $saved);
		$this->assertSame('6a1d1f9e-3c55-4e0b-8b8e-2f6b0f6f1a01', $saved[0]['uuid'], 'the listed uuid is the object uuid');
		$this->assertArrayNotHasKey('uuid', $saved[0]['object']);
		$this->assertArrayNotHasKey('slug', $saved[0]['object']);
		$this->assertSame('A1', $saved[0]['object']['title']);
		$this->assertSame('livepass-lane12-a1', $saved[0]['object']['@self']['slug']);
	}//end testTopLevelUuidAndSlugAreIdentityNotData()

	/**
	 * A schema that declares `slug` keeps it as data.
	 *
	 * @return void
	 */
	public function testADeclaredSlugStaysData(): void {
		$saved = $this->importListed(
			['title' => ['type' => 'string'], 'slug' => ['type' => 'string']],
			['@self' => ['register' => 'reg', 'schema' => 'seed', 'slug' => 'b1'], 'slug' => 'b1', 'title' => 'B1']
		);

		$this->assertCount(1, $saved);
		$this->assertSame('b1', $saved[0]['object']['slug']);
		$this->assertNull($saved[0]['uuid'], 'no uuid listed: the store chooses one');
	}//end testADeclaredSlugStaysData()

	/**
	 * An @self.uuid wins over a top-level one.
	 *
	 * @return void
	 */
	public function testSelfUuidWinsOverTopLevel(): void {
		$saved = $this->importListed(
			['title' => ['type' => 'string']],
			[
				'@self' => ['register' => 'reg', 'schema' => 'seed', 'slug' => 'c1', 'uuid' => '0b9a2a52-7e19-4a1e-9f0c-6f3c0e7a2b02'],
				'uuid' => '6a1d1f9e-3c55-4e0b-8b8e-2f6b0f6f1a03',
				'title' => 'C1',
			]
		);

		$this->assertSame('0b9a2a52-7e19-4a1e-9f0c-6f3c0e7a2b02', $saved[0]['uuid']);
	}//end testSelfUuidWinsOverTopLevel()
}//end class
