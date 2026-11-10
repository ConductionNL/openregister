<?php

/**
 * Golden for the free-text `_search` SQL on both search paths.
 *
 * Records the WHERE conditions buildWhereConditionsSql() returns (the UNION
 * path) and the predicates applyFullTextSearch() adds (the QueryBuilder path)
 * for plain and parsed terms, on PostgreSQL and MySQL, with and without fuzzy
 * matching and a column subset. Re-record with UPDATE_GOLDEN=1.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\MagicMapper\TableFilterRun;

/**
 * Free-text search SQL, recorded per term, platform, fuzzy flag and column set.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\MagicMapper\NestedPathFilter
 * @uses   \OCA\OpenRegister\Db\MagicMapper\DeletedLens
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumn
 * @uses   \OCA\OpenRegister\Db\MagicMapper\SearchLeaf
 * @uses   \OCA\OpenRegister\Db\MagicMapper\SearchLeafScope
 * @uses   \OCA\OpenRegister\Service\Object\DraftListLens
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Search\Service\Search\PropertySearchProfile
 * @uses   \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @uses   \OCA\OpenRegister\Service\Search\SearchTermParser
 * @uses   \OCA\OpenRegister\Service\Search\SearchTermNode
 * @uses   \OCA\OpenRegister\Service\Search\SearchTermSqlCompiler
 */
class MagicSearchHandlerSearchLeafGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/search-leaf-golden.json';

	private const PROPERTIES = [
		'title' => ['type' => 'string'],
		'case' => ['type' => 'string'],
		'firstName' => ['type' => 'string'],
		'code' => ['type' => 'string', 'matchType' => 'exact'],
		'prefixed' => ['type' => 'string', 'matchType' => 'prefix'],
		'naam' => ['type' => 'string', 'matchType' => 'fuzzy'],
		'secret' => ['type' => 'string', 'x-openregister-encrypted' => true],
		'born' => ['type' => 'string', 'format' => 'date'],
		'amount' => ['type' => 'number'],
		'flag' => ['type' => 'boolean', 'matchType' => 'exact'],
		'broken' => 'not-an-array',
	];

	private const TERMS = ['foo', "o'brien", 'dak AND NOT geweigerd', 'dak*', '"twee woorden"', '(a OR b) AND c', 'x_%'];

	/**
	 * Captured QueryBuilder predicates.
	 *
	 * @var array<int, string>
	 */
	private array $captured = [];

	/**
	 * Every term renders exactly the SQL recorded on both paths.
	 *
	 * @return void
	 */
	public function testSearchSqlMatchesGolden(): void {
		$actual = [];
		foreach (['pg' => true, 'mysql' => false] as $platform => $isPostgres) {
			foreach ([false, true] as $fuzzy) {
				foreach (self::TERMS as $term) {
					$key = "{$platform} fuzzy=" . var_export($fuzzy, true) . " {$term}";
					foreach ([null, ['title', 'case', 'naam', 'code']] as $columns) {
						$actual["union {$key} columns=" . json_encode($columns)] = $this->unionSql(
							isPostgres: $isPostgres,
							fuzzy: $fuzzy,
							search: $term,
							columns: $columns
						);
					}

					$actual["qb {$key}"] = $this->queryBuilderSql(isPostgres: $isPostgres, fuzzy: $fuzzy, search: $term);
				}
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testSearchSqlMatchesGolden()

	/**
	 * The UNION-path conditions for one search.
	 *
	 * @param bool       $isPostgres Whether the platform is PostgreSQL.
	 * @param bool       $fuzzy      Whether `_fuzzy=true` is asked and pg_trgm is present.
	 * @param string     $search     The search term.
	 * @param array|null $columns    The columns the table has, or null for all.
	 *
	 * @return array<int, string> The conditions.
	 */
	private function unionSql(bool $isPostgres, bool $fuzzy, string $search, ?array $columns): array {
		$query = ['_search' => '  ' . $search . ' '];
		if ($fuzzy === true) {
			$query['_fuzzy'] = 'true';
		}

		return $this->handler(isPostgres: $isPostgres, fuzzy: $fuzzy)->buildWhereConditionsSql(
			query: $query,
			schema: $this->schema(),
			existingColumns: $columns
		);
	}//end unionSql()

	/**
	 * The QueryBuilder-path predicates for one search.
	 *
	 * @param bool   $isPostgres Whether the platform is PostgreSQL.
	 * @param bool   $fuzzy      Whether fuzzy matching is enabled.
	 * @param string $search     The search term.
	 *
	 * @return array<int, string> The captured predicates.
	 */
	private function queryBuilderSql(bool $isPostgres, bool $fuzzy, string $search): array {
		$this->captured = [];
		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyFullTextSearch');
		$method->invoke($this->handler(isPostgres: $isPostgres, fuzzy: $fuzzy), new TableFilterRun(qb: $this->queryBuilder(), query: ['_search' => $search], table: new MagicReadTable(schema: $this->schema())), $fuzzy);

		return $this->captured;
	}//end queryBuilderSql()

	/**
	 * The schema under search.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setProperties(self::PROPERTIES);

		return $schema;
	}//end schema()

	/**
	 * A handler on the given platform whose pg_trgm probe answers $fuzzy.
	 *
	 * @param bool $isPostgres Whether the platform is PostgreSQL.
	 * @param bool $fuzzy      What the pg_trgm probe answers.
	 *
	 * @return MagicSearchHandler The handler.
	 */
	private function handler(bool $isPostgres, bool $fuzzy): MagicSearchHandler {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(static fn ($value): string => "'" . (string) $value . "'");

		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$queryBuilder->method('getConnection')->willReturn($connection);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($queryBuilder);
		$platform = new MySQLPlatform();
		if ($isPostgres === true) {
			$platform = new PostgreSQLPlatform();
		}

		$db->method('getDatabasePlatform')->willReturn($platform);

		$rbac = $this->createMock(MagicRbacHandler::class);
		$rbac->method('buildRbacConditionsSql')->willReturn(['bypass' => true, 'conditions' => []]);

		$organisation = $this->createMock(MagicOrganizationHandler::class);
		$organisation->method('resolveOrganizationScope')->willReturn(['mode' => MagicOrganizationHandler::SCOPE_ALL]);

		$handler = new MagicSearchHandler(
			$db,
			$this->createMock(LoggerInterface::class),
			$rbac,
			$organisation,
			new SchemaTypeConverter(),
			$this->createMock(DateTimeNormalizer::class),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class)
		);
		$probe = new \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe($this->createMock(IDBConnection::class), $this->createMock(LoggerInterface::class));
		(new ReflectionProperty($probe, 'available'))->setValue($probe, $fuzzy);
		(new ReflectionProperty(MagicSearchHandler::class, 'trgmProbe'))->setValue($handler, $probe);

		return $handler;
	}//end handler()

	/**
	 * A builder that renders every predicate it is given.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function queryBuilder(): IQueryBuilder {
		$params = 0;
		$expr = $this->createMock(IExpressionBuilder::class);
		$composite = $this->createMock(ICompositeExpression::class);
		$composite->method('add')->willReturnCallback(
			function (mixed $part) use ($composite): ICompositeExpression {
				$this->captured[] = 'or: ' . (string) $part;
				return $composite;
			}
		);
		$expr->method('orX')->willReturn($composite);
		$expr->method('like')->willReturnCallback(static fn ($column, $value): string => "like({$column},{$value})");

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createFunction')->willReturnCallback(static fn (string $sql): string => $sql);
		$qb->method('createNamedParameter')->willReturnCallback(
			static function ($value) use (&$params): string {
				$params++;
				return ":p{$params}=" . json_encode($value);
			}
		);
		$qb->method('andWhere')->willReturnCallback(
			function (...$predicates) use ($qb) {
				foreach ($predicates as $predicate) {
					if (is_string($predicate) === false) {
						$this->captured[] = 'and: composite';
						continue;
					}

					$this->captured[] = 'and: ' . $predicate;
				}

				return $qb;
			}
		);

		return $qb;
	}//end queryBuilder()
}//end class
