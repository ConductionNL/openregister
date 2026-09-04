<?php

declare(strict_types=1);

/**
 * Unit tests for the raw append entry points on ObjectService.
 *
 * appendObjectsRaw() and purgeExpiredObjectsRaw() are the fast path for
 * high-volume writers. These tests pin what the service does AROUND the
 * mapper: how a plain row is shaped for the bulk handler, that the bulk
 * upsert runs with the pre-update fetch switched off, how identifiers are
 * resolved within the register, and that the service's own register/schema
 * context is never touched.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\AuditHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\MergeHandler;
use OCA\OpenRegister\Service\Object\MetadataHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\IAppContainer;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class ObjectServiceRawAppendTest extends TestCase {

	private ObjectService $service;

	private MagicMapper&MockObject $magicMapper;

	private RegisterMapper&MockObject $registerMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private Register $register;

	private Schema $schema;

	protected function setUp(): void {
		parent::setUp();

		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		$this->register = new Register();
		$this->register->setId(1);
		$this->register->setSlug('portaliq');
		$this->register->setSchemas([2]);

		$this->schema = new Schema();
		$this->schema->setId(2);
		$this->schema->setSlug('portalTrafficEvent');

		$this->service = new ObjectService(
			$this->createMock(DataManipulationHandler::class),
			$this->createMock(DeleteObject::class),
			$this->createMock(GetObject::class),
			$this->createMock(PermissionHandler::class),
			$this->createMock(RenderObject::class),
			$this->createMock(SaveObject::class),
			$this->createMock(SaveObjects::class),
			$this->createMock(SearchQueryHandler::class),
			$this->createMock(ValidateObject::class),
			$this->createMock(LockHandler::class),
			$this->createMock(AuditHandler::class),
			$this->createMock(RelationHandler::class),
			$this->createMock(MergeHandler::class),
			$this->createMock(FacetHandler::class),
			$this->createMock(MetadataHandler::class),
			$this->createMock(PerformanceOptimizationHandler::class),
			$this->createMock(QueryHandler::class),
			$this->createMock(RevertHandler::class),
			$this->createMock(UtilityHandler::class),
			$this->createMock(ValidationHandler::class),
			$this->createMock(CascadingHandler::class),
			$this->createMock(MigrationHandler::class),
			$this->registerMapper,
			$this->schemaMapper,
			$this->createMock(ViewMapper::class),
			$this->magicMapper,
			$this->createMock(FileService::class),
			$this->createMock(IUserSession::class),
			$this->createMock(SearchTrailService::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(CacheHandler::class),
			$this->createMock(SettingsService::class),
			$this->createMock(DateTimeNormalizer::class),
			$this->createMock(IAppContainer::class),
			$this->createMock(ObjectSourceRegistry::class)
		);
	}

	/**
	 * Read a private property off the service.
	 */
	private function contextProperty(string $name): mixed {
		$property = (new ReflectionClass(ObjectService::class))->getProperty($name);
		$property->setAccessible(true);

		return $property->getValue($this->service);
	}

	public function testAppendShapesRowsAndRunsTheBulkPathWithSafeguardsOff(): void {
		$this->magicMapper->expects($this->once())
			->method('ensureTableForRegisterSchema')
			->with($this->register, $this->schema);
		$this->magicMapper->method('getTableNameForRegisterSchema')
			->with($this->register, $this->schema)
			->willReturn('openregister_table_1_2');

		$captured = [];
		$this->magicMapper->expects($this->once())
			->method('bulkUpsert')
			->with(
				$this->callback(function (array $rows) use (&$captured): bool {
					$captured = $rows;
					return true;
				}),
				$this->register,
				$this->schema,
				'openregister_table_1_2',
				false
			)
			->willReturn([['_uuid' => 'a'], ['_uuid' => 'b']]);

		$written = $this->service->appendObjectsRaw(
			objects: [
				[
					'uuid' => 'fixed-uuid',
					'expires' => '2026-12-31T00:00:00+00:00',
					'owner' => 'collector',
					'name' => 'page_view',
					'pagePath' => '/home',
				],
				['name' => 'scroll', 'params' => ['depth' => 90]],
			],
			register: $this->register,
			schema: $this->schema
		);

		$this->assertSame(2, $written, 'The return value is the number of rows the mapper reports written.');
		$this->assertCount(2, $captured);

		// Metadata moved into @self; properties stayed at the top level.
		$this->assertSame('fixed-uuid', $captured[0]['@self']['uuid']);
		$this->assertSame('2026-12-31T00:00:00+00:00', $captured[0]['@self']['expires']);
		$this->assertSame('collector', $captured[0]['@self']['owner']);
		$this->assertArrayNotHasKey('uuid', $captured[0]);
		$this->assertArrayNotHasKey('expires', $captured[0]);
		$this->assertArrayNotHasKey('owner', $captured[0]);
		$this->assertSame('page_view', $captured[0]['name']);
		$this->assertSame('/home', $captured[0]['pagePath']);

		// A row without a uuid gets a generated v4; no expiry key is invented.
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$captured[1]['@self']['uuid']
		);
		$this->assertArrayNotHasKey('expires', $captured[1]['@self']);
		$this->assertSame(['depth' => 90], $captured[1]['params']);
	}

	public function testAppendOfNothingTouchesNothing(): void {
		$this->magicMapper->expects($this->never())->method('ensureTableForRegisterSchema');
		$this->magicMapper->expects($this->never())->method('bulkUpsert');
		$this->registerMapper->expects($this->never())->method('find');

		$this->assertSame(0, $this->service->appendObjectsRaw(objects: [], register: 'portaliq', schema: 'portalTrafficEvent'));
	}

	public function testSlugsResolveWithinTheRegisterAndLeaveTheContextAlone(): void {
		// The register resolves without RBAC or tenancy, like setRegister() does.
		$this->registerMapper->expects($this->once())
			->method('find')
			->with('portaliq', false, false)
			->willReturn($this->register);

		// The schema resolves among the register's carried ids, never globally.
		$this->schemaMapper->expects($this->once())
			->method('findInIds')
			->with('portalTrafficEvent', [2])
			->willReturn($this->schema);
		$this->schemaMapper->expects($this->never())->method('find');

		$this->magicMapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_2');
		$this->magicMapper->expects($this->once())
			->method('bulkUpsert')
			->with($this->anything(), $this->register, $this->schema, 'openregister_table_1_2', false)
			->willReturn([['_uuid' => 'a']]);

		$written = $this->service->appendObjectsRaw(
			objects: [['name' => 'page_view']],
			register: 'portaliq',
			schema: 'portalTrafficEvent'
		);

		$this->assertSame(1, $written);
		$this->assertNull($this->contextProperty('currentRegister'), 'A raw append must not set the service register context.');
		$this->assertNull($this->contextProperty('currentSchema'), 'A raw append must not set the service schema context.');
		$this->assertNull($this->contextProperty('currentSchemaRef'), 'A raw append must not leave a pending schema ref behind.');
	}

	public function testPurgeDelegatesToTheMapperAndReportsTheCount(): void {
		$this->magicMapper->expects($this->once())
			->method('purgeExpired')
			->with($this->register, $this->schema)
			->willReturn(3);

		$this->assertSame(3, $this->service->purgeExpiredObjectsRaw(register: $this->register, schema: $this->schema));
	}

	public function testPurgeResolvesIdentifiersWithinTheRegister(): void {
		$this->registerMapper->expects($this->once())
			->method('find')
			->with(1, false, false)
			->willReturn($this->register);
		$this->schemaMapper->expects($this->once())
			->method('findInIds')
			->with(2, [2])
			->willReturn($this->schema);

		$this->magicMapper->expects($this->once())
			->method('purgeExpired')
			->with($this->register, $this->schema)
			->willReturn(0);

		$this->assertSame(0, $this->service->purgeExpiredObjectsRaw(register: 1, schema: 2));
		$this->assertNull($this->contextProperty('currentRegister'));
		$this->assertNull($this->contextProperty('currentSchema'));
	}
}
