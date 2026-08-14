<?php

/**
 * SchemasController DELETE safety regression tests.
 *
 * Spec REQ (runtime-schema-api):
 *   "Runtime schema deletion is guarded by object count"
 *
 * Every disposition of DELETE /api/schemas/{id} is spec-mandated and covered here:
 *  - Delete a schema with N > 0 objects without a flag     → HTTP 409
 *  - Delete a schema with N > 0 objects and ?force=true    → HTTP 200, objects
 *    orphaned, magic table left in place (unchanged legacy behaviour)
 *  - Delete an unused schema (N = 0)                       → HTTP 200, empty magic
 *    table reclaimed
 *  - Delete with ?deleteObjects=true                       → cascade: objects audited
 *    + hard-deleted, table dropped, schema removed
 *  - Both flags at once                                    → HTTP 400
 *  - Cascade without manage permission                     → HTTP 403
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\SchemaDeletionService;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Service\UploadService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for SchemasController::destroy DELETE-safety guard.
 */
class SchemasDestroySafetyTest extends TestCase {

	private SchemasController $controller;

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper $schemaMapper;

	/**
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper $objectMapper;

	/**
	 * @var SchemaCacheHandler&MockObject
	 */
	private SchemaCacheHandler $schemaCacheService;

	/**
	 * @var FacetCacheHandler&MockObject
	 */
	private FacetCacheHandler $facetCacheSvc;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * @var SchemaDeletionService&MockObject
	 */
	private SchemaDeletionService $schemaDeletionService;

	/**
	 * Whether the current user is an admin (drives checkSchemaManagePermission).
	 *
	 * @var bool
	 */
	private bool $isAdmin = true;

	/**
	 * Wire up SchemasController with every dependency mocked.
	 * Default user is an authenticated admin so all permission checks pass.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->schemaCacheService = $this->createMock(SchemaCacheHandler::class);
		$this->facetCacheSvc = $this->createMock(FacetCacheHandler::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->schemaDeletionService = $this->createMock(SchemaDeletionService::class);

		// SchemasController resolves IUserSession + IGroupManager lazily via the
		// container (checkSchemaManagePermission). Default to an authenticated admin
		// so all write-permission checks pass.
		$adminUser = $this->createMock(\OCP\IUser::class);
		$adminUser->method('getUID')->willReturn('admin');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($adminUser);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(fn (): bool => $this->isAdmin);
		$groupManager->method('getUserGroupIds')->willReturn(['admin']);

		// The cascade + table-reclaim work is resolved lazily from the container.
		$schemaDeletionService = $this->schemaDeletionService;

		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function ($id) use ($userSession, $groupManager, $schemaDeletionService) {
				if ($id === \OCP\IUserSession::class) {
					return $userSession;
				}

				if ($id === \OCP\IGroupManager::class) {
					return $groupManager;
				}

				if ($id === SchemaDeletionService::class) {
					return $schemaDeletionService;
				}

				return null;
			}
		);

		$this->controller = new SchemasController(
			'openregister',
			$this->request,
			$this->createMock(IAppConfig::class),
			$this->schemaMapper,
			$this->objectMapper,
			$this->createMock(UploadService::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(OrganisationService::class),
			$this->schemaCacheService,
			$this->facetCacheSvc,
			$this->createMock(SchemaService::class),
			$this->logger,
			$this->container,
			$this->createMock(SchemaVersioningService::class)
		);

	}//end setUp()

	/**
	 * Build a Schema entity with injected id + slug.
	 */
	private function makeSchema(int $id, string $slug = 'test-schema'): Schema {
		$schema = new Schema();
		$schema->setSlug($slug);
		$schema->setTitle($slug);

		$ref = new ReflectionClass($schema);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($schema, $id);

		return $schema;
	}//end makeSchema()

	/**
	 * Stub the two mutually-exclusive delete dispositions on the request.
	 *
	 * @param string|null $force Value of ?force.
	 * @param string|null $deleteObjects Value of ?deleteObjects.
	 */
	private function stubFlags(?string $force = null, ?string $deleteObjects = null): void {
		$this->request
			->method('getParam')
			->willReturnCallback(
				static function (string $key, $default = null) use ($force, $deleteObjects) {
					if ($key === 'force') {
						return $force;
					}

					if ($key === 'deleteObjects') {
						return $deleteObjects;
					}

					return $default;
				}
			);

	}//end stubFlags()

	/**
	 * REQ + SCENARIO: "Delete a schema with objects, no force flag".
	 *
	 * MUST return HTTP 409 with `{ error: 'schema-has-objects', objectCount: N }`
	 * — and crucially MUST NOT call SchemaMapper::delete (the schema stays
	 * persisted). Cache invalidation MUST NOT fire on the rejected path.
	 */
	public function testDestroyWithoutForceReturns409WhenObjectsExist(): void {
		$schema = $this->makeSchema(42, 'application');

		$this->schemaMapper
			->expects($this->once())
			->method('find')
			->with($this->equalTo(42))
			->willReturn($schema);

		// 5 objects still reference this schema.
		$this->objectMapper
			->expects($this->once())
			->method('getStatistics')
			->with($this->equalTo(null), $this->equalTo(42))
			->willReturn(['total' => 5]);

		// No disposition flag is set → guard MUST fire.
		$this->stubFlags();

		// The schema MUST remain persisted.
		$this->schemaMapper->expects($this->never())->method('delete');
		$this->schemaCacheService->expects($this->never())->method('invalidate');
		$this->schemaDeletionService->expects($this->never())->method('cascadeDeleteSchema');

		$response = $this->controller->destroy(42);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(409, $response->getStatus());

		$data = $response->getData();
		$this->assertSame('schema-has-objects', $data['error']);
		$this->assertSame(5, $data['objectCount']);

	}//end testDestroyWithoutForceReturns409WhenObjectsExist()

	/**
	 * REQ + SCENARIO: "Delete a schema with objects and force=true".
	 *
	 * MUST proceed with delete (204/200), MUST invoke
	 * SchemaCacheHandler::invalidate(id), MUST log a WARNING with
	 * orphan count.
	 */
	public function testDestroyWithForceTrueDeletesAndInvalidatesCache(): void {
		$schema = $this->makeSchema(42, 'application');

		$this->schemaMapper
			->expects($this->once())
			->method('find')
			->with($this->equalTo(42))
			->willReturn($schema);

		$this->objectMapper
			->expects($this->once())
			->method('getStatistics')
			->willReturn(['total' => 7]);

		// ?force=true is set.
		$this->stubFlags(force: 'true');

		// Delete MUST be called once, and MUST pass the force bypass through to the
		// mapper guard — that is what "orphan the objects" means at the mapper level.
		$this->schemaMapper
			->expects($this->once())
			->method('delete')
			->with($this->equalTo($schema), $this->isTrue());

		// BACK-COMPAT: force still ORPHANS. The populated magic table is deliberately
		// left in place — dropping it would destroy the rows with no audit entry.
		$this->schemaDeletionService->expects($this->never())->method('dropEmptyTablesForSchema');
		$this->schemaDeletionService->expects($this->never())->method('cascadeDeleteSchema');

		// Cache MUST be invalidated on the affected schema ID.
		$this->schemaCacheService
			->expects($this->once())
			->method('invalidate')
			->with($this->equalTo(42));

		// A WARNING-level log MUST surface the orphan count for operator review.
		$this->logger
			->expects($this->atLeastOnce())
			->method('warning')
			->with(
				$this->stringContains('Force-deleting schema with attached objects'),
				$this->callback(
					function (array $ctx): bool {
						return ($ctx['schemaId'] ?? null) === 42
						&& ($ctx['objectCount'] ?? null) === 7;
					}
				)
			);

		$response = $this->controller->destroy(42);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());

	}//end testDestroyWithForceTrueDeletesAndInvalidatesCache()

	/**
	 * REQ + SCENARIO: "Delete an unused schema" (regression baseline).
	 *
	 * When zero objects reference the schema, the destroy path MUST proceed
	 * straight through to delete + invalidate without involving the force
	 * flag. Establishes the happy-path baseline so the 409 + force-true
	 * paths above are not vacuous.
	 */
	public function testDestroyOnUnusedSchemaSucceeds(): void {
		$schema = $this->makeSchema(99, 'orphan-free');

		$this->schemaMapper
			->expects($this->once())
			->method('find')
			->with($this->equalTo(99))
			->willReturn($schema);

		// Zero objects reference this schema.
		$this->objectMapper
			->expects($this->once())
			->method('getStatistics')
			->willReturn(['total' => 0]);

		$this->stubFlags();

		$this->schemaMapper->expects($this->once())->method('delete');
		$this->schemaCacheService->expects($this->once())->method('invalidate')->with($this->equalTo(99));

		// TASK 2.5: the (empty) magic table is reclaimed instead of being orphaned.
		$this->schemaDeletionService
			->expects($this->once())
			->method('dropEmptyTablesForSchema')
			->with($this->equalTo($schema));

		$response = $this->controller->destroy(99);

		$this->assertSame(200, $response->getStatus());

	}//end testDestroyOnUnusedSchemaSucceeds()

	/**
	 * REQ + SCENARIO: "Cascade — delete a schema and its objects".
	 *
	 * ?deleteObjects=true tears the schema down completely: objects audited and
	 * hard-deleted, magic table dropped, schema removed. The response names what was
	 * destroyed.
	 */
	public function testCascadeDeletesObjectsAndReportsWhatItRemoved(): void {
		$schema = $this->makeSchema(42, 'cow');

		$this->schemaMapper->method('find')->willReturn($schema);
		$this->objectMapper->method('getStatistics')->willReturn(['total' => 2]);

		$this->stubFlags(deleteObjects: 'true');

		$this->schemaDeletionService
			->expects($this->once())
			->method('cascadeDeleteSchema')
			->with($this->equalTo($schema))
			->willReturn(
				[
					'deletedCount' => 2,
					'deletedUuids' => ['uuid-1', 'uuid-2'],
					'tableDropped' => true,
				]
			);

		// The cascade owns the schema delete (inside its transaction) — the controller
		// must not delete it a second time.
		$this->schemaMapper->expects($this->never())->method('delete');

		// Caches are invalidated on EVERY disposition, cascade included.
		$this->schemaCacheService->expects($this->once())->method('invalidate')->with($this->equalTo(42));
		$this->facetCacheSvc->expects($this->once())->method('invalidateForSchemaChange');

		$response = $this->controller->destroy(42);

		$this->assertSame(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame(42, $data['schemaId']);
		$this->assertSame(2, $data['deletedCount']);
		$this->assertSame(['uuid-1', 'uuid-2'], $data['deletedUuids']);
		$this->assertTrue($data['tableDropped']);

	}//end testCascadeDeletesObjectsAndReportsWhatItRemoved()

	/**
	 * REQ + SCENARIO: "Cascade succeeds but the table drop fails".
	 *
	 * The request still succeeds — the data work is committed — and reports the
	 * leftover empty table honestly.
	 */
	public function testCascadeReportsAFailedTableDrop(): void {
		$schema = $this->makeSchema(42, 'cow');

		$this->schemaMapper->method('find')->willReturn($schema);
		$this->objectMapper->method('getStatistics')->willReturn(['total' => 1]);

		$this->stubFlags(deleteObjects: 'true');

		$this->schemaDeletionService
			->method('cascadeDeleteSchema')
			->willReturn(
				[
					'deletedCount' => 1,
					'deletedUuids' => ['uuid-1'],
					'tableDropped' => false,
				]
			);

		$response = $this->controller->destroy(42);

		$this->assertSame(200, $response->getStatus());
		$this->assertFalse($response->getData()['tableDropped']);

	}//end testCascadeReportsAFailedTableDrop()

	/**
	 * REQ + SCENARIO: "Both dispositions passed at once".
	 *
	 * force ORPHANS the objects and deleteObjects DESTROYS them. Asking for both is an
	 * ambiguous destructive intent and is refused outright — before the schema is even
	 * looked up, so nothing can be touched.
	 */
	public function testBothDispositionFlagsAreRefusedWith400(): void {
		$this->stubFlags(force: 'true', deleteObjects: 'true');

		$this->schemaMapper->expects($this->never())->method('find');
		$this->schemaMapper->expects($this->never())->method('delete');
		$this->schemaDeletionService->expects($this->never())->method('cascadeDeleteSchema');

		$response = $this->controller->destroy(42);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('conflicting-delete-dispositions', $response->getData()['error']);

	}//end testBothDispositionFlagsAreRefusedWith400()

	/**
	 * REQ + SCENARIO: "Cascade requires manage permission".
	 *
	 * A caller who fails checkSchemaManagePermission() gets HTTP 403 and NOTHING is
	 * deleted — not the schema, not one object.
	 */
	public function testCascadeWithoutManagePermissionIsRefusedWith403(): void {
		$schema = $this->makeSchema(42, 'cow');
		$this->schemaMapper->method('find')->willReturn($schema);

		// Not an admin, and the schema declares no manage rule → default-secure deny.
		$this->isAdmin = false;

		$this->stubFlags(deleteObjects: 'true');

		$this->schemaDeletionService->expects($this->never())->method('cascadeDeleteSchema');
		$this->schemaMapper->expects($this->never())->method('delete');

		$response = $this->controller->destroy(42);

		$this->assertSame(403, $response->getStatus());

	}//end testCascadeWithoutManagePermissionIsRefusedWith403()
}//end class
