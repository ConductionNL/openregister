<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Controller;

use OCA\OpenRegister\Controller\SourcesController;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionService;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\Sync\HarvestPipelineService;
use OCA\OpenRegister\Service\Sync\SourceFetcherRegistry;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for SourcesController
 *
 * @package Unit\Controller
 */
class SourcesControllerTest extends TestCase {
	private SourcesController $controller;
	private IRequest&MockObject $request;
	private IAppConfig&MockObject $config;
	private SourceMapper&MockObject $sourceMapper;
	private DatabaseIntrospectionService&MockObject $introspectionService;

	protected function setUp(): void {
		parent::setUp();
		$this->controller = $this->buildController(isAdmin: true);
	}

	/**
	 * Build the controller with a real DBAL connection factory (null-secret
	 * credential store) and a mocked introspection service.
	 *
	 * @param bool $isAdmin Whether the acting user is an admin.
	 *
	 * @return SourcesController The controller under test.
	 */
	private function buildController(bool $isAdmin): SourcesController {
		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->sourceMapper = $this->createMock(SourceMapper::class);
		$this->introspectionService = $this->createMock(DatabaseIntrospectionService::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		// Write endpoints are admin-gated; simulate an authenticated user.
		$adminUser = $this->createMock(\OCP\IUser::class);
		$adminUser->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(\OCP\IUserSession::class);
		$userSession->method('getUser')->willReturn($adminUser);
		$groupManager = $this->createMock(\OCP\IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		$credentialStore = $this->createMock(CredentialStore::class);
		$credentialStore->method('get')->willReturn(null);

		return new SourcesController(
			'openregister',
			$this->request,
			$this->config,
			$this->sourceMapper,
			$l10n,
			$userSession,
			$groupManager,
			$this->createMock(\OCP\Security\ICrypto::class),
			$this->createMock(SourceFetcherRegistry::class),
			$this->createMock(HarvestPipelineService::class),
			new DbalConnectionFactory(credentialStore: $credentialStore, logger: new NullLogger()),
			$this->introspectionService,
			new NullLogger()
		);
	}

	// -------------------------------------------------------------------------
	// index()
	// -------------------------------------------------------------------------

	public function testIndexReturnsSourcesWithEmptyParams(): void {
		$source = new Source();
		$this->request->method('getParams')->willReturn([]);
		$this->sourceMapper->method('findAll')->willReturn([$source]);

		$result = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertArrayHasKey('results', $data);
		$this->assertCount(1, $data['results']);
	}

	public function testIndexReturnsEmptyResults(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->sourceMapper->method('findAll')->willReturn([]);

		$result = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertSame([], $data['results']);
	}

	public function testIndexWithLimitAndOffset(): void {
		$this->request->method('getParams')->willReturn([
			'_limit' => '10',
			'_offset' => '5',
		]);
		$this->sourceMapper->expects($this->once())
			->method('findAll')
			->with(
				$this->equalTo(10),
				$this->equalTo(5),
				$this->equalTo([])
			)
			->willReturn([]);

		$result = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $result->getStatus());
	}

	public function testIndexWithPageConvertsToOffset(): void {
		$this->request->method('getParams')->willReturn([
			'_limit' => '10',
			'_page' => '3',
		]);
		// page 3 with limit 10 => offset = (3-1)*10 = 20
		$this->sourceMapper->expects($this->once())
			->method('findAll')
			->with(
				$this->equalTo(10),
				$this->equalTo(20),
				$this->equalTo([])
			)
			->willReturn([]);

		$result = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $result->getStatus());
	}

	public function testIndexWithPageOneConvertsToOffsetZero(): void {
		$this->request->method('getParams')->willReturn([
			'_limit' => '5',
			'_page' => '1',
		]);
		// page 1 with limit 5 => offset = (1-1)*5 = 0
		$this->sourceMapper->expects($this->once())
			->method('findAll')
			->with(
				$this->equalTo(5),
				$this->equalTo(0),
				$this->equalTo([])
			)
			->willReturn([]);

		$this->controller->index();
	}

	public function testIndexPageWithoutLimitDoesNotConvert(): void {
		$this->request->method('getParams')->willReturn([
			'_page' => '3',
		]);
		// Without _limit, page should not be converted to offset
		$this->sourceMapper->expects($this->once())
			->method('findAll')
			->with(
				$this->isNull(),
				$this->isNull(),
				$this->equalTo([])
			)
			->willReturn([]);

		$this->controller->index();
	}

	public function testIndexFiltersOutSpecialParams(): void {
		$this->request->method('getParams')->willReturn([
			'_limit' => '10',
			'_offset' => '0',
			'_page' => '1',
			'_search' => 'test',
			'_route' => 'openregister.sources.index',
			'type' => 'api',
			'status' => 'active',
		]);
		$this->sourceMapper->expects($this->once())
			->method('findAll')
			->with(
				$this->equalTo(10),
				$this->equalTo(0),
				$this->equalTo(['type' => 'api', 'status' => 'active'])
			)
			->willReturn([]);

		$result = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $result->getStatus());
	}

	public function testIndexWithOnlyLimitParam(): void {
		$this->request->method('getParams')->willReturn([
			'_limit' => '25',
		]);
		$this->sourceMapper->expects($this->once())
			->method('findAll')
			->with(
				$this->equalTo(25),
				$this->isNull(),
				$this->equalTo([])
			)
			->willReturn([]);

		$this->controller->index();
	}

	public function testIndexWithOnlyOffsetParam(): void {
		$this->request->method('getParams')->willReturn([
			'_offset' => '15',
		]);
		$this->sourceMapper->expects($this->once())
			->method('findAll')
			->with(
				$this->isNull(),
				$this->equalTo(15),
				$this->equalTo([])
			)
			->willReturn([]);

		$this->controller->index();
	}

	public function testIndexWithMultipleSources(): void {
		$source1 = new Source();
		$source2 = new Source();
		$source3 = new Source();
		$this->request->method('getParams')->willReturn([]);
		$this->sourceMapper->method('findAll')->willReturn([$source1, $source2, $source3]);

		$result = $this->controller->index();

		$data = $result->getData();
		$this->assertCount(3, $data['results']);
	}

	// -------------------------------------------------------------------------
	// show()
	// -------------------------------------------------------------------------

	public function testShowReturnsSource(): void {
		$source = new Source();
		$this->sourceMapper->method('find')->with(1)->willReturn($source);

		$result = $this->controller->show('1');

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(Http::STATUS_OK, $result->getStatus());
	}

	public function testShowReturns404WhenNotFound(): void {
		$this->sourceMapper->method('find')
			->willThrowException(new DoesNotExistException('Not found'));

		$result = $this->controller->show('999');

		$this->assertSame(404, $result->getStatus());
		$data = $result->getData();
		$this->assertSame('Not Found', $data['error']);
	}

	public function testShowCastsStringIdToInt(): void {
		$source = new Source();
		$this->sourceMapper->expects($this->once())
			->method('find')
			->with($this->equalTo(42))
			->willReturn($source);

		$result = $this->controller->show('42');

		$this->assertSame(Http::STATUS_OK, $result->getStatus());
	}

	public function testShowWithNonNumericIdCastsToZero(): void {
		$source = new Source();
		$this->sourceMapper->expects($this->once())
			->method('find')
			->with($this->equalTo(0))
			->willReturn($source);

		$result = $this->controller->show('abc');

		$this->assertSame(Http::STATUS_OK, $result->getStatus());
	}

	// -------------------------------------------------------------------------
	// create()
	// -------------------------------------------------------------------------

	public function testCreateReturnsCreatedSource(): void {
		$source = new Source();
		$this->request->method('getParams')->willReturn(['name' => 'test source']);
		$this->sourceMapper->method('createFromArray')->willReturn($source);

		$result = $this->controller->create();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(Http::STATUS_OK, $result->getStatus());
	}

	public function testCreateRemovesInternalParams(): void {
		$source = new Source();
		$this->request->method('getParams')->willReturn([
			'_route' => 'test.route',
			'_limit' => '10',
			'name' => 'test',
		]);
		$this->sourceMapper->expects($this->once())
			->method('createFromArray')
			->with($this->callback(function ($data) {
				return !isset($data['_route'])
					&& !isset($data['_limit'])
					&& isset($data['name'])
					&& $data['name'] === 'test';
			}))
			->willReturn($source);

		$this->controller->create();
	}

	public function testCreateRemovesIdIfPresent(): void {
		$source = new Source();
		$this->request->method('getParams')->willReturn([
			'id' => 5,
			'name' => 'test',
		]);
		$this->sourceMapper->expects($this->once())
			->method('createFromArray')
			->with($this->callback(function ($data) {
				return !isset($data['id']) && isset($data['name']);
			}))
			->willReturn($source);

		$this->controller->create();
	}

	public function testCreateDoesNotRemoveIdIfNull(): void {
		$source = new Source();
		$this->request->method('getParams')->willReturn([
			'id' => null,
			'name' => 'test',
		]);
		$this->sourceMapper->expects($this->once())
			->method('createFromArray')
			->with($this->callback(function ($data) {
				// null id should also be removed by the check
				return !isset($data['id']) && isset($data['name']);
			}))
			->willReturn($source);

		$this->controller->create();
	}

	/**
	 * Custody: a plaintext password/secret submitted for a `type: database`
	 * source must be stripped BEFORE persistence (D1) — the mapper never sees
	 * it, and databaseUrl is cleared on the database path.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testCreateStripsSubmittedDatabasePassword(): void {
		$source = new Source();
		$this->request->method('getParams')->willReturn([
			'name' => 'ext-db',
			'type' => 'database',
			'databaseUrl' => 'postgres://user:PASS@host:5432/db',
			'authConfig' => [
				'host' => 'db.example.org',
				'password' => 'YOUR_PASSWORD_HERE',
				'secret' => 'YOUR_SECRET_HERE',
				'credential' => '00000000-0000-0000-0000-000000000000',
			],
		]);
		$this->sourceMapper->expects($this->once())
			->method('createFromArray')
			->with($this->callback(function ($data) {
				return isset($data['authConfig']['password']) === false
					&& isset($data['authConfig']['secret']) === false
					&& ($data['databaseUrl'] ?? null) === ''
					&& $data['authConfig']['host'] === 'db.example.org'
					&& $data['authConfig']['credential'] === '00000000-0000-0000-0000-000000000000';
			}))
			->willReturn($source);

		$this->controller->create();
	}

	/**
	 * Custody: update() strips a submitted plaintext password/secret the same
	 * way create() does (D1).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testUpdateStripsSubmittedDatabasePassword(): void {
		$source = new Source();
		$this->request->method('getParams')->willReturn([
			'type' => 'database',
			'authConfig' => [
				'host' => 'db.example.org',
				'password' => 'YOUR_PASSWORD_HERE',
			],
		]);
		$this->sourceMapper->expects($this->once())
			->method('updateFromArray')
			->with(
				$this->equalTo(7),
				$this->callback(function ($data) {
					return isset($data['authConfig']['password']) === false
						&& $data['authConfig']['host'] === 'db.example.org';
				})
			)
			->willReturn($source);

		$this->controller->update(7);
	}

	public function testCreateWithEmptyParams(): void {
		$source = new Source();
		$this->request->method('getParams')->willReturn([]);
		$this->sourceMapper->expects($this->once())
			->method('createFromArray')
			->with($this->equalTo([]))
			->willReturn($source);

		$result = $this->controller->create();

		$this->assertSame(Http::STATUS_OK, $result->getStatus());
	}

	public function testCreateWithMultipleInternalParams(): void {
		$source = new Source();
		$this->request->method('getParams')->willReturn([
			'_route' => 'openregister.sources.create',
			'_method' => 'POST',
			'_token' => 'csrf-token',
			'name' => 'My Source',
			'type' => 'api',
		]);
		$this->sourceMapper->expects($this->once())
			->method('createFromArray')
			->with($this->callback(function ($data) {
				return count($data) === 2
					&& $data['name'] === 'My Source'
					&& $data['type'] === 'api';
			}))
			->willReturn($source);

		$this->controller->create();
	}

	// -------------------------------------------------------------------------
	// update()
	// -------------------------------------------------------------------------

	public function testUpdateReturnsUpdatedSource(): void {
		$source = new Source();
		$this->request->method('getParams')->willReturn(['name' => 'updated']);
		$this->sourceMapper->method('updateFromArray')->willReturn($source);

		$result = $this->controller->update(1);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(Http::STATUS_OK, $result->getStatus());
	}

	public function testUpdateRemovesImmutableFields(): void {
		$source = new Source();
		$this->request->method('getParams')->willReturn([
			'id' => 1,
			'organisation' => 'org1',
			'owner' => 'user1',
			'created' => '2024-01-01',
			'name' => 'updated',
		]);
		$this->sourceMapper->expects($this->once())
			->method('updateFromArray')
			->with(
				$this->equalTo(1),
				$this->callback(function ($data) {
					return !isset($data['id'])
						&& !isset($data['organisation'])
						&& !isset($data['owner'])
						&& !isset($data['created'])
						&& isset($data['name'])
						&& $data['name'] === 'updated';
				})
			)
			->willReturn($source);

		$this->controller->update(1);
	}

	public function testUpdateRemovesInternalParams(): void {
		$source = new Source();
		$this->request->method('getParams')->willReturn([
			'_route' => 'openregister.sources.update',
			'_limit' => '10',
			'name' => 'updated',
		]);
		$this->sourceMapper->expects($this->once())
			->method('updateFromArray')
			->with(
				$this->equalTo(5),
				$this->callback(function ($data) {
					return !isset($data['_route'])
						&& !isset($data['_limit'])
						&& $data['name'] === 'updated';
				})
			)
			->willReturn($source);

		$this->controller->update(5);
	}

	public function testUpdateWithEmptyParams(): void {
		$source = new Source();
		$this->request->method('getParams')->willReturn([]);
		$this->sourceMapper->expects($this->once())
			->method('updateFromArray')
			->with(
				$this->equalTo(10),
				$this->equalTo([])
			)
			->willReturn($source);

		$result = $this->controller->update(10);

		$this->assertSame(Http::STATUS_OK, $result->getStatus());
	}

	public function testUpdatePassesCorrectId(): void {
		$source = new Source();
		$this->request->method('getParams')->willReturn(['name' => 'test']);
		$this->sourceMapper->expects($this->once())
			->method('updateFromArray')
			->with($this->equalTo(42), $this->anything())
			->willReturn($source);

		$this->controller->update(42);
	}

	// -------------------------------------------------------------------------
	// patch()
	// -------------------------------------------------------------------------

	public function testPatchDelegatesToUpdate(): void {
		$source = new Source();
		$this->request->method('getParams')->willReturn(['name' => 'patched']);
		$this->sourceMapper->method('updateFromArray')->willReturn($source);

		$result = $this->controller->patch(1);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(Http::STATUS_OK, $result->getStatus());
	}

	public function testPatchRemovesImmutableFieldsLikeUpdate(): void {
		$source = new Source();
		$this->request->method('getParams')->willReturn([
			'id' => 99,
			'organisation' => 'org-test',
			'owner' => 'someone',
			'created' => '2025-06-01',
			'description' => 'patched desc',
		]);
		$this->sourceMapper->expects($this->once())
			->method('updateFromArray')
			->with(
				$this->equalTo(7),
				$this->callback(function ($data) {
					return !isset($data['id'])
						&& !isset($data['organisation'])
						&& !isset($data['owner'])
						&& !isset($data['created'])
						&& $data['description'] === 'patched desc';
				})
			)
			->willReturn($source);

		$this->controller->patch(7);
	}

	// -------------------------------------------------------------------------
	// destroy()
	// -------------------------------------------------------------------------

	public function testDestroyReturnsEmptyOnSuccess(): void {
		$source = new Source();
		$this->sourceMapper->method('find')->with(1)->willReturn($source);
		$this->sourceMapper->expects($this->once())
			->method('delete')
			->with($source);

		$result = $this->controller->destroy(1);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(Http::STATUS_OK, $result->getStatus());
		$this->assertSame([], $result->getData());
	}

	public function testDestroyCallsFindWithCorrectId(): void {
		$source = new Source();
		$this->sourceMapper->expects($this->once())
			->method('find')
			->with($this->equalTo(55))
			->willReturn($source);

		$this->controller->destroy(55);
	}

	public function testDestroyCallsDeleteWithFoundSource(): void {
		$source = new Source();
		$this->sourceMapper->method('find')->willReturn($source);
		$this->sourceMapper->expects($this->once())
			->method('delete')
			->with($this->identicalTo($source));

		$this->controller->destroy(1);
	}

	// -------------------------------------------------------------------------
	// testConnection() + introspect() — dbal-virtual-registers contract
	// (route: POST /api/sources/{id}/test-connection, /api/sources/{id}/introspect)
	// -------------------------------------------------------------------------

	/**
	 * Build a `type: database` Source pointing at a freshly built SQLite fixture.
	 *
	 * @return Source The database source.
	 */
	private function sqliteDatabaseSource(): Source {
		require_once __DIR__ . '/../../fixtures/dbal/build-permits-sqlite.php';
		$path = sys_get_temp_dir() . '/or-sources-controller-test-permits.sqlite';
		build_permits_sqlite(path: $path);

		$source = new Source();
		$source->setId(5);
		$source->setUuid('00000000-0000-0000-0000-000000000000');
		$source->setType('database');
		$source->setAuthConfig(['driver' => 'pdo_sqlite', 'path' => $path]);

		return $source;
	}

	/**
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testTestConnectionRequiresAdmin(): void {
		$controller = $this->buildController(isAdmin: false);

		$result = $controller->testConnection(1);

		$this->assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
	}

	/**
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testTestConnectionReturns404WhenSourceMissing(): void {
		$this->sourceMapper->method('find')->willThrowException(new DoesNotExistException('nope'));

		$result = $this->controller->testConnection(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
	}

	/**
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testTestConnectionRejectsNonDatabaseSource(): void {
		$source = new Source();
		$source->setType('internal');
		$this->sourceMapper->method('find')->willReturn($source);

		$result = $this->controller->testConnection(1);

		$this->assertSame(422, $result->getStatus());
	}

	/**
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testTestConnectionSucceedsAgainstSqliteFixtureWithoutLeakingSecrets(): void {
		$this->sourceMapper->method('find')->willReturn($this->sqliteDatabaseSource());

		$result = $this->controller->testConnection(5);

		$this->assertSame(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertStringNotContainsString('password', strtolower(json_encode($data)));
	}

	/**
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testTestConnectionUnreachableSourceReturns503NotBare500(): void {
		$source = new Source();
		$source->setId(6);
		$source->setType('database');
		// A directory is not a valid SQLite database file — connection fails.
		$source->setAuthConfig(['driver' => 'pdo_sqlite', 'path' => sys_get_temp_dir()]);
		$this->sourceMapper->method('find')->willReturn($source);

		$result = $this->controller->testConnection(6);

		$this->assertContains($result->getStatus(), [502, 503]);
		$data = $result->getData();
		$this->assertArrayHasKey('error', $data);
		$this->assertStringNotContainsString('password', strtolower(json_encode($data)));
	}

	/**
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testIntrospectRequiresAdmin(): void {
		$controller = $this->buildController(isAdmin: false);

		$result = $controller->introspect(1);

		$this->assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
	}

	/**
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testIntrospectReturns404WhenSourceMissing(): void {
		$this->sourceMapper->method('find')->willThrowException(new DoesNotExistException('nope'));

		$result = $this->controller->introspect(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
	}

	/**
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testIntrospectRejectsNonDatabaseSource(): void {
		$source = new Source();
		$source->setType('internal');
		$this->sourceMapper->method('find')->willReturn($source);

		$result = $this->controller->introspect(1);

		$this->assertSame(422, $result->getStatus());
	}

	/**
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testIntrospectReturnsSummaryShape(): void {
		$source = $this->sqliteDatabaseSource();
		$this->sourceMapper->method('find')->willReturn($source);
		$this->introspectionService->expects($this->once())
			->method('introspect')
			->with($this->identicalTo($source))
			->willReturn(
				[
					'register' => 12,
					'created' => ['applicants', 'permit_types', 'permits', 'active_permits'],
					'updated' => [],
					'drift' => [],
				]
			);

		$result = $this->controller->introspect(5);

		$this->assertSame(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertSame(12, $data['register']);
		$this->assertContains('permits', $data['created']);
		$this->assertSame([], $data['drift']);
		$this->assertStringNotContainsString('password', strtolower(json_encode($data)));
	}

	/**
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testIntrospectUnreachableSourceReturns503(): void {
		$source = $this->sqliteDatabaseSource();
		$this->sourceMapper->method('find')->willReturn($source);
		$this->introspectionService->method('introspect')->willThrowException(
			new \OCA\OpenRegister\Service\Dbal\DbalConnectionException('unreachable')
		);

		$result = $this->controller->introspect(5);

		$this->assertSame(503, $result->getStatus());
	}

	/**
	 * Build the controller with the sync collaborators under test control.
	 *
	 * @param bool $isAdmin Whether the acting user is an admin.
	 * @param SourceFetcherRegistry $fetcherRegistry Resolves the transport for a source type.
	 * @param HarvestPipelineService $pipeline Harvest pipeline orchestrator.
	 *
	 * @return SourcesController The controller under test.
	 */
	private function buildSyncController(
		bool $isAdmin,
		SourceFetcherRegistry $fetcherRegistry,
		HarvestPipelineService $pipeline,
	): SourcesController {
		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->sourceMapper = $this->createMock(SourceMapper::class);
		$this->introspectionService = $this->createMock(DatabaseIntrospectionService::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(\OCP\IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(\OCP\IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		$credentialStore = $this->createMock(CredentialStore::class);
		$credentialStore->method('get')->willReturn(null);

		return new SourcesController(
			'openregister',
			$this->request,
			$this->config,
			$this->sourceMapper,
			$l10n,
			$userSession,
			$groupManager,
			$this->createMock(\OCP\Security\ICrypto::class),
			$fetcherRegistry,
			$pipeline,
			new DbalConnectionFactory(credentialStore: $credentialStore, logger: new NullLogger()),
			$this->introspectionService,
			new NullLogger()
		);
	}

	public function testSyncNowIsRefusedForANonAdmin(): void {
		$pipeline = $this->createMock(HarvestPipelineService::class);
		$pipeline->expects($this->never())->method('run');

		$controller = $this->buildSyncController(
			isAdmin: false,
			fetcherRegistry: $this->createMock(SourceFetcherRegistry::class),
			pipeline: $pipeline
		);

		$result = $controller->syncNow(5);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(403, $result->getStatus());
		$this->assertSame('Admin privileges required', $result->getData()['error']);
	}

	public function testSyncNowReturns404ForASourceOutsideTheOrganisation(): void {
		$pipeline = $this->createMock(HarvestPipelineService::class);
		$pipeline->expects($this->never())->method('run');

		$controller = $this->buildSyncController(
			isAdmin: true,
			fetcherRegistry: $this->createMock(SourceFetcherRegistry::class),
			pipeline: $pipeline
		);

		// SourceMapper::find() applies the active-organisation filter, so a
		// foreign tenant's id is indistinguishable from a missing one.
		$this->sourceMapper->method('find')->willThrowException(new DoesNotExistException('nope'));

		$result = $controller->syncNow(5);

		$this->assertSame(404, $result->getStatus());
	}

	public function testSyncNowReturns422WhenNoTransportSupportsTheSourceType(): void {
		$registry = $this->createMock(SourceFetcherRegistry::class);
		$registry->method('get')->willReturn(null);

		$pipeline = $this->createMock(HarvestPipelineService::class);
		$pipeline->expects($this->never())->method('run');

		$controller = $this->buildSyncController(
			isAdmin: true,
			fetcherRegistry: $registry,
			pipeline: $pipeline
		);

		$source = new Source();
		$source->setId(5);
		$source->setType('exotic');
		$this->sourceMapper->method('find')->willReturn($source);

		$result = $controller->syncNow(5);

		$this->assertSame(422, $result->getStatus());
		$this->assertSame('No sync transport available for this source type', $result->getData()['error']);
	}

	public function testSyncNowRunsThePipelineAndRecordsTheOutcome(): void {
		$fetcher = $this->createMock(\OCA\OpenRegister\Service\Sync\SourceFetcherInterface::class);

		$registry = $this->createMock(SourceFetcherRegistry::class);
		$registry->method('get')->with('api')->willReturn($fetcher);

		$pipeline = $this->createMock(HarvestPipelineService::class);
		$pipeline->expects($this->once())
			->method('run')
			->willReturn(['status' => 'success', 'imported' => 12]);

		$controller = $this->buildSyncController(
			isAdmin: true,
			fetcherRegistry: $registry,
			pipeline: $pipeline
		);

		$source = new Source();
		$source->setId(5);
		$source->setType('api');
		$this->sourceMapper->method('find')->willReturn($source);

		// The run outcome is persisted back onto the source.
		$this->sourceMapper->expects($this->once())->method('update')->with($source);

		$result = $controller->syncNow(5);

		$this->assertSame(200, $result->getStatus());
		$this->assertSame(12, $result->getData()['imported']);
		$this->assertSame('success', $source->getLastSyncStatus());
	}

	public function testSyncStatusReportsNeverForASourceThatHasNotRun(): void {
		$controller = $this->buildSyncController(
			isAdmin: true,
			fetcherRegistry: $this->createMock(SourceFetcherRegistry::class),
			pipeline: $this->createMock(HarvestPipelineService::class)
		);

		$source = new Source();
		$source->setId(5);
		$source->setUuid('src-uuid');
		$source->setSyncEnabled(true);
		$source->setSyncInterval(3600);
		$this->sourceMapper->method('find')->willReturn($source);

		$result = $controller->syncStatus(5);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(200, $result->getStatus());
		$data = $result->getData();
		$this->assertSame('src-uuid', $data['uuid']);
		$this->assertSame('never', $data['status']);
		$this->assertNull($data['lastSyncDate']);
		$this->assertSame(3600, $data['syncInterval']);
		// No credentials are ever exposed on this read.
		$this->assertArrayNotHasKey('authConfig', $data);
		$this->assertArrayNotHasKey('databaseUrl', $data);
	}

	public function testSyncStatusReturns404ForASourceOutsideTheOrganisation(): void {
		$controller = $this->buildSyncController(
			isAdmin: true,
			fetcherRegistry: $this->createMock(SourceFetcherRegistry::class),
			pipeline: $this->createMock(HarvestPipelineService::class)
		);

		$this->sourceMapper->method('find')->willThrowException(new DoesNotExistException('nope'));

		$result = $controller->syncStatus(5);

		$this->assertSame(404, $result->getStatus());
	}
}
