<?php

/**
 * The report contract on the ad-hoc aggregation primitive: what a report that
 * ships with an app may rely on. A count grouped by one field, filtered in the
 * findAll() vocabulary, bounded to [from, to) on a named date field, with the
 * objects that have no value for the group field returned as ONE group keyed
 * null, answered as {groups: [{key, value}], backend, cached}. Each scenario
 * runs on the native path (real SQLite) and on the PHP fallback, so a change to
 * either path's shape goes red here.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/aggregations-a-shipped-report-may-rely-on/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Aggregation;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\AggregationAccess;
use OCA\OpenRegister\Service\Aggregation\AggregationCaller;
use OCA\OpenRegister\Service\Aggregation\AggregationPresentation;
use OCA\OpenRegister\Service\Aggregation\AggregationSources;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IUserSession;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The report contract, scenario by scenario, on both paths.
 */
class AggregationReportContractTest extends TestCase {

	/**
	 * The caller's organisation; the native path scopes to it.
	 */
	private const ORG = '__no_active_org__';

	/**
	 * Open cases: anna 3, bart 1, two without an assignee; one closed case
	 * for anna that the filter leaves out; one case of another organisation.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function dataset(): array {
		return [
			['assignee' => 'anna', 'district' => 'noord', 'startDate' => '2027-03-01', 'state' => 'open', 'org' => self::ORG],
			['assignee' => 'anna', 'district' => 'noord', 'startDate' => '2027-03-15', 'state' => 'open', 'org' => self::ORG],
			['assignee' => 'anna', 'district' => 'zuid', 'startDate' => '2027-03-31', 'state' => 'open', 'org' => self::ORG],
			['assignee' => 'bart', 'district' => null, 'startDate' => '2027-04-01', 'state' => 'open', 'org' => self::ORG],
			['assignee' => null, 'district' => null, 'startDate' => '2027-03-10', 'state' => 'open', 'org' => self::ORG],
			['assignee' => null, 'district' => 'zuid', 'startDate' => '2027-02-28', 'state' => 'open', 'org' => self::ORG],
			['assignee' => 'anna', 'district' => 'noord', 'startDate' => '2027-03-05', 'state' => 'closed', 'org' => self::ORG],
			['assignee' => 'anna', 'district' => 'noord', 'startDate' => '2027-03-05', 'state' => 'open', 'org' => 'another-organisation'],
		];
	}

	/**
	 * Scenario "open cases per handler": the cases without an assignee are one group keyed null, on the native path.
	 *
	 * @return void
	 */
	public function testOpenCasesPerHandlerKeepTheUnassignedAsOneNullGroupNatively(): void {
		$result = $this->nativeRunner()->runAdhoc(register: $this->register(), schema: $this->schema(), query: $this->perHandler());

		$this->assertSame('sqlite', $result['backend']);
		$this->assertSame(['' => 2, 'anna' => 3, 'bart' => 1], $this->fold(groups: $result['groups']));
		$this->assertNullGroupIsNull(groups: $result['groups'], expected: 2);
	}

	/**
	 * The same scenario on the PHP fallback.
	 *
	 * @return void
	 */
	public function testOpenCasesPerHandlerKeepTheUnassignedAsOneNullGroupInPhp(): void {
		$runner = $this->phpRunner(rows: $this->ownRows());
		$result = $runner->runAdhoc(register: $this->register(), schema: $this->schema(), query: $this->perHandler());

		$this->assertNotSame('sqlite', $result['backend']);
		$this->assertSame(['' => 2, 'anna' => 3, 'bart' => 1], $this->fold(groups: $result['groups']));
		$this->assertNullGroupIsNull(groups: $result['groups'], expected: 2);
	}

	/**
	 * Scenario "cases per area in a period", native: only [from, to) counts, and no district is one null group.
	 *
	 * @return void
	 */
	public function testCasesPerAreaInAPeriodCountOnlyTheHalfOpenPeriodNatively(): void {
		$result = $this->nativeRunner()->runAdhoc(register: $this->register(), schema: $this->schema(), query: $this->perAreaInMarch());

		// 1 Mar (from, included) noord, 15 Mar noord, 31 Mar zuid, 10 Mar no district.
		// 1 Apr (to, excluded) and 28 Feb lie outside; the closed case is filtered out.
		$this->assertSame(['' => 1, 'noord' => 2, 'zuid' => 1], $this->fold(groups: $result['groups']));
		$this->assertNullGroupIsNull(groups: $result['groups'], expected: 1);
	}

	/**
	 * The same scenario on the PHP fallback.
	 *
	 * @return void
	 */
	public function testCasesPerAreaInAPeriodCountOnlyTheHalfOpenPeriodInPhp(): void {
		$runner = $this->phpRunner(rows: $this->ownRows());
		$result = $runner->runAdhoc(register: $this->register(), schema: $this->schema(), query: $this->perAreaInMarch());

		$this->assertSame(['' => 1, 'noord' => 2, 'zuid' => 1], $this->fold(groups: $result['groups']));
		$this->assertNullGroupIsNull(groups: $result['groups'], expected: 1);
	}

	/**
	 * The response shape a shipped report reads: groups of exactly {key, value}, plus backend and cached.
	 *
	 * @return void
	 */
	public function testTheShapeIsGroupsOfKeyAndValueWithBackendAndCached(): void {
		foreach ([$this->nativeRunner(), $this->phpRunner(rows: $this->ownRows())] as $runner) {
			$result = $runner->runAdhoc(register: $this->register(), schema: $this->schema(), query: $this->perHandler());

			$this->assertArrayHasKey('groups', $result);
			$this->assertArrayHasKey('backend', $result);
			$this->assertArrayHasKey('cached', $result);
			foreach ($result['groups'] as $group) {
				$this->assertSame(['key', 'value'], array_keys($group));
				$this->assertIsInt($group['value']);
			}
		}
	}

	/**
	 * The caller's organisation scope applies before counting: another organisation's case is not counted.
	 *
	 * @return void
	 */
	public function testAnotherOrganisationsCaseIsNotCounted(): void {
		$result = $this->nativeRunner()->runAdhoc(register: $this->register(), schema: $this->schema(), query: $this->perHandler());

		$total = array_sum(array_column($result['groups'], 'value'));
		$this->assertSame(6, $total, 'six open cases of the caller\'s organisation, not seven');
	}

	/**
	 * A period needs a date property and a non-empty [from, to); a request without dateField is unchanged.
	 *
	 * @return void
	 */
	public function testThePeriodIsRefusedWhereItCannotMeanOneThing(): void {
		$validator = new TimeseriesRequestValidator();
		$base = ['field' => 'district', 'metric' => 'count', 'filter' => []];
		$march = ['dateField' => 'startDate', 'from' => '2027-03-01', 'to' => '2027-04-01'];

		$refused = [
			'not a date' => $base + ['dateField' => 'district', 'from' => '2027-03-01', 'to' => '2027-04-01'],
			'undeclared' => $base + ['dateField' => 'closedOn', 'from' => '2027-03-01', 'to' => '2027-04-01'],
			'no to' => $base + ['dateField' => 'startDate', 'from' => '2027-03-01'],
			'empty period' => $base + ['dateField' => 'startDate', 'from' => '2027-04-01', 'to' => '2027-04-01'],
			'with interval' => ['field' => 'startDate', 'interval' => 'DAY'] + $march,
			'matched by value' => ['field' => 'district', 'filter' => ['startDate' => '2027-03-05']] + $march,
			'bounded twice' => ['field' => 'district', 'filter' => ['startDate' => ['gte' => '2027-01-01']]] + $march,
		];
		foreach ($refused as $label => $input) {
			try {
				$validator->validate(input: $input, schema: $this->schema());
				$this->fail('accepted: ' . $label);
			} catch (InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}

		$unbounded = $validator->validate(input: $base + ['from' => '2027-03-01', 'to' => '2027-04-01'], schema: $this->schema());
		$this->assertSame([], $unbounded->filter, 'without dateField from/to change nothing, as before');

		$bounded = $validator->validate(
			input: ['field' => 'district', 'filter' => ['startDate' => ['isnull' => false]]] + $march,
			schema: $this->schema()
		);
		$this->assertSame(['startDate' => ['isnull' => false, 'gte' => '2027-03-01', 'lt' => '2027-04-01']], $bounded->filter);
	}

	/**
	 * Count of open cases grouped by assignee.
	 *
	 * @return AggregationQuery
	 */
	private function perHandler(): AggregationQuery {
		return (new TimeseriesRequestValidator())->validate(
			input: ['field' => 'assignee', 'metric' => 'count', 'filter' => ['state' => ['ne' => 'closed']]],
			schema: $this->schema()
		);
	}

	/**
	 * Count of open cases grouped by district, started in March 2027.
	 *
	 * @return AggregationQuery
	 */
	private function perAreaInMarch(): AggregationQuery {
		return (new TimeseriesRequestValidator())->validate(
			input: [
				'field' => 'district',
				'metric' => 'count',
				'filter' => ['state' => ['ne' => 'closed']],
				'dateField' => 'startDate',
				'from' => '2027-03-01',
				'to' => '2027-04-01',
			],
			schema: $this->schema()
		);
	}

	/**
	 * Groups as key => value, the null key spelled '' so the map sorts; ksorted.
	 *
	 * @param array<int, array<string, mixed>> $groups The groups.
	 *
	 * @return array<string, int>
	 */
	private function fold(array $groups): array {
		$out = [];
		foreach ($groups as $group) {
			$out[(string) $group['key']] = (int) $group['value'];
		}

		ksort($out);
		return $out;
	}

	/**
	 * Exactly one group has key null, holding the expected count; none has the empty string as key.
	 *
	 * @param array<int, array<string, mixed>> $groups   The groups.
	 * @param int                              $expected Its count.
	 *
	 * @return void
	 */
	private function assertNullGroupIsNull(array $groups, int $expected): void {
		$nulls = array_values(array_filter($groups, static fn (array $group): bool => $group['key'] === null));
		$this->assertCount(1, $nulls, 'the objects without a value are ONE group keyed null');
		$this->assertSame($expected, (int) $nulls[0]['value']);
		$this->assertCount(0, array_filter($groups, static fn (array $group): bool => $group['key'] === ''));
	}

	/**
	 * The rows of the caller's organisation; the PHP path is handed them by the mapper, which scopes.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function ownRows(): array {
		return array_values(array_filter($this->dataset(), static fn (array $row): bool => $row['org'] === self::ORG));
	}

	/**
	 * A runner on a real in-memory SQLite magic table.
	 *
	 * @return AggregationRunner
	 */
	private function nativeRunner(): AggregationRunner {
		$pdo = new PDO('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec(
			'CREATE TABLE "oc_register_1_schema_case" ("_deleted" TEXT, "_archived" TEXT, "_status" TEXT, "_organisation" TEXT,'
			. ' "assignee" TEXT, "district" TEXT, "start_date" TEXT, "state" TEXT)'
		);
		$insert = $pdo->prepare(
			'INSERT INTO "oc_register_1_schema_case" ("_deleted", "_organisation", "assignee", "district", "start_date", "state")'
			. ' VALUES (NULL, ?, ?, ?, ?, ?)'
		);
		foreach ($this->dataset() as $row) {
			$insert->execute([$row['org'], $row['assignee'], $row['district'], $row['startDate'], $row['state']]);
		}

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(SqlitePlatform::class));
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($pdo): IPreparedStatement {
				$pdoStmt = $pdo->prepare(str_replace('*PREFIX*', 'oc_', $sql));
				$stmt = $this->createMock(IPreparedStatement::class);
				$stmt->method('execute')->willReturnCallback(
					function ($bindings = []) use ($pdoStmt): IResult {
						$pdoStmt->execute(($bindings ?? []));
						return $this->createMock(IResult::class);
					}
				);
				$stmt->method('fetch')->willReturnCallback(static fn () => $pdoStmt->fetch(PDO::FETCH_ASSOC));
				return $stmt;
			}
		);

		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('getTableNameForRegisterSchema')->willReturn('register_1_schema_case');

		return $this->makeRunner(db: $db, magicMapper: $magicMapper);
	}

	/**
	 * A runner whose platform is unknown, so the PHP fallback counts the given rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows the mapper returns.
	 *
	 * @return AggregationRunner
	 */
	private function phpRunner(array $rows): AggregationRunner {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(AbstractPlatform::class));

		$entities = [];
		foreach ($rows as $row) {
			unset($row['org']);
			$entity = $this->createMock(ObjectEntity::class);
			$entity->method('getObject')->willReturn($row);
			$entities[] = $entity;
		}

		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('findAllInRegisterSchemaTable')->willReturn($entities);
		$magicMapper->method('getTableNameForRegisterSchema')->willReturn('register_1_schema_case');

		return $this->makeRunner(db: $db, magicMapper: $magicMapper);
	}

	/**
	 * The case schema.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setId(1);
		$schema->setProperties(
			[
				'assignee' => ['type' => 'string'],
				'district' => ['type' => 'string'],
				'startDate' => ['type' => 'string', 'format' => 'date'],
				'state' => ['type' => 'string'],
			]
		);
		return $schema;
	}

	/**
	 * The register.
	 *
	 * @return Register
	 */
	private function register(): Register {
		return $this->makeRegister();
	}

	/**
	 * Assemble an AggregationRunner with the given DB + MagicMapper and
	 * permissive RBAC / null-org / no-translation collaborators.
	 *
	 * @param IDBConnection $db The (real-SQLite-backed or unknown) connection.
	 * @param MagicMapper $magicMapper The magic-table mapper.
	 *
	 * @return AggregationRunner
	 */
	private function makeRunner(IDBConnection $db, MagicMapper $magicMapper): AggregationRunner {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$organisationService = $this->createMock(OrganisationService::class);
		$organisationService->method('getActiveOrganisation')->willReturn(null);

		$permissionHandler = $this->createMock(PermissionHandler::class);
		$permissionHandler->method('hasPermission')->willReturn(true);

		$cache = $this->createMock(AggregationCache::class);
		$cache->method('getAdhoc')->willReturn(null);

		$translationHandler = $this->createMock(TranslationHandler::class);
		$translationHandler->method('getTranslatableProperties')->willReturn([]);

		$languageService = $this->createMock(LanguageService::class);
		$languageService->method('shouldReturnAllTranslations')->willReturn(false);

		return new AggregationRunner(
			sources: new AggregationSources(
				catalog: new \OCA\OpenRegister\Db\SchemaCatalog(
					registers: $this->createMock(RegisterMapper::class),
					schemas: $this->createMock(SchemaMapper::class),
					tables: $magicMapper
				),
				db: $db
			),
			cache: $cache,
			access: new AggregationAccess(
				permissionHandler: $permissionHandler,
				organizationHandler: $this->orgHandlerScopedTo(self::ORG)
			),
			caller: new AggregationCaller(
				userSession: $userSession,
				organisationService: $organisationService,
				placeholders: new PlaceholderResolver($userSession)
			),
			presentation: new AggregationPresentation(
				translationHandler: $translationHandler,
				languageService: $languageService
			)
		);
	}

	/**
	 * A register that carries schema 1.
	 *
	 * @return Register
	 */
	private function makeRegister(): Register {
		$register = new Register();
		$register->setSlug('bookkeeping');
		$register->setSchemas([1]);
		return $register;
	}

	/**
	 * A MagicOrganizationHandler that reports the caller scoped to exactly one
	 * organisation. The fixtures in these tests seed rows carrying that same
	 * value in `_organisation`, so the rendered predicate matches them — which
	 * is what the old hard-coded `_organisation = :activeOrg` did implicitly.
	 *
	 * @param string $orgUuid The organisation the caller is scoped to.
	 *
	 * @return MagicOrganizationHandler
	 */
	private function orgHandlerScopedTo(string $orgUuid): MagicOrganizationHandler {
		$handler = $this->createMock(MagicOrganizationHandler::class);
		$handler->method('resolveOrganizationScope')->willReturn(
			['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => [$orgUuid]]
		);

		return $handler;
	}//end orgHandlerScopedTo()

}//end class
