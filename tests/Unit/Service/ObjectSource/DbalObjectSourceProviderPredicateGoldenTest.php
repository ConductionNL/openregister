<?php

/**
 * The DBAL object source builds its read and write predicates exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Throwable;

/**
 * Golden record of the SQL and bound parameters the provider adds for equality, like and
 * `_search` filters, sorting, and the single and composite id predicates on the read and the
 * write path, including a composite id of the wrong shape. Set OR_GOLDEN_RECORD=1 to rewrite
 * the fixture.
 *
 * @covers \OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider
 * @uses   \OCA\OpenRegister\Db\LikeOperator
 * @uses   \OCA\OpenRegister\Service\ObjectSource\DbalStatement
 * @uses   \OCA\OpenRegister\Service\ObjectSource\DbalWriteException
 */
class DbalObjectSourceProviderPredicateGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/dbal-object-source-predicate-golden.json';

	private const COLUMNS = ['id', 'name', 'status', 'secret', '7'];

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
		$actual = [];
		foreach ($this->filterScenarios() as $name => [$query, $config]) {
			$actual['filters: ' . $name] = $this->built($connection, fn (QueryBuilder $qb) => $this->filters($qb, $connection, $query, $config));
		}

		foreach ($this->sortScenarios() as $name => $query) {
			$actual['sort: ' . $name] = $this->built($connection, fn (QueryBuilder $qb) => $this->sort(qb: $qb, connection: $connection, query: $query));
		}

		foreach ($this->idScenarios() as $name => [$columns, $id]) {
			foreach (['read' => 'applyIdPredicate', 'write' => 'applyWritePredicate'] as $path => $old) {
				$actual[$path . ' id: ' . $name] = $this->built($connection, fn (QueryBuilder $qb) => $this->idPredicate($qb, $connection, $old, $columns, $id));
			}
		}

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: json_decode((string) json_encode($actual), true));
	}//end testScenariosMatchTheGolden()

	/**
	 * Queries and config blocks for the filter path.
	 *
	 * @return array<string, array{0: array, 1: array}>
	 */
	private function filterScenarios(): array {
		$config = ['table' => 'people'];
		return [
			'none'                 => [[], $config],
			'equality'             => [['filters' => ['status' => 'active', 'name' => 'Ann']], $config],
			'not allowlisted'      => [['filters' => ['nope' => 1, 'status' => 'x']], $config],
			'numeric column name'  => [['filters' => [7 => 'x', '7' => 'y']], $config],
			'filters not a list'   => [['filters' => 'status'], $config],
			'array and object'     => [['filters' => ['status' => ['a', 'b'], 'name' => (object) ['a' => 1]]], $config],
			'like one term'        => [['filters' => ['name' => ['like' => 'an']]], $config],
			'like several terms'   => [['filters' => ['name' => ['like' => ['an', 'bo']], 'status' => 'x']], $config],
			'like empty'           => [['filters' => ['name' => ['like' => '']]], $config],
			'like plus other key'  => [['filters' => ['name' => ['like' => 'a', 'eq' => 'b']]], $config],
			'search'               => [['_search' => 'ann'], $config],
			'search alias'         => [['search' => 'bob'], $config],
			'search both'          => [['_search' => 'a', 'search' => 'b', 'filters' => ['status' => 's']], $config],
			'search empty'         => [['_search' => ''], $config],
			'filterable allowlist' => [['_search' => 'a', 'filters' => ['status' => 'x', 'name' => 'y']], $config + ['filterableColumns' => ['name']]],
			'filterable empty'     => [['_search' => 'a'], $config + ['filterableColumns' => []]],
		];
	}//end filterScenarios()

	/**
	 * Queries for the sort path.
	 *
	 * @return array<string, array>
	 */
	private function sortScenarios(): array {
		return [
			'none'           => [],
			'empty'          => ['sort' => []],
			'not a list'     => ['sort' => 'name'],
			'asc and desc'   => ['sort' => ['name' => 'asc', 'status' => 'DESC', 'id' => 'other']],
			'order alias'    => ['_order' => ['id' => 'desc']],
			'sort wins'      => ['sort' => ['name' => 'desc'], '_order' => ['id' => 'desc']],
			'not allowlisted' => ['sort' => ['nope' => 'desc', 0 => 'asc', 7 => 'desc']],
		];
	}//end sortScenarios()

	/**
	 * Id columns and ids.
	 *
	 * @return array<string, array{0: array, 1: string}>
	 */
	private function idScenarios(): array {
		return [
			'single'               => [['id'], '42'],
			'single with separator' => [['id'], '1::2'],
			'composite'            => [['tenant_id', 'code'], '1::A'],
			'composite short'      => [['tenant_id', 'code'], '1'],
			'composite long'       => [['tenant_id', 'code'], '1::A::x'],
			'composite three'      => [['a', 'b', 'c'], 'x::y::z'],
			'duplicate column'     => [['a', 'a'], 'x::y'],
		];
	}//end idScenarios()

	/**
	 * Apply the filters through whichever signature the provider has.
	 *
	 * @param QueryBuilder $qb         The query builder.
	 * @param Connection   $connection The connection.
	 * @param array        $query      The query.
	 * @param array        $config     The config block.
	 *
	 * @return void
	 */
	private function filters(QueryBuilder $qb, Connection $connection, array $query, array $config): void {
		$filterable = (new ReflectionMethod(DbalObjectSourceProvider::class, 'filterableColumns'))->invoke($this->provider(), self::COLUMNS, $config);
		if (method_exists(DbalObjectSourceProvider::class, 'applyFilters') === false) {
			$this->clauses(qb: $qb, connection: $connection, filterable: $filterable)->filter(query: $query);
			return;
		}

		$method = new ReflectionMethod(DbalObjectSourceProvider::class, 'applyFilters');
		if ($method->getNumberOfParameters() === 5) {
			$method->invoke($this->provider(), $qb, $connection, $query, self::COLUMNS, $config);
			return;
		}

		$this->call('applyFilters', $qb, $connection, [$query, $filterable]);
	}//end filters()

	/**
	 * Apply the sort through the provider's old method or the list clauses.
	 *
	 * @param QueryBuilder $qb         The query builder.
	 * @param Connection   $connection The connection.
	 * @param array        $query      The query.
	 *
	 * @return void
	 */
	private function sort(QueryBuilder $qb, Connection $connection, array $query): void {
		if (method_exists(DbalObjectSourceProvider::class, 'applySort') === false) {
			$this->clauses(qb: $qb, connection: $connection, filterable: [])->sort(query: $query, columns: self::COLUMNS);
			return;
		}

		$this->call('applySort', $qb, $connection, [$query, self::COLUMNS]);
	}//end sort()

	/**
	 * The list clauses the provider builds for one statement.
	 *
	 * @param QueryBuilder $qb         The query builder.
	 * @param Connection   $connection The connection.
	 * @param array        $filterable The filterable columns.
	 *
	 * @return object The clauses (DbalListClauses).
	 */
	private function clauses(QueryBuilder $qb, Connection $connection, array $filterable): object {
		$statementClass = 'OCA\\OpenRegister\\Service\\ObjectSource\\DbalStatement';
		$clausesClass = 'OCA\\OpenRegister\\Service\\ObjectSource\\DbalListClauses';
		return new $clausesClass(statement: new $statementClass(qb: $qb, connection: $connection), filterable: $filterable);
	}//end clauses()

	/**
	 * Apply an id predicate through the old method or the statement's equality pairs.
	 *
	 * @param QueryBuilder $qb         The query builder.
	 * @param Connection   $connection The connection.
	 * @param string       $old        The old method name.
	 * @param array        $columns    The id columns.
	 * @param string       $id         The id.
	 *
	 * @return void
	 */
	private function idPredicate(QueryBuilder $qb, Connection $connection, string $old, array $columns, string $id): void {
		if (method_exists(DbalObjectSourceProvider::class, $old) === true) {
			(new ReflectionMethod(DbalObjectSourceProvider::class, $old))->invoke($this->provider(), $qb, $connection, $columns, $id);
			return;
		}

		$pairs = ($old === 'applyIdPredicate') ? 'idPairs' : 'writeIdPairs';
		$statementClass = 'OCA\\OpenRegister\\Service\\ObjectSource\\DbalStatement';
		(new $statementClass($qb, $connection))->andEquals((new ReflectionMethod(DbalObjectSourceProvider::class, $pairs))->invoke(null, $columns, $id));
	}//end idPredicate()

	/**
	 * Call a provider method with (qb, connection, ...rest) or (statement, ...rest).
	 *
	 * @param string       $name       The method.
	 * @param QueryBuilder $qb         The query builder.
	 * @param Connection   $connection The connection.
	 * @param array        $rest       The other arguments.
	 *
	 * @return void
	 */
	private function call(string $name, QueryBuilder $qb, Connection $connection, array $rest): void {
		$method = new ReflectionMethod(DbalObjectSourceProvider::class, $name);
		$first = $method->getParameters()[0]->getType()?->getName();
		$head = [$qb, $connection];
		if ($first !== QueryBuilder::class) {
			$head = [new $first($qb, $connection)];
		}

		$method->invokeArgs($this->provider(), array_merge($head, $rest));
	}//end call()

	/**
	 * The SQL and parameters a fresh select picks up, or what the build throws.
	 *
	 * @param Connection $connection The connection.
	 * @param callable   $build      Adds to the query builder.
	 *
	 * @return array The recorded outcome.
	 */
	private function built(Connection $connection, callable $build): array {
		$qb = $connection->createQueryBuilder()->select('*')->from('t');
		try {
			$build($qb);
		} catch (Throwable $e) {
			return ['threw' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()];
		}

		return ['sql' => $qb->getSQL(), 'params' => $qb->getParameters()];
	}//end built()

	/**
	 * A provider whose collaborators are never reached by the predicate builders.
	 *
	 * @return DbalObjectSourceProvider
	 */
	private function provider(): DbalObjectSourceProvider {
		return new DbalObjectSourceProvider(
			sourceMapper: $this->createMock(SourceMapper::class),
			connectionFactory: $this->createMock(DbalConnectionFactory::class),
			logger: new NullLogger()
		);
	}//end provider()
}//end class
