<?php

/**
 * WOO-548 regression: `_relations.<field>` VALUE-filtering on MariaDB/MySQL.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Locks the WOO-548 MariaDB/MySQL fallback for the three `_relations`-touching
 * methods in {@see MagicSearchHandler}. Previous shape emitted unguarded
 * PostgreSQL-only `jsonb_typeof` / `to_jsonb` / `@>`, which raised a syntax
 * error (or silently returned empty) on MariaDB/MySQL. Consumed by
 * OpenCatalogi's public search (WOO-536 Stap 5a).
 *
 * The PostgreSQL branch is covered by the sibling
 * {@see MagicSearchHandlerRelationsFilterTest}.
 */
class MagicSearchHandlerRelationsFilterMariaDbTest extends TestCase {

	private IDBConnection&MockObject $db;

	private LoggerInterface&MockObject $logger;

	private MagicRbacHandler&MockObject $rbacHandler;

	private MagicOrganizationHandler&MockObject $organizationHandler;

	private MagicSearchHandler $handler;

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->rbacHandler = $this->createMock(MagicRbacHandler::class);
		$this->organizationHandler = $this->createMock(MagicOrganizationHandler::class);
		$this->db->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());

		$this->handler = new MagicSearchHandler(
			db: $this->db,
			logger: $this->logger,
			rbacHandler: $this->rbacHandler,
			organizationHandler: $this->organizationHandler,
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer($this->logger)
		);
	}//end setUp()

	/**
	 * Build a connection mock whose quote() wraps values in single quotes.
	 */
	private function makeConnection(): object {
		$conn = $this->createMock(IDBConnection::class);
		$conn->method('quote')->willReturnCallback(fn ($v) => "'{$v}'");
		return $conn;
	}//end makeConnection()

	/**
	 * Invoke the private buildRelationFilterConditionsSql() via reflection.
	 *
	 * @param array<string,mixed> $query Search query.
	 *
	 * @return string[] Generated SQL conditions.
	 */
	private function invokeRelationFilters(array $query): array {
		$method = new ReflectionMethod(MagicSearchHandler::class, 'buildRelationFilterConditionsSql');
		$method->setAccessible(true);
		return $method->invoke($this->handler, $query, $this->makeConnection());
	}//end invokeRelationFilters()

	public function testUnionBranchEmitsPortableJsonFunctionsOnMariaDb(): void {
		$conditions = $this->invokeRelationFilters(['_relations.author' => 'person-42']);

		$this->assertCount(1, $conditions);
		$sql = $conditions[0];

		// MariaDB fallback must be free of PostgreSQL-only syntax so the query
		// stops raising SQLSTATE[42601] on non-Postgres deployments.
		$this->assertStringNotContainsString('jsonb_typeof', $sql);
		$this->assertStringNotContainsString('to_jsonb', $sql);
		$this->assertStringNotContainsString('@>', $sql);
		$this->assertStringNotContainsString('::text', $sql);

		// It must still filter on the referenced id VALUE and its named field
		// (mirroring the semantics locked by the sibling PG test).
		$this->assertStringContainsString("JSON_EXTRACT(_relations, CONCAT('\$.', 'author'))", $sql);
		$this->assertStringContainsString("'person-42'", $sql);
		$this->assertStringContainsString("JSON_SEARCH(_relations, 'one', 'person-42', NULL, CONCAT('\$.', 'author.%'))", $sql);
		// Legacy array shape still matches (JSON_CONTAINS on the whole array).
		$this->assertStringContainsString('JSON_CONTAINS(_relations, JSON_QUOTE(\'person-42\'))', $sql);
	}//end testUnionBranchEmitsPortableJsonFunctionsOnMariaDb()

	public function testMultipleRelationFiltersEachCarryTheirOwnValueOnMariaDb(): void {
		$conditions = $this->invokeRelationFilters(
			[
				'_relations.author' => 'person-1',
				'_relations.reviewer' => 'person-2',
			]
		);

		$this->assertCount(2, $conditions);
		$this->assertStringContainsString("'person-1'", $conditions[0]);
		$this->assertStringContainsString("'author'", $conditions[0]);
		$this->assertStringContainsString("'person-2'", $conditions[1]);
		$this->assertStringContainsString("'reviewer'", $conditions[1]);
	}//end testMultipleRelationFiltersEachCarryTheirOwnValueOnMariaDb()

	public function testMariaDbBranchDropsPostgresJsonbSyntaxAcrossAllConditions(): void {
		// Regression against the pre-WOO-548 bug: even multiple filters must
		// each land in the MariaDB branch, no straggler PG syntax anywhere.
		$conditions = $this->invokeRelationFilters(
			[
				'_relations.author' => 'a',
				'_relations.reviewer' => 'b',
				'_relations.editor' => 'c',
			]
		);
		foreach ($conditions as $sql) {
			$this->assertStringNotContainsString('jsonb_typeof', $sql);
			$this->assertStringNotContainsString('to_jsonb', $sql);
			$this->assertStringNotContainsString('@>', $sql);
		}
	}//end testMariaDbBranchDropsPostgresJsonbSyntaxAcrossAllConditions()

	/**
	 * `applyRelationsContainsFilter` is the direct consumer of the
	 * `_relations_contains` public query filter (WOO-536 Stap 5a). Lock its
	 * MariaDB shape so a future refactor can't silently regress the two-line
	 * fallback back into PG syntax.
	 */
	public function testRelationsContainsFilterEmitsPortableJsonSearchOnMariaDb(): void {
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$captured = '';
		$qb->method('createNamedParameter')->willReturnCallback(
			fn ($v) => "'{$v}'"
		);
		$qb->method('andWhere')->willReturnCallback(
			function (string $sql) use (&$captured) {
				$captured = $sql;
			}
		);

		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyRelationsContainsFilter');
		$method->setAccessible(true);
		$method->invoke($this->handler, $qb, 'uuid-abc');

		$this->assertStringNotContainsString('jsonb_typeof', $captured);
		$this->assertStringNotContainsString('to_jsonb', $captured);
		$this->assertStringNotContainsString('@>', $captured);
		$this->assertStringContainsString("JSON_SEARCH(t._relations, 'one', 'uuid-abc')", $captured);
		$this->assertStringContainsString('IS NOT NULL', $captured);
	}//end testRelationsContainsFilterEmitsPortableJsonSearchOnMariaDb()

}//end class
