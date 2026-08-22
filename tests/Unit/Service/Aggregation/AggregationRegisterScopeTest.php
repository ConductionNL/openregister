<?php

/**
 * Unit tests for register-scoped schema resolution on the aggregation endpoints.
 *
 * `GET /apps/openregister/api/objects/aggregations/{register}/{schema}/…` has always
 * carried a `{register}` path segment and, until this change, never used it: both
 * `AggregationRunner::run()` and `AggregationRunner::runAdhocByRef()` opened with a
 * GLOBAL `SchemaMapper::find($schemaRef)` and loaded the register afterwards, by
 * which point the schema had already been matched against every register and every
 * app on the instance.
 *
 * Measured on the shared dev instance 2026-08-21: a dashboard `stat` widget
 * aggregated ANOTHER APP's rows — `TimeEntry` resolved to planix's schema 161
 * instead of hrmq's 9466, and `Expense` to pipelinq's 507 instead of hrmq's 5026.
 * Single-app instances and CI cannot reproduce it, which is why these tests pin the
 * boundary rather than the numbers.
 *
 * Mirrors SchemasControllerShowRegisterScopeTest (#2694): scoped hit, scoped miss
 * with the same slug existing elsewhere, unknown register, and the control proving
 * the global path is unreachable once a register is named — and still reachable for
 * the register-less caller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Aggregation\AggregationRunner
 * @covers \OCA\OpenRegister\Service\RegisterScopedSchemaResolver
 */
class AggregationRegisterScopeTest extends TestCase {

	private MagicMapper&MockObject $magicMapper;

	private RegisterMapper&MockObject $registerMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private PlaceholderResolver $placeholderResolver;

	private IDBConnection&MockObject $db;

	private AggregationCache&MockObject $cache;

	private PermissionHandler&MockObject $permissionHandler;

	private IUserSession&MockObject $userSession;

	private OrganisationService&MockObject $organisationService;

	private AggregationRunner $runner;

	protected function setUp(): void {
		parent::setUp();

		$this->magicMapper         = $this->createMock(MagicMapper::class);
		$this->registerMapper      = $this->createMock(RegisterMapper::class);
		$this->schemaMapper        = $this->createMock(SchemaMapper::class);
		$this->db                  = $this->createMock(IDBConnection::class);
		$this->cache               = $this->createMock(AggregationCache::class);
		$this->permissionHandler   = $this->createMock(PermissionHandler::class);
		$this->userSession         = $this->createMock(IUserSession::class);
		$this->organisationService = $this->createMock(OrganisationService::class);

		// PlaceholderResolver is declared `final` and cannot be mocked.
		$this->placeholderResolver = new PlaceholderResolver($this->userSession);

		$this->userSession->method('getUser')->willReturn(null);
		$this->cache->method('get')->willReturn(null);
		$this->cache->method('set');
		$this->organisationService->method('getActiveOrganisation')->willReturn(null);
		$this->permissionHandler->method('hasPermission')->willReturn(true);

		// Non-Postgres platform forces the PHP fallback path, so the aggregate
		// completes without a real database.
		$platform = new class {
			public function __toString(): string {
				return 'OtherPlatform';
			}
		};
		$this->db->method('getDatabasePlatform')->willReturn($platform);

		$this->runner = new AggregationRunner(
			magicMapper: $this->magicMapper,
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			placeholders: $this->placeholderResolver,
			db: $this->db,
			cache: $this->cache,
			permissionHandler: $this->permissionHandler,
			userSession: $this->userSession,
			organisationService: $this->organisationService,
			translationHandler: $this->createMock(TranslationHandler::class),
			languageService: $this->createMock(LanguageService::class)
		);
	}//end setUp()


	/**
	 * Build a persisted-looking schema.
	 *
	 * @param int    $id            The schema id.
	 * @param string $slug          The schema slug.
	 * @param array  $configuration Optional schema configuration (aggregation annotation).
	 *
	 * @return Schema The schema.
	 */
	private function schemaWithId(int $id, string $slug = 'TimeEntry', array $configuration = []): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setTitle('TimeEntry');
		if ($configuration !== []) {
			$schema->setConfiguration($configuration);
		}

		return $schema;
	}//end schemaWithId()


	/**
	 * Build a register carrying the given schema ids.
	 *
	 * @param int   $id        The register id.
	 * @param array $schemaIds The schema ids it carries.
	 *
	 * @return Register The register.
	 */
	private function registerWith(int $id, array $schemaIds): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug('hrmq');
		$register->setSchemas($schemaIds);

		return $register;
	}//end registerWith()


	/**
	 * Build a stub object row for the PHP fallback path.
	 *
	 * @param array $data The object data.
	 *
	 * @return ObjectEntity&MockObject The stub row.
	 */
	private function row(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);

		return $entity;
	}//end row()


	/**
	 * Scoped hit: the ad-hoc surface resolves the schema ref among the named
	 * register's schemas only.
	 *
	 * `SchemaMapper::find()` — the global resolver — must never run. On the live
	 * instance it is the call that returned planix's schema 161 for hrmq's
	 * `TimeEntry`.
	 *
	 * @return void
	 */
	public function testAdhocResolvesSchemaWithinTheNamedRegisterOnly(): void {
		$this->registerMapper->expects($this->once())
			->method('find')
			->with('hrmq', $this->anything(), $this->isFalse())
			->willReturn($this->registerWith(id: 12, schemaIds: [9466, 9467]));

		$this->schemaMapper->expects($this->once())
			->method('findInIds')
			->with('TimeEntry', [9466, 9467])
			->willReturn($this->schemaWithId(id: 9466));
		$this->schemaMapper->expects($this->never())->method('find');

		$this->magicMapper->method('findAllInRegisterSchemaTable')
			->willReturn([$this->row(['hours' => 3]), $this->row(['hours' => 4])]);

		$result = $this->runner->runAdhocByRef(
			registerRef: 'hrmq',
			schemaRef: 'TimeEntry',
			query: AggregationQuery::create(metric: 'count')
		);

		$this->assertSame(2, $result['value']);
	}//end testAdhocResolvesSchemaWithinTheNamedRegisterOnly()


	/**
	 * Scoped hit on the named-annotation surface: `run()` scopes too, so the
	 * `x-openregister-aggregations` annotation and the RBAC `list` gate are read
	 * off the register's OWN schema and not a same-slug schema from another app.
	 *
	 * @return void
	 */
	public function testNamedAggregationResolvesSchemaWithinTheNamedRegisterOnly(): void {
		$schema = $this->schemaWithId(
			id: 9466,
			configuration: ['x-openregister-aggregations' => ['totalCount' => ['select' => 'count']]]
		);

		$this->registerMapper->method('find')->willReturn($this->registerWith(id: 12, schemaIds: [9466]));
		$this->schemaMapper->expects($this->once())
			->method('findInIds')
			->with('TimeEntry', [9466])
			->willReturn($schema);
		$this->schemaMapper->expects($this->never())->method('find');

		$this->magicMapper->method('findAllInRegisterSchemaTable')->willReturn([$this->row(['hours' => 3])]);

		$result = $this->runner->run(registerRef: 'hrmq', schemaRef: 'TimeEntry', name: 'totalCount');

		$this->assertSame(1, $result['value']);
	}//end testNamedAggregationResolvesSchemaWithinTheNamedRegisterOnly()


	/**
	 * Scoped miss: a slug carried elsewhere on the instance but not by the named
	 * register is refused with the boundary diagnosis, not resolved globally.
	 *
	 * This is the exact live shape — three schemas carry `TimeEntry`, the caller
	 * named hrmq's register, and hrmq's schema is not among the ids that register
	 * carries. Serving one of the other two is the defect.
	 *
	 * @return void
	 */
	public function testSlugCarriedElsewhereButNotByTheRegisterIsRefused(): void {
		$this->registerMapper->method('find')->willReturn($this->registerWith(id: 12, schemaIds: [7, 8]));

		$this->schemaMapper->method('findInIds')->willReturn(null);
		$this->schemaMapper->method('countBySlug')->willReturn(3);
		$this->schemaMapper->expects($this->never())->method('find');

		$caught = null;
		try {
			$this->runner->runAdhocByRef(
				registerRef: 'hrmq',
				schemaRef: 'TimeEntry',
				query: AggregationQuery::create(metric: 'count')
			);
		} catch (RuntimeException $e) {
			$caught = $e;
		}

		$this->assertInstanceOf(
			RuntimeException::class,
			$caught,
			'A scoped miss MUST refuse; AggregationController maps RuntimeException to HTTP 404'
		);
		$message = $caught->getMessage();
		$this->assertStringContainsString('is not carried by register "hrmq" (id 12)', $message);
		$this->assertStringContainsString('3 schema(s) elsewhere', $message);
		$this->assertStringContainsString('naming a register makes it a boundary', $message);
		$this->assertStringContainsString('occ openregister:registers:relink-schemas', $message);
	}//end testSlugCarriedElsewhereButNotByTheRegisterIsRefused()


	/**
	 * An unknown register is a refusal naming the register — never a silent
	 * fallback to global resolution, which would serve a schema from outside the
	 * boundary the caller explicitly named.
	 *
	 * @return void
	 */
	public function testUnknownRegisterIsRefusedInsteadOfFallingBackGlobally(): void {
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('nope'));

		$this->schemaMapper->expects($this->never())->method('find');
		$this->schemaMapper->expects($this->never())->method('findInIds');

		$caught = null;
		try {
			$this->runner->runAdhocByRef(
				registerRef: 'no-such-register',
				schemaRef: 'TimeEntry',
				query: AggregationQuery::create(metric: 'count')
			);
		} catch (RuntimeException $e) {
			$caught = $e;
		}

		$this->assertInstanceOf(RuntimeException::class, $caught);
		$message = $caught->getMessage();
		$this->assertStringContainsString("Register not found: 'no-such-register'", $message);
		$this->assertStringContainsString('naming a register makes it a boundary', $message);
	}//end testUnknownRegisterIsRefusedInsteadOfFallingBackGlobally()


	/**
	 * A numeric schema id NOT in the register's list still resolves.
	 *
	 * The boundary exists because a SLUG is ambiguous instance-wide; a numeric
	 * id is unique by construction, so scoping it protects nothing and can
	 * only refuse a caller whose register carries a stale `schemas` list.
	 * Enforcing it there turned `POST /api/objects/{registerId}/{schemaId}`
	 * into a 404 for existing clients — measured in the Newman suite — which
	 * is what this test now prevents recurring.
	 *
	 * @return void
	 */
	public function testNumericSchemaIdResolvesEvenWhenTheMembershipListIsStale(): void {
		$this->registerMapper->method('find')->willReturn($this->registerWith(id: 12, schemaIds: [9466]));

		$this->schemaMapper->method('findInIds')->willReturn(null);
		$this->schemaMapper->method('countBySlug')->willReturn(0);
		$this->schemaMapper->expects($this->once())
			->method('find')
			->willReturn($this->schemaWithId(id: 161));

		$result = $this->runner->runAdhocByRef(
			registerRef: 'hrmq',
			schemaRef: '161',
			query: AggregationQuery::create(metric: 'count')
		);

		$this->assertNotNull($result);
	}//end testNumericSchemaIdResolvesEvenWhenTheMembershipListIsStale()


	/**
	 * CONTROL. The global resolver is no longer reachable when a register is
	 * named — proven negatively above with `expects($this->never())` — and is
	 * still the resolver for a caller that names none.
	 *
	 * `findSchema()` is the only surface with an optional register: the
	 * timeseries controller passes one, cross-schema `from:` refs do not.
	 *
	 * @return void
	 */
	public function testFindSchemaWithoutARegisterKeepsGlobalResolution(): void {
		$this->schemaMapper->expects($this->once())
			->method('find')
			->willReturn($this->schemaWithId(id: 161));
		$this->schemaMapper->expects($this->never())->method('findInIds');
		$this->registerMapper->expects($this->never())->method('find');

		$this->assertSame(161, $this->runner->findSchema(schemaRef: 'TimeEntry')->getId());
	}//end testFindSchemaWithoutARegisterKeepsGlobalResolution()


	/**
	 * CONTROL, the other half: the same call WITH a register never reaches the
	 * global resolver. `timeseries()` validates its field allow-list against the
	 * schema this returns, so a global resolution here would police one app's
	 * property list while the aggregate ran over another app's rows.
	 *
	 * @return void
	 */
	public function testFindSchemaWithARegisterNeverReachesGlobalResolution(): void {
		$this->registerMapper->method('find')->willReturn($this->registerWith(id: 12, schemaIds: [9466]));

		$this->schemaMapper->expects($this->once())
			->method('findInIds')
			->with('TimeEntry', [9466])
			->willReturn($this->schemaWithId(id: 9466));
		$this->schemaMapper->expects($this->never())->method('find');

		$this->assertSame(
			9466,
			$this->runner->findSchema(schemaRef: 'TimeEntry', registerRef: 'hrmq')->getId()
		);
	}//end testFindSchemaWithARegisterNeverReachesGlobalResolution()


	/**
	 * A register whose `schemas` list was lost cannot resolve anything, and says
	 * so with the repair command rather than quietly widening to the instance.
	 *
	 * This is the shape that hid the original defect: an empty scoped result is
	 * indistinguishable from "this register holds no objects".
	 *
	 * @return void
	 */
	public function testRegisterWithAnEmptySchemaListIsRefusedWithTheRepairCommand(): void {
		$this->registerMapper->method('find')->willReturn($this->registerWith(id: 12, schemaIds: []));

		$this->schemaMapper->method('findInIds')->willReturn(null);
		$this->schemaMapper->method('countBySlug')->willReturn(3);
		$this->schemaMapper->expects($this->never())->method('find');

		$caught = null;
		try {
			$this->runner->runAdhocByRef(
				registerRef: 'hrmq',
				schemaRef: 'TimeEntry',
				query: AggregationQuery::create(metric: 'count')
			);
		} catch (RuntimeException $e) {
			$caught = $e;
		}

		$this->assertInstanceOf(RuntimeException::class, $caught);
		$this->assertStringContainsString('carries no schemas at all', $caught->getMessage());
		$this->assertStringContainsString('occ openregister:registers:relink-schemas', $caught->getMessage());
	}//end testRegisterWithAnEmptySchemaListIsRefusedWithTheRepairCommand()
}//end class
