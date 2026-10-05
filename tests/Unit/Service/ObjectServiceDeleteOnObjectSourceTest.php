<?php

declare(strict_types=1);

/**
 * A delete on a schema served by an object source reaches that source
 *
 * `ObjectService::deleteObject()` resolved the object through MagicMapper
 * before handing the delete to `DeleteObject`, and a scoped miss there is
 * rethrown as "not found". An object-source schema keeps no rows in a magic
 * table, so every DELETE on one stopped at that lookup: a writable database
 * source could never delete a row (its spec says it SHALL), and the
 * organisation projection answered 404 instead of its own refusal. The
 * provider dispatch in `DeleteObject` was never reached.
 *
 * These tests run the real ObjectService, the real DeleteObject handler and
 * the real ObjectSourceRegistry; the organisation case uses the real
 * OrganisationObjectSourceProvider.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveImports)
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
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
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
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
use OCA\OpenRegister\Service\ObjectSource\OrganisationObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\WritableObjectSourceProvider;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\IAppContainer;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests that deleteObject() hands an object-source delete to its provider.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveImports)
 */
class ObjectServiceDeleteOnObjectSourceTest extends TestCase {

	/** @var MockObject&MagicMapper */
	private MockObject $objectMapper;

	/** @var MockObject&PermissionHandler */
	private MockObject $permissionHandler;

	/** @var MockObject&OrganisationMapper */
	private MockObject $organisationMapper;

	/** @var ObjectSourceRegistry */
	private ObjectSourceRegistry $registry;

	/** @var ObjectService */
	private ObjectService $service;

	/**
	 * Build the real service, the real delete handler and the real registry.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);
		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->registry = new ObjectSourceRegistry($this->createMock(LoggerInterface::class));

		// MagicMapper has no row for an object-source id: the same miss the
		// scoped lookup raises for an absent native object.
		$this->objectMapper->method('find')->willThrowException(
			new DoesNotExistException('Object not found in magic table')
		);

		$deleteHandler = new DeleteObject(
			objectEntityMapper: $this->objectMapper,
			cacheHandler: $this->createMock(CacheHandler::class),
			userSession: $this->createMock(IUserSession::class),
			auditTrailMapper: $this->createMock(AuditTrailMapper::class),
			settingsService: $this->createMock(SettingsService::class),
			logger: $this->createMock(LoggerInterface::class),
			integrityService: $this->createMock(ReferentialIntegrityService::class),
			db: $this->createMock(IDBConnection::class),
			organisationMapper: $this->organisationMapper,
			objectSourceRegistry: $this->registry,
			registerMapper: $this->createMock(RegisterMapper::class)
		);

		$this->service = new ObjectService(
			$this->createMock(DataManipulationHandler::class),
			$deleteHandler,
			$this->createMock(GetObject::class),
			$this->permissionHandler,
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
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(ViewMapper::class),
			$this->objectMapper,
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
			$this->registry
		);
	}//end setUp()

	/**
	 * A register and a schema served by the given object source.
	 *
	 * @param array<string, mixed>|null $objectSource The `x-openregister-object-source` annotation, or null for a native schema.
	 *
	 * @return array{0: Register, 1: Schema}
	 */
	private function scope(?array $objectSource): array {
		$register = new Register();
		$register->setId(11);
		$register->setSlug('directory');

		$schema = new Schema();
		$schema->setId(42);
		$schema->setSlug('permits');
		if ($objectSource !== null) {
			$schema->setConfiguration(['x-openregister-object-source' => $objectSource]);
		}

		return [$register, $schema];
	}//end scope()

	/**
	 * A writable provider that records every remove() it is asked for.
	 *
	 * @param array<int, array{register: Register, schema: Schema, id: string}> $removed Receives each remove() call.
	 *
	 * @return WritableObjectSourceProvider
	 */
	private function recordingProvider(array &$removed): WritableObjectSourceProvider {
		return new class($removed) implements WritableObjectSourceProvider {
			/**
			 * @param array<int, array{register: Register, schema: Schema, id: string}> $removed Receives each remove() call.
			 */
			public function __construct(private array &$removed) {
			}

			public function getId(): string {
				return 'dbal-source';
			}

			public function isEnabled(): bool {
				return true;
			}

			public function find(Register $register, Schema $schema, string $id, array $config = []): ?ObjectEntity {
				return null;
			}

			public function findAll(Register $register, Schema $schema, array $query = [], array $config = []): array {
				return [];
			}

			public function count(Register $register, Schema $schema, array $query = [], array $config = []): int {
				return 0;
			}

			public function insert(Register $register, Schema $schema, array $data, array $config = []): ObjectEntity {
				throw new RuntimeException('insert is not part of this test');
			}

			public function update(Register $register, Schema $schema, string $id, array $data, array $config = []): ObjectEntity {
				throw new RuntimeException('update is not part of this test');
			}

			public function remove(Register $register, Schema $schema, string $id, array $config = []): bool {
				$this->removed[] = ['register' => $register, 'schema' => $schema, 'id' => $id];
				return true;
			}
		};
	}//end recordingProvider()

	/**
	 * DELETE on a writable source removes the external row.
	 *
	 * @return void
	 */
	public function testADeleteOnAWritableSourceReachesTheProvider(): void {
		$removed = [];
		$this->registry->addProvider($this->recordingProvider($removed));
		[$register, $schema] = $this->scope(['provider' => 'dbal-source', 'readOnly' => false, 'config' => ['table' => 'permits']]);

		$result = $this->service->deleteObject(uuid: '2', register: $register, schema: $schema);

		$this->assertTrue($result);
		$this->assertCount(1, $removed, 'the writable provider was never asked to remove the row');
		$this->assertSame('2', $removed[0]['id']);
		$this->assertTrue($removed[0]['register'] === $register);
	}//end testADeleteOnAWritableSourceReachesTheProvider()

	/**
	 * The organisation projection answers with its own refusal, not "not found".
	 *
	 * @return void
	 */
	public function testTheOrganisationProjectionAnswersWithItsOwnRefusal(): void {
		$this->organisationMapper->method('findByUuidFollowingMerge')->willThrowException(
			new DoesNotExistException('no such organisation')
		);
		$this->registry->addProvider(
			new OrganisationObjectSourceProvider(
				$this->organisationMapper,
				$this->createMock(IUserSession::class),
				$this->createMock(IGroupManager::class),
				$this->createMock(LoggerInterface::class),
				$this->createMock(OrganisationService::class)
			)
		);
		[$register, $schema] = $this->scope(['provider' => 'organisation-source', 'readOnly' => false]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/cannot be deleted through the object API/');

		$this->service->deleteObject(uuid: 'org-uuid-1', register: $register, schema: $schema);
	}//end testTheOrganisationProjectionAnswersWithItsOwnRefusal()

	/**
	 * A read-only source refuses the delete as a read-only projection.
	 *
	 * @return void
	 */
	public function testAReadOnlySourceIsRefusedAsReadOnly(): void {
		$removed = [];
		$this->registry->addProvider($this->recordingProvider($removed));
		[$register, $schema] = $this->scope(['provider' => 'dbal-source', 'config' => ['table' => 'permits']]);

		try {
			$this->service->deleteObject(uuid: '2', register: $register, schema: $schema);
			$this->fail('a delete on a read-only source went through');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('read-only projection', $e->getMessage());
		}

		$this->assertSame([], $removed);
	}//end testAReadOnlySourceIsRefusedAsReadOnly()

	/**
	 * Delete RBAC on the schema runs before the source is consulted.
	 *
	 * @return void
	 */
	public function testSchemaDeleteRbacRunsBeforeTheSourceIsConsulted(): void {
		$removed = [];
		$this->registry->addProvider($this->recordingProvider($removed));
		[$register, $schema] = $this->scope(['provider' => 'dbal-source', 'readOnly' => false]);

		$this->permissionHandler->expects($this->atLeastOnce())
			->method('checkPermission')
			->with($this->identicalTo($schema), 'delete')
			->willThrowException(new \Exception('User does not have permission to delete'));

		try {
			$this->service->deleteObject(uuid: '2', register: $register, schema: $schema);
			$this->fail('a refused caller reached the delete');
		} catch (\Exception $e) {
			$this->assertSame('User does not have permission to delete', $e->getMessage());
		}

		$this->assertSame([], $removed, 'the external row was removed for a caller without delete rights');
	}//end testSchemaDeleteRbacRunsBeforeTheSourceIsConsulted()

	/**
	 * Control: a scoped delete of an absent native object is still "not found".
	 *
	 * @return void
	 */
	public function testAnAbsentNativeObjectIsStillNotFound(): void {
		[$register, $schema] = $this->scope(null);

		$this->expectException(DoesNotExistException::class);

		$this->service->deleteObject(uuid: 'missing-uuid', register: $register, schema: $schema);
	}//end testAnAbsentNativeObjectIsStillNotFound()
}//end class
