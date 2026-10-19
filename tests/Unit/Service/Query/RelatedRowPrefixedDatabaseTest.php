<?php

/**
 * A `_referenced` / `_related` filter run against a real database whose tables carry a prefix.
 *
 * The clause tests assert on SQL text, and their doubles returned an already
 * prefixed table name, so a subquery naming the bare `openregister_table_*`
 * passed every test and failed on every instance (`oc_` is the default
 * prefix). This runs the applier's SQL on SQLite with `oc_` tables, after the
 * `*PREFIX*` replacement Nextcloud's connection performs
 * (`OC\DB\Connection::replaceTablePrefix()`).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Query
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit and DBAL helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Query\RelatedRowAccess;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowExistsClause
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @uses \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowFilter
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowFilterParser
 */
class RelatedRowPrefixedDatabaseTest extends TestCase {

	/**
	 * The instance's table prefix.
	 *
	 * @var string
	 */
	private const PREFIX = 'oc_';

	/** @var Connection An in-memory SQLite database with prefixed magic tables. */
	private Connection $db;

	protected function setUp(): void {
		$this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

		// The beschikking table (outer, schema 3) and the aanvraag table (referenced, schema 2).
		$this->db->executeStatement('CREATE TABLE oc_openregister_table_1_3 (_uuid TEXT, _deleted TEXT, "subsidieaanvraag" TEXT)');
		$this->db->executeStatement('CREATE TABLE oc_openregister_table_1_2 (_uuid TEXT, _deleted TEXT, _owner TEXT, "case" TEXT)');

		$this->db->executeStatement(
			'INSERT INTO oc_openregister_table_1_2 (_uuid, _owner, "case") VALUES (\'a-1\', \'alice\', \'case-1\'), (\'a-2\', \'alice\', \'case-2\')'
		);
		$this->db->executeStatement(
			'INSERT INTO oc_openregister_table_1_3 (_uuid, "subsidieaanvraag") VALUES (\'b-1\', \'a-1\'), (\'b-2\', \'a-2\')'
		);
	}

	/**
	 * The applier's clauses for one query, with their parameters.
	 *
	 * @param array<string, mixed> $query The request query.
	 *
	 * @return array{0: array<int, string>, 1: array<string, mixed>}
	 */
	private function clausesFor(array $query): array {
		$schema = new Schema();
		$schema->setId(2);
		$schema->setProperties(['case' => ['type' => 'string']]);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findBySlug')->willReturn([$schema]);

		// The real handler answers the bare name; the prefix is added in SQL.
		$tableHandler = $this->createMock(MagicTableHandler::class);
		$tableHandler->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_2');
		$tableHandler->method('existsTableForRegisterSchema')->willReturn(true);

		$rbac = $this->createMock(MagicRbacHandler::class);
		$rbac->method('buildRbacPredicateForAlias')->willReturn("rel0._owner = 'alice'");

		$where = [];
		$parameters = [];
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('createFunction')->willReturnArgument(0);
		$qb->method('andWhere')->willReturnCallback(function (string $sql) use (&$where, $qb) {
			$where[] = $sql;
			return $qb;
		});
		$qb->method('setParameter')->willReturnCallback(function (string $name, mixed $value) use (&$parameters, $qb) {
			$parameters[$name] = $value;
			return $qb;
		});

		$register = new Register();
		$register->setId(1);

		(new RelatedRowQueryApplier(
			schemaMapper: $schemaMapper,
			tableHandler: $tableHandler,
			db: $this->createMock(IDBConnection::class),
			logger: new NullLogger(),
			access: new RelatedRowAccess(rbac: $rbac, logger: new NullLogger())
		))->apply(qb: $qb, query: $query, register: $register, organisationScopeFor: static fn (): array => ['mode' => MagicOrganizationHandler::SCOPE_ALL, 'uuids' => []]);

		return [$where, $parameters];
	}

	/**
	 * Run the outer query as Nextcloud's connection would: `*PREFIX*` replaced first.
	 *
	 * @param array<int, string>   $where      The clauses.
	 * @param array<string, mixed> $parameters The bindings.
	 *
	 * @return array<int, string> The matching outer uuids.
	 */
	private function outerUuidsMatching(array $where, array $parameters): array {
		$sql = 'SELECT t._uuid FROM *PREFIX*openregister_table_1_3 t WHERE '.implode(' AND ', $where).' ORDER BY t._uuid';

		return $this->db->fetchFirstColumn(str_replace('*PREFIX*', self::PREFIX, $sql), $parameters);
	}

	public function testAReferencedFilterRunsOnAPrefixedDatabase(): void {
		[$where, $parameters] = $this->clausesFor(
			['_referenced' => ['subsidieAanvraag' => ['subsidieaanvraag' => ['case' => 'case-1']]]]
		);

		$this->assertSame(['b-1'], $this->outerUuidsMatching($where, $parameters));
	}

	public function testItMatchesNothingWhenNoReferencedRowQualifies(): void {
		[$where, $parameters] = $this->clausesFor(
			['_referenced' => ['subsidieAanvraag' => ['subsidieaanvraag' => ['case' => 'case-9']]]]
		);

		$this->assertSame([], $this->outerUuidsMatching($where, $parameters));
	}
}//end class
