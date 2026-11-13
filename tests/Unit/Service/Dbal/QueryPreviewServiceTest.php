<?php

/**
 * Tests for QueryPreviewService (dbal-query-schema-guarded-and-previewed, design D-3).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Dbal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dbal-query-schema-guarded-and-previewed/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\Dbal\DbalConnectionException;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactoryInterface;
use OCA\OpenRegister\Service\Dbal\QueryPreviewException;
use OCA\OpenRegister\Service\Dbal\QueryPreviewService;
use OCA\OpenRegister\Service\Dbal\ReadOnlyQueryGuard;
use OCA\OpenRegister\Service\Dbal\ReadOnlyReadScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A preview of joins and filters over an in-memory SQLite database.
 */
#[CoversClass(QueryPreviewService::class)]
#[CoversClass(QueryPreviewException::class)]
#[UsesClass(ReadOnlyQueryGuard::class)]
#[UsesClass(ReadOnlyReadScope::class)]
class QueryPreviewServiceTest extends TestCase {

	/**
	 * The database.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * Create two joined tables: 60 people, each in a city.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
		$this->connection->executeStatement('CREATE TABLE city (id INTEGER PRIMARY KEY, name VARCHAR(32))');
		$this->connection->executeStatement('CREATE TABLE person (id INTEGER PRIMARY KEY, name VARCHAR(32), city_id INTEGER, score REAL, note VARCHAR(8))');
		$this->connection->executeStatement("INSERT INTO city (id, name) VALUES (1, 'Utrecht'), (2, 'Zwolle')");
		for ($i = 1; $i <= 60; $i++) {
			$this->connection->executeStatement(
				'INSERT INTO person (id, name, city_id, score, note) VALUES (?, ?, ?, ?, NULL)',
				[$i, 'p' . $i, (1 + ($i % 2)), ($i + 0.5)]
			);
		}
	}//end setUp()

	/**
	 * The service over the database.
	 *
	 * @return QueryPreviewService
	 */
	private function service(): QueryPreviewService {
		$factory = $this->createMock(DbalConnectionFactoryInterface::class);
		$factory->method('getConnection')->willReturn($this->connection);

		return new QueryPreviewService(connectionFactory: $factory);
	}//end service()

	/**
	 * A join previews at most 50 rows with its columns and their types.
	 *
	 * @return void
	 */
	public function testAJoinPreviewsFiftyRowsWithTypedColumns(): void {
		$preview = $this->service()->preview(
			source: new Source(),
			query: 'SELECT p.id, p.name, c.name AS city, p.score, p.note FROM person p JOIN city c ON c.id = p.city_id;'
		);

		$this->assertCount(50, $preview['rows']);
		$this->assertSame(['id' => 1, 'name' => 'p1', 'city' => 'Zwolle', 'score' => 1.5, 'note' => null], $preview['rows'][0]);
		$this->assertSame(
			[
				['name' => 'id', 'type' => 'integer'],
				['name' => 'name', 'type' => 'string'],
				['name' => 'city', 'type' => 'string'],
				['name' => 'score', 'type' => 'number'],
				['name' => 'note', 'type' => 'null'],
			],
			$preview['columns']
		);
	}//end testAJoinPreviewsFiftyRowsWithTypedColumns()

	/**
	 * A query without rows answers no columns and no rows.
	 *
	 * @return void
	 */
	public function testAnEmptyResult(): void {
		$this->assertSame(['columns' => [], 'rows' => []], $this->service()->preview(source: new Source(), query: 'SELECT * FROM person WHERE id < 0'));
	}//end testAnEmptyResult()

	/**
	 * A data-changing statement is refused by the guard before the database is asked.
	 *
	 * @return void
	 */
	public function testAWriteIsRefusedByTheGuard(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A query-backed schema must begin with SELECT or WITH.');

		try {
			$this->service()->preview(source: new Source(), query: 'DELETE FROM person');
		} finally {
			$this->assertSame(60, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM person'));
		}
	}//end testAWriteIsRefusedByTheGuard()

	/**
	 * An empty query is refused.
	 *
	 * @return void
	 */
	public function testAnEmptyQueryIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A query is required.');

		$this->service()->preview(source: new Source(), query: "  \n");
	}//end testAnEmptyQueryIsRefused()

	/**
	 * A database error answers its class, never its message.
	 *
	 * @return void
	 */
	public function testADatabaseErrorKeepsItsMessageOut(): void {
		try {
			$this->service()->preview(source: new Source(), query: 'SELECT secret_column FROM payroll');
			$this->fail('The preview answered a query over a table that does not exist.');
		} catch (QueryPreviewException $exception) {
			$this->assertSame('TableNotFoundException', $exception->errorClass);
			$this->assertSame('The database refused the query (TableNotFoundException).', $exception->getMessage());
			$this->assertStringNotContainsString('payroll', $exception->getMessage());
			$this->assertNotNull($exception->getPrevious());
		}
	}//end testADatabaseErrorKeepsItsMessageOut()

	/**
	 * A source that cannot be connected to passes the connection error on.
	 *
	 * @return void
	 */
	public function testAnUnreachableSourcePassesTheConnectionErrorOn(): void {
		$factory = $this->createMock(DbalConnectionFactoryInterface::class);
		$factory->method('getConnection')->willThrowException(new DbalConnectionException('unreachable'));

		$this->expectException(DbalConnectionException::class);
		(new QueryPreviewService(connectionFactory: $factory))->preview(source: new Source(), query: 'SELECT 1');
	}//end testAnUnreachableSourcePassesTheConnectionErrorOn()

	/**
	 * Mixed value types merge to one column type.
	 *
	 * @return void
	 */
	public function testColumnTypesMerge(): void {
		$this->assertSame(
			[
				['name' => 'a', 'type' => 'number'],
				['name' => 'b', 'type' => 'string'],
				['name' => 'c', 'type' => 'boolean'],
			],
			QueryPreviewService::columnsOf(rows: [['a' => 1, 'b' => 1, 'c' => true], ['a' => 1.5, 'b' => 'x', 'c' => null]])
		);
	}//end testColumnTypesMerge()
}//end class
