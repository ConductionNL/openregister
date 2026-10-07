<?php

/**
 * LikeOperator tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\LikeOperator;
use PHPUnit\Framework\TestCase;

/**
 * The `like` operator: SQL per platform, escaping, and real matches on SQLite.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-like-filter-matches-a-substring-ignoring-case
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-like-matches-percent-underscore-and-backslash-literally
 */
class LikeOperatorTest extends TestCase {
	/**
	 * Rows the SQLite tests match against.
	 *
	 * @var array<int, string>
	 */
	private const NAMES = [
		'Gemeente Demo',
		'DEMOCRATIE b.v.',
		'100% zeker',
		'1000 zeker',
		'a_b',
		'axb',
		'back\\slash',
		'backslash',
	];

	/**
	 * An in-memory SQLite table holding NAMES, plus an INTEGER column.
	 *
	 * @return Connection The connection.
	 */
	private function sqlite(): Connection {
		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
		$connection->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY, name VARCHAR(64), amount INTEGER)');
		foreach (self::NAMES as $index => $name) {
			$connection->insert('t', ['id' => ($index + 1), 'name' => $name, 'amount' => (1000 + $index)]);
		}

		$connection->insert('t', ['id' => 99, 'name' => null, 'amount' => null]);

		return $connection;
	}//end sqlite()

	/**
	 * Run the operator against the SQLite table with a bound parameter.
	 *
	 * @param string $term The search term.
	 * @param string $column The column to match.
	 *
	 * @return array<int, string> The matching names, sorted.
	 */
	private function matchBound(string $term, string $column = 'name'): array {
		$connection = $this->sqlite();
		$like = new LikeOperator(databasePlatform: $connection->getDatabasePlatform());
		$this->assertSame(LikeOperator::PLATFORM_SQLITE, $like->platform());

		$rows = $connection->fetchFirstColumn(
			'SELECT name FROM t WHERE ' . $like->condition(column: $column, patternSql: '?') . ' ORDER BY name',
			[$like->pattern(term: $term)]
		);

		return array_map('strval', $rows);
	}//end matchBound()

	/**
	 * A term matches anywhere in the value, ignoring case.
	 *
	 * @return void
	 */
	public function testMatchesSubstringIgnoringCase(): void {
		$this->assertSame(['DEMOCRATIE b.v.', 'Gemeente Demo'], $this->matchBound(term: 'demo'));
		$this->assertSame(['DEMOCRATIE b.v.', 'Gemeente Demo'], $this->matchBound(term: 'DEMO'));
	}//end testMatchesSubstringIgnoringCase()

	/**
	 * `%` in the term matches a percent sign, not "anything".
	 *
	 * @return void
	 */
	public function testPercentMatchesLiterally(): void {
		$this->assertSame(['100% zeker'], $this->matchBound(term: '0%'));
	}//end testPercentMatchesLiterally()

	/**
	 * `_` in the term matches an underscore, not "any one character".
	 *
	 * @return void
	 */
	public function testUnderscoreMatchesLiterally(): void {
		$this->assertSame(['a_b'], $this->matchBound(term: 'a_b'));
	}//end testUnderscoreMatchesLiterally()

	/**
	 * `\` in the term matches a backslash and escapes nothing.
	 *
	 * @return void
	 */
	public function testBackslashMatchesLiterally(): void {
		$this->assertSame(['back\\slash'], $this->matchBound(term: 'k\\s'));
		// A trailing backslash would escape the closing `%` if it were not itself escaped.
		$this->assertSame(['back\\slash'], $this->matchBound(term: 'back\\'));
	}//end testBackslashMatchesLiterally()

	/**
	 * A quote in the term is bound, not interpolated.
	 *
	 * @return void
	 */
	public function testQuoteIsBoundNotInterpolated(): void {
		$this->assertSame([], $this->matchBound(term: "' OR 1=1 --"));
	}//end testQuoteIsBoundNotInterpolated()

	/**
	 * The column is cast to text, so an INTEGER column can be matched.
	 *
	 * @return void
	 */
	public function testIntegerColumnIsMatchedAsText(): void {
		// Amounts run 1000 to 1007; only 'axb' holds 1005.
		$this->assertSame(['axb'], $this->matchBound(term: '005', column: 'amount'));
	}//end testIntegerColumnIsMatchedAsText()

	/**
	 * The quoted-literal form (the raw UNION path) matches the same rows.
	 *
	 * @return void
	 */
	public function testQuotedLiteralMatchesLikeBoundParameter(): void {
		$connection = $this->sqlite();
		$like = new LikeOperator(databasePlatform: $connection->getDatabasePlatform());

		foreach (['demo', '0%', 'a_b', 'k\\s', "it's"] as $term) {
			$sql = 'SELECT name FROM t WHERE '
				. $like->condition(column: 'name', patternSql: $connection->quote($like->pattern(term: $term)))
				. ' ORDER BY name';
			$this->assertSame($this->matchBound(term: $term), array_map('strval', $connection->fetchFirstColumn($sql)), $term);
		}
	}//end testQuotedLiteralMatchesLikeBoundParameter()

	/**
	 * Several terms match any of them.
	 *
	 * @return void
	 */
	public function testAnyConditionMatchesAnyTerm(): void {
		$connection = $this->sqlite();
		$like = new LikeOperator(databasePlatform: $connection->getDatabasePlatform());
		$terms = $like->terms(value: ['a_b', 'zeker']);

		$sql = 'SELECT name FROM t WHERE ' . $like->anyCondition(column: 'name', patternSqls: ['?', '?']) . ' ORDER BY name';
		$rows = $connection->fetchFirstColumn($sql, array_map(fn (string $t): string => $like->pattern(term: $t), $terms));

		$this->assertSame(['100% zeker', '1000 zeker', 'a_b'], $rows);
	}//end testAnyConditionMatchesAnyTerm()

	/**
	 * The pattern escapes the three metacharacters with a backslash.
	 *
	 * @return void
	 */
	public function testPatternEscapesMetacharacters(): void {
		$like = new LikeOperator(databasePlatform: new PostgreSQLPlatform());

		$this->assertSame('%demo%', $like->pattern(term: 'demo'));
		$this->assertSame('%100\\%%', $like->pattern(term: '100%'));
		$this->assertSame('%a\\_b%', $like->pattern(term: 'a_b'));
		$this->assertSame('%c:\\\\temp%', $like->pattern(term: 'c:\\temp'));
	}//end testPatternEscapesMetacharacters()

	/**
	 * Empty and non-scalar terms add nothing; a cleared filter means no condition.
	 *
	 * @return void
	 */
	public function testTermsDropEmptyValues(): void {
		$like = new LikeOperator(databasePlatform: new PostgreSQLPlatform());

		$this->assertSame([], $like->terms(value: ''));
		$this->assertSame([], $like->terms(value: null));
		$this->assertSame(['a', '7'], $like->terms(value: ['a', '', ['nested'], 7]));
		$this->assertNull($like->anyCondition(column: 't.name', patternSqls: []));
	}//end testTermsDropEmptyValues()

	/**
	 * PostgreSQL uses ILIKE on the text cast and relies on the default backslash escape.
	 *
	 * @return void
	 */
	public function testPostgresSql(): void {
		$like = new LikeOperator(databasePlatform: new PostgreSQLPlatform());

		$this->assertSame(LikeOperator::PLATFORM_POSTGRES, $like->platform());
		$this->assertSame('CAST(t.name AS TEXT) ILIKE :p1', $like->condition(column: 't.name', patternSql: ':p1'));
	}//end testPostgresSql()

	/**
	 * MySQL and MariaDB lower both sides and cast to CHAR.
	 *
	 * @return void
	 */
	public function testMysqlAndMariaDbSql(): void {
		foreach ([new MySQL80Platform(), new MariaDBPlatform()] as $platform) {
			$like = new LikeOperator(databasePlatform: $platform);

			$this->assertSame(LikeOperator::PLATFORM_MYSQL, $like->platform());
			$this->assertSame('LOWER(CAST(t.name AS CHAR)) LIKE LOWER(:p1)', $like->condition(column: 't.name', patternSql: ':p1'));
		}
	}//end testMysqlAndMariaDbSql()

	/**
	 * SQLite names its escape character, since it has no default.
	 *
	 * @return void
	 */
	public function testSqliteSql(): void {
		$like = new LikeOperator(databasePlatform: new SqlitePlatform());

		$this->assertSame("LOWER(CAST(name AS TEXT)) LIKE LOWER(?) ESCAPE '\\'", $like->condition(column: 'name', patternSql: '?'));
	}//end testSqliteSql()
}//end class
