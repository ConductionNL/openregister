<?php

/**
 * ObjectsControllerCountsTest
 *
 * Unit tests for the batched object-count endpoint POST /api/objects/counts.
 * Covers: multi-pair ordered response, dedupe-runs-once, filter parity,
 * empty request, unauthorized/unknown-pair withholding, RBAC/multitenancy
 * parity with the collection read, and the authenticated (non-public)
 * auth posture.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Unit tests for ObjectsController::counts().
 *
 * @package Unit\Controller
 */
class ObjectsControllerCountsTest extends TestCase {
	private ObjectsController $controller;
	private IRequest&MockObject $request;
	private IAppConfig&MockObject $config;
	private IAppManager&MockObject $appManager;
	private ContainerInterface&MockObject $container;
	private MagicMapper&MockObject $objectMapper;
	private RegisterMapper&MockObject $registerMapper;
	private SchemaMapper&MockObject $schemaMapper;
	private AuditTrailMapper&MockObject $auditTrailMapper;
	private ObjectService&MockObject $objectService;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private ExportService&MockObject $exportService;
	private ImportService&MockObject $importService;
	private WebhookService&MockObject $webhookService;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->exportService = $this->createMock(ExportService::class);
		$this->importService = $this->createMock(ImportService::class);
		$this->webhookService = $this->createMock(WebhookService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// resolveRegisterSchemaRef() reuses the entities resolved by
		// ObjectService::setRegister()/setSchema(); the ObjectService mock
		// returns null from the entity getters by default, so the
		// register/schema entities stay null — this forces the
		// database-backed (non-magic) count path through
		// searchObjectsPaginated, which these tests mock.
		$this->controller = ObjectsControllerFactory::create(
			'openregister',
			$this->request,
			$this->config,
			$this->appManager,
			$this->container,
			$this->registerMapper,
			$this->schemaMapper,
			$this->auditTrailMapper,
			$this->objectService,
			$this->userSession,
			$this->groupManager,
			$this->exportService,
			$this->importService,
			$this->webhookService,
			$this->logger
		);
	}//end setUp()

	/**
	 * Make register/schema resolution succeed (non-magic path).
	 */
	private function stubResolution(): void {
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('getSchema')->willReturn(2);
	}

	private function setupNonAdminUser(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn(['users']);
	}

	private function setupAdminUser(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn(['admin']);
	}

	/**
	 * Multiple pairs return in one response, in order; identical triples
	 * are deduped so the aggregate runs once and duplicates share the count.
	 */
	public function testMultiplePairsOrderedAndDeduped(): void {
		$this->setupNonAdminUser();
		$this->stubResolution();
		$this->request->method('getParams')->willReturn([
			'counts' => [
				['register' => 'R', 'schema' => 'A'],
				['register' => 'R', 'schema' => 'B'],
				['register' => 'R', 'schema' => 'A'],
			],
		]);

		$this->objectService->method('buildSearchQuery')->willReturn([]);

		// Distinct triples: {R,A} then {R,B}. {R,A} must compute once (first
		// call → total 5) and be reused for the third entry; {R,B} is the
		// second call → total 9. Exactly two aggregates run.
		$this->objectService->expects($this->exactly(2))
			->method('searchObjectsPaginated')
			->willReturnOnConsecutiveCalls(
				['total' => 5],
				['total' => 9]
			);

		$response = $this->controller->counts($this->objectService);

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertCount(3, $data['results']);

		// Order preserved, registers/schemas echoed.
		$this->assertSame(['register' => 'R', 'schema' => 'A', 'count' => 5], $data['results'][0]);
		$this->assertSame(['register' => 'R', 'schema' => 'B', 'count' => 9], $data['results'][1]);
		// Deduped: the second {R,A} shares the first's count without recomputing.
		$this->assertSame(['register' => 'R', 'schema' => 'A', 'count' => 5], $data['results'][2]);
	}

	/**
	 * A per-entry filter is forwarded to the same query builder the
	 * collection read uses, and the count is read at _limit=1.
	 */
	public function testFilterIsHonouredPerEntry(): void {
		$this->setupNonAdminUser();
		$this->stubResolution();
		$this->request->method('getParams')->willReturn([
			'counts' => [
				['register' => 'R', 'schema' => 'A', 'filter' => ['status' => 'open']],
			],
		]);

		// Assert the filter reaches buildSearchQuery unchanged.
		$this->objectService->method('buildSearchQuery')
			->willReturnCallback(function (array $requestParams) {
				$this->assertSame(['status' => 'open'], $requestParams);
				return $requestParams;
			});

		// Assert the count read is scoped at _limit=1 like ?_limit=1&status=open.
		$this->objectService->method('searchObjectsPaginated')
			->willReturnCallback(function (array $query) {
				$this->assertSame(1, $query['_limit']);
				$this->assertSame('open', $query['status']);
				return ['total' => 3];
			});

		$response = $this->controller->counts($this->objectService);

		$data = $response->getData();
		$this->assertSame(3, $data['results'][0]['count']);
	}

	/**
	 * An empty counts array returns { results: [] } with success.
	 */
	public function testEmptyRequestReturnsEmptyResults(): void {
		$this->request->method('getParams')->willReturn(['counts' => []]);

		// No count path should run for an empty request.
		$this->objectService->expects($this->never())->method('searchObjectsPaginated');

		$response = $this->controller->counts($this->objectService);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => []], $response->getData());
	}

	/**
	 * A missing counts key also returns { results: [] } with success.
	 */
	public function testMissingCountsKeyReturnsEmptyResults(): void {
		$this->request->method('getParams')->willReturn([]);

		$response = $this->controller->counts($this->objectService);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => []], $response->getData());
	}

	/**
	 * A pair that cannot be resolved is withheld as count:null and NEVER
	 * triggers a raw/unfiltered count — no existence leak.
	 */
	public function testUnknownOrUnauthorizedPairIsWithheld(): void {
		$this->setupNonAdminUser();

		// Resolution fails → RegisterNotFoundException inside countPairScoped.
		$this->objectService->method('setRegister')
			->willThrowException(new DoesNotExistException('nope'));

		$this->request->method('getParams')->willReturn([
			'counts' => [
				['register' => 'secret', 'schema' => 'restricted'],
			],
		]);

		// The RBAC-scoped count path must never run for a withheld pair.
		$this->objectService->expects($this->never())->method('searchObjectsPaginated');

		$response = $this->controller->counts($this->objectService);

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertNull($data['results'][0]['count']);
		$this->assertSame('secret', $data['results'][0]['register']);
		$this->assertSame('restricted', $data['results'][0]['schema']);
	}

	/**
	 * RBAC + multitenancy parity: a non-admin caller's count is produced with
	 * _rbac=true AND _multitenancy=true — the same scoping index() applies —
	 * so a non-admin cannot obtain a count for objects they cannot list.
	 */
	public function testNonAdminCountIsRbacAndMultitenancyScoped(): void {
		$this->setupNonAdminUser();
		$this->stubResolution();
		$this->request->method('getParams')->willReturn([
			'counts' => [['register' => 'R', 'schema' => 'A']],
		]);
		$this->objectService->method('buildSearchQuery')->willReturn([]);

		$captured = [];
		$this->objectService->method('searchObjectsPaginated')
			->willReturnCallback(function (array $query, bool $rbac, bool $multi) use (&$captured) {
				$captured['rbac'] = $rbac;
				$captured['multi'] = $multi;
				return ['total' => 7];
			});

		$response = $this->controller->counts($this->objectService);

		$this->assertTrue($captured['rbac'], 'non-admin count must apply RBAC');
		$this->assertTrue($captured['multi'], 'non-admin count must apply multitenancy');
		$this->assertSame(7, $response->getData()['results'][0]['count']);
	}

	/**
	 * Admin parity: an admin caller bypasses RBAC/multitenancy exactly like
	 * index() (rbac=false, multi=false).
	 */
	public function testAdminCountBypassesRbacLikeIndex(): void {
		$this->setupAdminUser();
		$this->stubResolution();
		$this->request->method('getParams')->willReturn([
			'counts' => [['register' => 'R', 'schema' => 'A']],
		]);
		$this->objectService->method('buildSearchQuery')->willReturn([]);

		$captured = [];
		$this->objectService->method('searchObjectsPaginated')
			->willReturnCallback(function (array $query, bool $rbac, bool $multi) use (&$captured) {
				$captured['rbac'] = $rbac;
				$captured['multi'] = $multi;
				return ['total' => 42];
			});

		$this->controller->counts($this->objectService);

		$this->assertFalse($captured['rbac'], 'admin count must bypass RBAC');
		$this->assertFalse($captured['multi'], 'admin count must bypass multitenancy');
	}

	/**
	 * A malformed entry (missing schema) rejects the whole batch with 400.
	 */
	public function testMalformedEntryRejectedWith400(): void {
		$this->request->method('getParams')->willReturn([
			'counts' => [
				['register' => 'R', 'schema' => 'A'],
				['register' => 'R'],
			],
		]);

		$response = $this->controller->counts($this->objectService);

		$this->assertSame(400, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}

	/**
	 * An entry without a register is malformed too.
	 */
	public function testAnEntryWithoutARegisterIsMalformed(): void {
		$this->request->method('getParams')->willReturn(['counts' => [['schema' => 'A']]]);
		$this->objectService->expects($this->never())->method('buildSearchQuery');

		$this->assertSame(400, $this->controller->counts($this->objectService)->getStatus());
	}

	/**
	 * The same pair under two filters is two counts, not one shared count.
	 */
	public function testTwoFiltersOnOnePairAreCountedApart(): void {
		$this->setupNonAdminUser();
		$this->stubResolution();
		$this->request->method('getParams')->willReturn([
			'counts' => [
				['register' => 'R', 'schema' => 'A', 'filter' => ['status' => 'open']],
				['register' => 'R', 'schema' => 'A', 'filter' => ['status' => 'closed']],
			],
		]);
		$this->objectService->method('buildSearchQuery')->willReturnArgument(0);
		$this->objectService->method('searchObjectsPaginated')->willReturnCallback(
			static fn (array $query): array => ['total' => ($query['status'] === 'open' ? 3 : 8)]
		);

		$results = $this->controller->counts($this->objectService)->getData()['results'];

		$this->assertSame([3, 8], array_column($results, 'count'));
	}

	/**
	 * An entry whose filter is no object is malformed, and the batch is refused before anything runs.
	 */
	public function testAFilterThatIsNoObjectIsMalformed(): void {
		$this->request->method('getParams')->willReturn(['counts' => [['register' => 'R', 'schema' => 'A', 'filter' => 'x']]]);
		$this->objectService->expects($this->never())->method('buildSearchQuery');

		$this->assertSame(400, $this->controller->counts($this->objectService)->getStatus());
	}

	/**
	 * A magic-mapped pair for the given schema configuration, with a mapper that records the query.
	 *
	 * @param array $configuration The schema configuration.
	 * @param array $queries       Receives the queries the magic mapper was asked.
	 */
	private function stubMagicPair(array $configuration, array &$queries): void {
		$this->stubResolution();
		$register = $this->createPartialMock(\OCA\OpenRegister\Db\Register::class, ['isMagicMappingEnabledForSchema']);
		$register->method('isMagicMappingEnabledForSchema')->willReturn(true);
		$schema = new \OCA\OpenRegister\Db\Schema();
		$schema->setId(2);
		$schema->setSlug('a');
		$schema->setConfiguration($configuration);
		$this->objectService->method('getCurrentRegisterEntity')->willReturn($register);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schema);
		$this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 5, '_offset' => 1, '_page' => 2, 'status' => 'open']);

		$mapper = $this->createMock(\OCA\OpenRegister\Db\MagicMapperInterface::class);
		$mapper->method('countObjectsInRegisterSchemaTable')->willReturnCallback(
			static function (array $query) use (&$queries): int {
				$queries[] = $query;
				return 7;
			}
		);
		$this->container->method('get')->willReturn($mapper);
		$this->request->method('getParams')->willReturn(['counts' => [['register' => 'R', 'schema' => 'A']]]);
	}

	/**
	 * A magic-mapped pair counts in its table, with the caller's scope threaded into the query.
	 */
	public function testMagicPairCountCarriesTheCallersScope(): void {
		$this->setupNonAdminUser();
		$queries = [];
		$this->stubMagicPair([], $queries);

		$response = $this->controller->counts($this->objectService);

		$this->assertSame(7, $response->getData()['results'][0]['count']);
		$this->assertSame(
			[['status' => 'open', '_rbac' => true, '_multitenancy' => true, '_multitenancy_explicit' => false]],
			$queries
		);
	}

	/**
	 * An administrator's magic count runs unscoped, like the collection read.
	 */
	public function testMagicPairCountForAnAdministrator(): void {
		$this->setupAdminUser();
		$queries = [];
		$this->stubMagicPair([], $queries);

		$this->controller->counts($this->objectService);

		$this->assertFalse($queries[0]['_rbac']);
		$this->assertFalse($queries[0]['_multitenancy']);
	}

	/**
	 * A schema served by an object source is counted through the paginated read, not its table.
	 */
	public function testAnObjectSourceSchemaIsNotCountedInItsTable(): void {
		$this->setupNonAdminUser();
		$queries = [];
		$this->stubMagicPair(['x-openregister-object-source' => ['provider' => 'p']], $queries);
		$this->objectService->method('searchObjectsPaginated')->willReturn(['total' => 3]);

		$response = $this->controller->counts($this->objectService);

		$this->assertSame(3, $response->getData()['results'][0]['count']);
		$this->assertSame([], $queries);
	}

	/**
	 * A non-array counts value is rejected with 400.
	 */
	public function testNonArrayCountsRejectedWith400(): void {
		$this->request->method('getParams')->willReturn(['counts' => 'garbage']);

		$response = $this->controller->counts($this->objectService);

		$this->assertSame(400, $response->getStatus());
	}

	/**
	 * Auth posture parity: counts() is authenticated-only. Its docblock
	 * carries @NoAdminRequired and MUST NOT carry @PublicPage, so the
	 * security middleware rejects an unauthenticated request exactly as it
	 * would a non-public object read.
	 */
	public function testCountsAuthPostureIsAuthenticatedNotPublic(): void {
		$reflection = new ReflectionMethod(ObjectsController::class, 'counts');
		$doc = (string)$reflection->getDocComment();

		$this->assertStringContainsString('@NoAdminRequired', $doc);
		$this->assertStringNotContainsString('@PublicPage', $doc);
	}
}
