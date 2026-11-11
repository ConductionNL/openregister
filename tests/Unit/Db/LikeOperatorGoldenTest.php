<?php

/**
 * Golden test for LikeOperator: the terms it accepts and the SQL it writes.
 *
 * Pins terms() over scalar, list, keyed and mixed values, pattern() over the
 * escaped characters, and condition() / anyCondition() for every platform
 * family, so a refactor that changes any term or any byte of SQL shows here.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\LikeOperator;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OCA\OpenRegister\Db\LikeOperator
 */
class LikeOperatorGoldenTest extends TestCase {

	public function testTermsMatchGolden(): void {
		$cases = [
			'string' => ['foo', ['foo']],
			'empty string' => ['', []],
			'zero string' => ['0', ['0']],
			'int' => [12, ['12']],
			'zero int' => [0, ['0']],
			'float' => [1.5, ['1.5']],
			'true' => [true, ['1']],
			'false' => [false, []],
			'null' => [null, []],
			'object' => [new stdClass(), []],
			'list' => [['a', 'b'], ['a', 'b']],
			'keyed list' => [['x' => 'a', 'y' => '', 'z' => 'c'], ['a', 'c']],
			'mixed list' => [['a', null, ['n'], 3, false, '', ' '], ['a', '3', ' ']],
			'empty list' => [[], []],
		];

		$like = new LikeOperator(databasePlatform: new MySQLPlatform());
		foreach ($cases as $name => [$value, $expected]) {
			$this->assertSame($expected, $like->terms(value: $value), $name);
		}
	}//end testTermsMatchGolden()

	public function testPatternMatchesGolden(): void {
		$like = new LikeOperator(databasePlatform: new MySQLPlatform());

		$this->assertSame('%foo%', $like->pattern(term: 'foo'));
		$this->assertSame('%50\\% off\\_now \\\\ ok%', $like->pattern(term: '50% off_now \\ ok'));
		$this->assertSame('%%', $like->pattern(term: ''));
	}//end testPatternMatchesGolden()

	public function testSqlMatchesGoldenPerPlatform(): void {
		$expected = [
			'postgres' => [
				'CAST(t.title AS TEXT) ILIKE :p1',
				'(CAST(t.title AS TEXT) ILIKE :p1 OR CAST(t.title AS TEXT) ILIKE \'%b%\')',
			],
			'sqlite' => [
				"LOWER(CAST(t.title AS TEXT)) LIKE LOWER(:p1) ESCAPE '\\'",
				"(LOWER(CAST(t.title AS TEXT)) LIKE LOWER(:p1) ESCAPE '\\' OR LOWER(CAST(t.title AS TEXT)) LIKE LOWER('%b%') ESCAPE '\\')",
			],
			'mysql' => [
				'LOWER(CAST(t.title AS CHAR)) LIKE LOWER(:p1)',
				'(LOWER(CAST(t.title AS CHAR)) LIKE LOWER(:p1) OR LOWER(CAST(t.title AS CHAR)) LIKE LOWER(\'%b%\'))',
			],
		];

		$platforms = ['postgres' => new PostgreSQLPlatform(), 'sqlite' => new SqlitePlatform(), 'mysql' => new MySQLPlatform()];
		foreach ($platforms as $family => $platform) {
			$like = new LikeOperator(databasePlatform: $platform);
			$this->assertSame($family, $like->platform());
			$this->assertSame($expected[$family][0], $like->condition(column: 't.title', patternSql: ':p1'), $family);
			$this->assertSame($expected[$family][1], $like->anyCondition(column: 't.title', patternSqls: [':p1', "'%b%'"]), $family);
			$this->assertNull($like->anyCondition(column: 't.title', patternSqls: []), $family);
		}
	}//end testSqlMatchesGoldenPerPlatform()

	public function testAnUnknownPlatformWritesMysqlSql(): void {
		$like = new LikeOperator(databasePlatform: new stdClass());

		$this->assertSame('mysql', $like->platform());
		$this->assertSame('(LOWER(CAST(c AS CHAR)) LIKE LOWER(:p))', $like->anyCondition(column: 'c', patternSqls: [':p']));
	}//end testAnUnknownPlatformWritesMysqlSql()
}//end class
