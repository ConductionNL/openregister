<?php

/**
 * `_search` on PostgreSQL also matches the stemmed form; on MySQL it is unchanged.
 *
 * The two inflection cases run the generated condition against a PostgreSQL
 * database named by OPENREGISTER_TEST_PGSQL_DSN (a PDO DSN) and are skipped
 * without one; the SQL cases run everywhere.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQL120Platform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\SearchLeafScope;
use OCA\OpenRegister\Db\MagicMapper\TableFilterRun;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCA\OpenRegister\Service\Search\TextSearchConfiguration;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\SearchLeafScope
 * @uses \OCA\OpenRegister\Db\MagicMapper\SearchLeaf
 * @uses \OCA\OpenRegister\Service\Search\TextSearchConfiguration
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
 */
final class MagicSearchDutchTest extends TestCase {

	/**
	 * Predicates the QueryBuilder path added.
	 *
	 * @var list<string>
	 */
	private array $captured = [];

	private function handler(string $platformClass, ?string $stored): MagicSearchHandler {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock($platformClass));
		$textSearch = null;
		if ($stored !== null) {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueString')->willReturn($stored);
			$textSearch = new TextSearchConfiguration(db: $db, appConfig: $appConfig);
		}

		return new MagicSearchHandler(
			db: $db,
			logger: new NullLogger(),
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer(new NullLogger()),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class),
			textSearch: $textSearch
		);
	}

	private function schema(): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn([
			'toelichting' => ['type' => 'string'],
			'datum'       => ['type' => 'string', 'format' => 'date'],
		]);

		return $schema;
	}

	/**
	 * The UNION-path condition, quoted by $quote.
	 */
	private function unionSql(MagicSearchHandler $handler, string $search, bool $isPostgres, callable $quote): string {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback($quote);
		$scope = new SearchLeafScope(schema: $this->schema(), isPostgres: $isPostgres);
		$scope->connection = $connection;
		$scope->stemming = (new ReflectionMethod(MagicSearchHandler::class, 'stemmingConfiguration'))->invoke($handler, $isPostgres);

		return (string)(new ReflectionMethod(MagicSearchHandler::class, 'buildSearchConditionSql'))->invoke($handler, $search, $scope);
	}

	/**
	 * The QueryBuilder-path predicates for one term.
	 */
	private function queryBuilderSql(MagicSearchHandler $handler, string $search): string {
		$this->captured = [];
		$composite = $this->createMock(ICompositeExpression::class);
		$composite->method('add')->willReturnCallback(function (mixed $part) use ($composite): ICompositeExpression {
			$this->captured[] = (string)$part;
			return $composite;
		});
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('orX')->willReturn($composite);
		$expr->method('like')->willReturnCallback(static fn ($column, $value): string => "like({$column},{$value})");
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createFunction')->willReturnCallback(static fn (string $sql): string => $sql);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value): string => ':p' . md5((string)$value));

		(new ReflectionMethod(MagicSearchHandler::class, 'applyFullTextSearch'))->invoke(
			$handler,
			new TableFilterRun(qb: $qb, query: ['_search' => $search], table: new MagicReadTable(schema: $this->schema())),
			false
		);

		return implode(' || ', $this->captured);
	}

	/**
	 * A live PostgreSQL database holding one magic-table-shaped table, or a skip.
	 */
	private function liveDatabase(): PDO {
		$dsn = getenv('OPENREGISTER_TEST_PGSQL_DSN');
		if (is_string($dsn) === false || $dsn === '') {
			$this->markTestSkipped('OPENREGISTER_TEST_PGSQL_DSN names no PostgreSQL database.');
		}

		$pdo = new PDO($dsn);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec('CREATE TEMPORARY TABLE probe (_name varchar(255), _description text, _summary text, toelichting text, datum varchar(32))');
		$pdo->exec("INSERT INTO probe (_name, toelichting) VALUES ('Omgevingsvergunning bouwen Dorpsstraat', NULL), ('Kapmelding', 'Er zijn vergunningen verleend.'), ('Dakkapel', 'Een vergunning is nodig.'), ('Iets anders', 'Niets')");

		return $pdo;
	}

	/**
	 * The names the UNION-path condition selects on the live database.
	 *
	 * @return list<string>
	 */
	private function liveNames(string $search): array {
		$pdo = $this->liveDatabase();
		$sql = $this->unionSql(
			handler: $this->handler(platformClass: PostgreSQL120Platform::class, stored: 'dutch'),
			search: $search,
			isPostgres: true,
			quote: static fn ($value): string => $pdo->quote((string)$value)
		);

		return $pdo->query('SELECT _name FROM probe WHERE ' . $sql . ' ORDER BY _name')->fetchAll(PDO::FETCH_COLUMN);
	}

	public function testAnInflectedFormFindsItsBase(): void {
		$this->assertSame(['Omgevingsvergunning bouwen Dorpsstraat'], $this->liveNames(search: 'omgevingsvergunningen'));
	}

	public function testTheBaseFindsTheInflectedForm(): void {
		// The substring match already took `vergunning` to `vergunningen` and
		// into the compound; the stemmed arm must keep all of them.
		$this->assertSame(['Dakkapel', 'Kapmelding', 'Omgevingsvergunning bouwen Dorpsstraat'], $this->liveNames(search: 'vergunning'));
		// The reverse is what is new: `vergunningen` finds the base form in a
		// property. A compound is not split, so the Omgevingsvergunning is not found.
		$this->assertSame(['Dakkapel', 'Kapmelding'], $this->liveNames(search: 'vergunningen'));
	}

	public function testMysqlSqlIsUnchanged(): void {
		$quote = static fn ($value): string => "'" . $value . "'";
		$withSetting    = $this->handler(platformClass: MySQL80Platform::class, stored: 'dutch');
		$withoutSetting = $this->handler(platformClass: MySQL80Platform::class, stored: null);

		$this->assertSame(
			$this->unionSql(handler: $withoutSetting, search: 'vergunningen', isPostgres: false, quote: $quote),
			$this->unionSql(handler: $withSetting, search: 'vergunningen', isPostgres: false, quote: $quote)
		);
		$this->assertStringNotContainsString('to_tsvector', $this->unionSql(handler: $withSetting, search: 'vergunningen', isPostgres: false, quote: $quote));
		$this->assertSame($this->queryBuilderSql(handler: $withoutSetting, search: 'vergunningen'), $this->queryBuilderSql(handler: $withSetting, search: 'vergunningen'));
	}

	public function testPostgresOrsTheStemmedArmOnTheUnionPath(): void {
		$sql = $this->unionSql(
			handler: $this->handler(platformClass: PostgreSQL120Platform::class, stored: 'dutch'),
			search: 'vergunningen',
			isPostgres: true,
			quote: static fn ($value): string => "'" . $value . "'"
		);

		$this->assertStringContainsString(
			" OR to_tsvector('dutch', COALESCE(CAST(_name AS TEXT), '') || ' ' || COALESCE(CAST(_description AS TEXT), '') || ' ' || COALESCE(CAST(_summary AS TEXT), '')) @@ plainto_tsquery('dutch', 'vergunningen')",
			$sql
		);
		$this->assertStringContainsString("to_tsvector('dutch', COALESCE(CAST(\"toelichting\" AS TEXT), '')) @@ plainto_tsquery('dutch', 'vergunningen')", $sql);
		$this->assertStringNotContainsString('"datum" AS TEXT', $sql);
		$this->assertStringContainsString("ILIKE '%vergunningen%'", $sql);
	}

	public function testPostgresAddsTheStemmedArmOnTheQueryBuilderPath(): void {
		$sql = $this->queryBuilderSql(handler: $this->handler(platformClass: PostgreSQL120Platform::class, stored: 'dutch'), search: 'vergunningen');
		$term = ':p' . md5('vergunningen');

		$this->assertStringContainsString("to_tsvector('dutch', COALESCE(CAST(t._name AS TEXT), '') || ' ' || COALESCE(CAST(t._description AS TEXT), '') || ' ' || COALESCE(CAST(t._summary AS TEXT), '')) @@ plainto_tsquery('dutch', {$term})", $sql);
		$this->assertStringContainsString("to_tsvector('dutch', COALESCE(CAST(t.\"toelichting\" AS TEXT), '')) @@ plainto_tsquery('dutch', {$term})", $sql);
	}

	public function testAParsedTermKeepsItsCompiledMeaning(): void {
		$sql = $this->unionSql(
			handler: $this->handler(platformClass: PostgreSQL120Platform::class, stored: 'dutch'),
			search: 'vergunning AND NOT kap',
			isPostgres: true,
			quote: static fn ($value): string => "'" . $value . "'"
		);

		$this->assertStringNotContainsString('to_tsvector', $sql);
	}

	public function testTheSimpleConfigurationAddsNoArm(): void {
		$sql = $this->queryBuilderSql(handler: $this->handler(platformClass: PostgreSQL120Platform::class, stored: 'simple'), search: 'vergunningen');

		$this->assertStringNotContainsString('to_tsvector', $sql);
	}
}
