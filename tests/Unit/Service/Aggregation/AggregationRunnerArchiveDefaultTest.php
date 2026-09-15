<?php

/**
 * A tile and the list under it must count the same rows.
 *
 * The aggregation fast path bypasses MagicMapper entirely and writes its own
 * SQL, so every rule the list applies has to be reproduced here or the two
 * disagree. That has happened before on this exact path with the organisation
 * boundary: every KPI tile in the fleet under-reported and the comment beside
 * the predicate still records it. This pins the archive rule so the same thing
 * does not happen again with archived records.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
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
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * No `covers` metadata, deliberately — `beStrictAboutCoverageMetadata="true"`
 * discards the coverage of any test that touches a collaborator it did not
 * name. See AggregationRunnerNativeBucketTest and #2847.
 */
class AggregationRunnerArchiveDefaultTest extends TestCase {

	private MagicMapper&MockObject $magicMapper;

	private RegisterMapper&MockObject $registerMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private PlaceholderResolver $placeholderResolver;

	private IDBConnection&MockObject $db;

	private AggregationCache&MockObject $cache;

	private PermissionHandler&MockObject $permissionHandler;

	private IUserSession&MockObject $userSession;

	private OrganisationService&MockObject $organisationService;

	/**
	 * Wire the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->cache = $this->createMock(AggregationCache::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->placeholderResolver = new PlaceholderResolver($this->userSession);

		$this->userSession->method('getUser')->willReturn(null);
		$this->organisationService->method('getActiveOrganisation')->willReturn(null);
		$this->permissionHandler->method('hasPermission')->willReturn(true);
		$this->cache->method('getAdhoc')->willReturn(null);
		$this->cache->method('setAdhoc');
		$this->magicMapper->method('getTableNameForRegisterSchema')->willReturn('register_1_schema_zaak');
	}//end setUp()

	/**
	 * A count with no `_archived` parameter leaves archived rows out.
	 *
	 * @return void
	 */
	public function testMysqlCountExcludesArchivedRows(): void {
		$this->wirePlatform(platform: $this->createMock(MySQLPlatform::class));
		$captured = $this->captureSql();

		$this->makeRunner()->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: $this->dayBucketQuery()
		);

		$this->assertNotNull($captured['sql'], 'runAdhoc MUST prepare a SQL statement');
		$this->assertStringContainsString(
			'_archived IS NULL',
			$captured['sql'],
			'a count with no _archived parameter MUST leave archived rows out, as the list does'
		);
	}//end testMysqlCountExcludesArchivedRows()

	/**
	 * And on Postgres, where the metadata column is jsonb and the empty marker
	 * can be the jsonb null rather than a SQL NULL.
	 *
	 * @return void
	 */
	public function testPostgresCountExcludesArchivedRows(): void {
		$this->wirePlatform(platform: $this->createMock(PostgreSQLPlatform::class));
		$captured = $this->captureSql();

		$this->makeRunner()->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: $this->dayBucketQuery()
		);

		$this->assertNotNull($captured['sql'], 'runAdhoc MUST prepare a SQL statement');
		$this->assertStringContainsString(
			"_archived = 'null'::jsonb",
			$captured['sql'],
			'the Postgres arm MUST treat a jsonb null as no archive, like the soft-delete predicate beside it'
		);
	}//end testPostgresCountExcludesArchivedRows()

	/**
	 * The control: the soft-delete predicate is still there.
	 *
	 * An edit that replaced the deleted rule with the archive one rather than
	 * adding beside it would pass both tests above while quietly counting the
	 * trash.
	 *
	 * @return void
	 */
	public function testTheSoftDeletePredicateIsStillThere(): void {
		$this->wirePlatform(platform: $this->createMock(MySQLPlatform::class));
		$captured = $this->captureSql();

		$this->makeRunner()->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: $this->dayBucketQuery()
		);

		$this->assertStringContainsString('_deleted IS NULL', (string)$captured['sql']);
	}//end testTheSoftDeletePredicateIsStillThere()

	// -----------------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------------

	/**
	 * A day-bucketed count.
	 *
	 * The native path is what this test is about, and a bare count does not
	 * always reach it. A bucketed query does, on every platform.
	 *
	 * @return AggregationQuery The query.
	 */
	private function dayBucketQuery(): AggregationQuery {
		return AggregationQuery::create(
			metric: 'count',
			dateBucket: [
				'field' => 'created',
				'start' => '2026-05-01T00:00:00Z',
				'end' => '2026-05-22T00:00:00Z',
				'gap' => 'day',
			]
		);
	}//end dayBucketQuery()

	/**
	 * Capture the SQL passed to `db->prepare()`.
	 *
	 * @return \ArrayObject<string, mixed> Mutable container with an `sql` key.
	 */
	private function captureSql(): \ArrayObject {
		$captured = new \ArrayObject(['sql' => null]);
		$stmt = $this->createMock(IPreparedStatement::class);
		$result = $this->createMock(IResult::class);
		$stmt->method('execute')->willReturn($result);
		$stmt->method('fetch')->willReturn(false);

		$this->db->method('prepare')->willReturnCallback(
			function (string $sql) use ($stmt, $captured) {
				$captured['sql'] = $sql;
				return $stmt;
			}
		);

		return $captured;
	}//end captureSql()

	/**
	 * Wire the db connection mock to return the given platform instance.
	 *
	 * @param AbstractPlatform $platform Platform double.
	 *
	 * @return void
	 */
	private function wirePlatform(AbstractPlatform $platform): void {
		$this->db->method('getDatabasePlatform')->willReturn($platform);
	}//end wirePlatform()

	/**
	 * The schema under test.
	 *
	 * @return Schema The schema.
	 */
	private function makeSchema(): Schema {
		$schema = new Schema();
		$schema->setSlug('zaak');
		$schema->setId(1);

		return $schema;
	}//end makeSchema()

	/**
	 * The register under test.
	 *
	 * @return Register The register.
	 */
	private function makeRegister(): Register {
		$register = new Register();
		$register->setSlug('zaken');
		$register->setSchemas([1]);

		return $register;
	}//end makeRegister()

	/**
	 * The runner under test.
	 *
	 * @return AggregationRunner The runner.
	 */
	private function makeRunner(): AggregationRunner {
		return new AggregationRunner(
			magicMapper: $this->magicMapper,
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			placeholders: $this->placeholderResolver,
			db: $this->db,
			cache: $this->cache,
			permissionHandler: $this->permissionHandler,
			userSession: $this->userSession,
			organisationService: $this->organisationService,
			organizationHandler: $this->orgHandlerScopedTo('__no_active_org__'),
			translationHandler: $this->createMock(TranslationHandler::class),
			languageService: $this->createMock(LanguageService::class),
		);
	}//end makeRunner()

	/**
	 * A MagicOrganizationHandler that reports the caller scoped to exactly one
	 * organisation.
	 *
	 * @param string $orgUuid The organisation the caller is scoped to.
	 *
	 * @return MagicOrganizationHandler The handler.
	 */
	private function orgHandlerScopedTo(string $orgUuid): MagicOrganizationHandler {
		$handler = $this->createMock(MagicOrganizationHandler::class);
		$handler->method('resolveOrganizationScope')->willReturn(
			['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => [$orgUuid]]
		);

		return $handler;
	}//end orgHandlerScopedTo()
}//end class
