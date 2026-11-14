<?php

/**
 * Accent-insensitive free-text search SQL on both search paths.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicUnaccentProbe;
use OCA\OpenRegister\Db\MagicMapper\SearchFolding;
use OCA\OpenRegister\Db\MagicMapper\TableFilterRun;
use OCA\OpenRegister\Db\MagicMapper\WhereClauseRequest;
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

/**
 * Where folding is available a search compares folded text on the UNION path,
 * the QueryBuilder path and the fuzzy arm; `_accents=exact` and an unavailable
 * fold keep the historical SQL.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @covers \OCA\OpenRegister\Db\MagicMapper\SearchLeaf
 * @covers \OCA\OpenRegister\Db\MagicMapper\SearchLeafScope
 * @uses   \OCA\OpenRegister\Db\MagicMapper\SearchFolding
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUnaccentProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 */
class MagicSearchHandlerAccentFoldingTest extends TestCase {

	/**
	 * The schema's properties: one searchable text property.
	 */
	private const PROPERTIES = ['title' => ['type' => 'string']];

	/**
	 * Captured QueryBuilder predicates.
	 *
	 * @var array<int, string>
	 */
	private array $captured = [];

	/**
	 * PostgreSQL with the wrapper: every column and the pattern are folded, compared with LIKE.
	 *
	 * @return void
	 */
	public function testPostgresUnionPathFoldsEveryColumn(): void {
		$sql = implode(' ', $this->unionSql(isPostgres: true, available: true, query: ['_search' => 'cafe']));

		$this->assertStringContainsString("openregister_fold(_name::text) LIKE openregister_fold('%cafe%')", $sql);
		$this->assertStringContainsString("openregister_fold(_summary::text) LIKE openregister_fold('%cafe%')", $sql);
		$this->assertMatchesRegularExpression('/openregister_fold\(.?title.?::text\) LIKE openregister_fold/', $sql);
		$this->assertStringNotContainsString('ILIKE', $sql);
	}//end testPostgresUnionPathFoldsEveryColumn()

	/**
	 * MariaDB and MySQL fold through the accent-insensitive collation.
	 *
	 * @return void
	 */
	public function testMysqlUnionPathUsesTheCollation(): void {
		$sql = implode(' ', $this->unionSql(isPostgres: false, available: true, query: ['_search' => 'cafe']));

		$this->assertStringContainsString(
			"CAST(_name AS CHAR) COLLATE utf8mb4_unicode_ci LIKE '%cafe%' COLLATE utf8mb4_unicode_ci",
			$sql
		);
		$this->assertStringNotContainsString('LOWER(', $sql);
	}//end testMysqlUnionPathUsesTheCollation()

	/**
	 * `_accents=exact` gives exactly the SQL of a search where folding is unavailable.
	 *
	 * @return void
	 */
	public function testExactAccentsKeepsTheHistoricalSql(): void {
		foreach ([true, false] as $isPostgres) {
			$exact = $this->unionSql(isPostgres: $isPostgres, available: true, query: ['_search' => 'cafe', '_accents' => 'exact']);
			$unavailable = $this->unionSql(isPostgres: $isPostgres, available: false, query: ['_search' => 'cafe']);

			$this->assertSame($unavailable, $exact);
			$this->assertStringNotContainsString('openregister_fold', implode(' ', $exact));
			$this->assertStringNotContainsString('COLLATE', implode(' ', $exact));
		}

		$this->assertStringContainsString(
			'openregister_fold',
			implode(' ', $this->unionSql(isPostgres: true, available: true, query: ['_search' => 'cafe', '_accents' => 'loose']))
		);
	}//end testExactAccentsKeepsTheHistoricalSql()

	/**
	 * The QueryBuilder substring path and the fuzzy name arm are folded.
	 *
	 * @return void
	 */
	public function testQueryBuilderSubstringAndFuzzyAreFolded(): void {
		$predicates = $this->queryBuilderSql(isPostgres: true, available: true, query: ['_search' => 'Café'], fuzzy: true);
		$pattern    = ':p1=' . json_encode('%' . strtolower('Café') . '%');
		$literal    = ':p2=' . json_encode('Café');

		$this->assertSame(
			[
				'or: openregister_fold(t."title"::text) LIKE openregister_fold(' . $pattern . ')',
				'or: openregister_fold(t._name::text) LIKE openregister_fold(' . $pattern . ')',
				'or: openregister_fold(t._description::text) LIKE openregister_fold(' . $pattern . ')',
				'or: openregister_fold(t._summary::text) LIKE openregister_fold(' . $pattern . ')',
				'or: similarity(openregister_fold(t._name::text), openregister_fold(' . $literal . ')) > 0.1',
				'and: composite',
			],
			$predicates
		);
	}//end testQueryBuilderSubstringAndFuzzyAreFolded()

	/**
	 * A parsed term folds its null-safe leaves, so NOT still negates a record without text.
	 *
	 * @return void
	 */
	public function testQueryBuilderParsedTermFoldsNullSafeLeaves(): void {
		$sql = implode(' ', $this->queryBuilderSql(isPostgres: true, available: true, query: ['_search' => 'cafe OR reunie'], fuzzy: false));

		$this->assertStringContainsString("openregister_fold(COALESCE(t._name::text, '')) LIKE openregister_fold(", $sql);
		$this->assertStringNotContainsString("LOWER(COALESCE(t._name, ''))", $sql);
	}//end testQueryBuilderParsedTermFoldsNullSafeLeaves()

	/**
	 * Without folding the QueryBuilder path keeps its lowered LIKE.
	 *
	 * @return void
	 */
	public function testQueryBuilderWithoutFoldingIsUnchanged(): void {
		$predicates = $this->queryBuilderSql(isPostgres: true, available: false, query: ['_search' => 'cafe'], fuzzy: false);

		$this->assertContains('or: like(LOWER(t._name),:p1="%cafe%")', $predicates);
	}//end testQueryBuilderWithoutFoldingIsUnchanged()

	/**
	 * searchFolding(): folded where available, none for `_accents=exact` or where unavailable.
	 *
	 * @return void
	 */
	public function testSearchFoldingAnswers(): void {
		$folding = $this->handler(isPostgres: true, available: true)->searchFolding(query: []);
		$this->assertInstanceOf(SearchFolding::class, $folding);
		$this->assertTrue($folding->isFolded());

		$this->assertNull($this->handler(isPostgres: true, available: true)->searchFolding(query: ['_accents' => 'exact']));
		$this->assertNull($this->handler(isPostgres: true, available: false)->searchFolding(query: []));
	}//end testSearchFoldingAnswers()

	/**
	 * The UNION-path conditions for one search.
	 *
	 * @param bool                 $isPostgres Whether the platform is PostgreSQL.
	 * @param bool                 $available  Whether accent folding is available.
	 * @param array<string, mixed> $query      The search query.
	 *
	 * @return array<int, string> The conditions.
	 */
	private function unionSql(bool $isPostgres, bool $available, array $query): array {
		return $this->handler(isPostgres: $isPostgres, available: $available)->buildWhereClause(
			request: new WhereClauseRequest(query: $query, table: new MagicReadTable(schema: $this->schema()), existingColumns: null)
		);
	}//end unionSql()

	/**
	 * The QueryBuilder-path predicates for one search.
	 *
	 * @param bool                 $isPostgres Whether the platform is PostgreSQL.
	 * @param bool                 $available  Whether accent folding is available.
	 * @param array<string, mixed> $query      The search query.
	 * @param bool                 $fuzzy      Whether fuzzy matching is enabled.
	 *
	 * @return array<int, string> The captured predicates.
	 */
	private function queryBuilderSql(bool $isPostgres, bool $available, array $query, bool $fuzzy): array {
		$this->captured = [];
		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyFullTextSearch');
		$method->invoke(
			$this->handler(isPostgres: $isPostgres, available: $available, fuzzy: $fuzzy),
			new TableFilterRun(qb: $this->queryBuilder(), query: $query, table: new MagicReadTable(schema: $this->schema())),
			$fuzzy
		);

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
	 * A handler on the given platform whose unaccent probe answers $available.
	 *
	 * @param bool $isPostgres Whether the platform is PostgreSQL.
	 * @param bool $available  Whether accent folding is available.
	 * @param bool $fuzzy      What the pg_trgm probe answers.
	 *
	 * @return MagicSearchHandler The handler.
	 */
	private function handler(bool $isPostgres, bool $available, bool $fuzzy = false): MagicSearchHandler {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(static fn ($value): string => "'" . (string) $value . "'");

		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$queryBuilder->method('getConnection')->willReturn($connection);

		$platform = new MySQLPlatform();
		if ($isPostgres === true) {
			$platform = new PostgreSQLPlatform();
		}

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($queryBuilder);
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

		$trgm = new MagicPgTrgmProbe($this->createMock(IDBConnection::class), $this->createMock(LoggerInterface::class));
		(new ReflectionProperty($trgm, 'available'))->setValue($trgm, $fuzzy);
		(new ReflectionProperty(MagicSearchHandler::class, 'trgmProbe'))->setValue($handler, $trgm);

		$unaccent = new MagicUnaccentProbe($db, $this->createMock(LoggerInterface::class));
		$reason = MagicUnaccentProbe::REASON_UNSUPPORTED;
		if ($available === true) {
			$reason = '';
		}

		(new ReflectionProperty($unaccent, 'reason'))->setValue($unaccent, $reason);
		(new ReflectionProperty(MagicSearchHandler::class, 'unaccentProbe'))->setValue($handler, $unaccent);

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
					$this->captured[] = 'and: ' . (is_string($predicate) === true ? $predicate : 'composite');
				}

				return $qb;
			}
		);

		return $qb;
	}//end queryBuilder()
}//end class
